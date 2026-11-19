<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Pixelforge;

use App\Support\Pixelforge\Enums\WebhookSignatureFailure;
use App\Support\Pixelforge\Exceptions\InvalidWebhookSignatureException;
use App\Support\Pixelforge\Exceptions\WebhookSecretNotConfiguredException;
use App\Support\Pixelforge\WebhookSignature;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureTest extends TestCase
{
    private const string SECRET = 'whsec_test_0123456789abcdef';

    private const string PAYLOAD = '{"id":"evt_01JC2Z9A7M4K2P8Q6R3T5V1W9X","object":"event","type":"ping","created_at":"2026-11-19T00:00:00Z","data":{}}';

    /**
     * 2026-11-19T00:00:00Z.
     */
    private const int NOW = 1_763_510_400;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(self::NOW));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_signs_the_timestamp_and_the_payload_as_the_docs_header(): void
    {
        $header = $this->signature()->sign(self::PAYLOAD, self::NOW);

        $this->assertSame(
            sprintf('t=%d,v1=%s', self::NOW, hash_hmac('sha256', self::NOW . '.' . self::PAYLOAD, self::SECRET)),
            $header,
        );
    }

    public function test_it_verifies_its_own_signature(): void
    {
        $signature = $this->signature();

        $signature->verify(self::PAYLOAD, $signature->sign(self::PAYLOAD, self::NOW));

        $this->addToAssertionCount(1);
    }

    #[DataProvider('missingHeaders')]
    public function test_an_absent_or_blank_header_is_missing(?string $header): void
    {
        $this->assertRejectedAs(WebhookSignatureFailure::Missing, $header);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function missingHeaders(): array
    {
        return [
            'absent' => [null],
            'empty' => [''],
            'blank' => ['   '],
        ];
    }

    #[DataProvider('malformedHeaders')]
    public function test_a_header_that_does_not_parse_is_malformed(string $header): void
    {
        $this->assertRejectedAs(WebhookSignatureFailure::Malformed, $header);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedHeaders(): array
    {
        $hex = hash_hmac('sha256', self::NOW . '.' . self::PAYLOAD, self::SECRET);

        return [
            'no t' => ['v1=' . $hex],
            'no v1' => ['t=' . self::NOW],
            'an empty t' => ['t=,v1=' . $hex],
            'an empty v1' => [sprintf('t=%d,v1=', self::NOW)],
            'a non-numeric t' => ['t=now,v1=' . $hex],
            'a fractional t' => [sprintf('t=%d.5,v1=%s', self::NOW, $hex)],
            'a negative t' => [sprintf('t=-%d,v1=%s', self::NOW, $hex)],
            'a repeated t' => [sprintf('t=%d,t=%d,v1=%s', self::NOW, self::NOW, $hex)],
            'a repeated v1' => [sprintf('t=%d,v1=%s,v1=%s', self::NOW, $hex, $hex)],
            'a part without =' => [sprintf('t=%d,v1=%s,garbage', self::NOW, $hex)],
            'not key=value at all' => ['garbage'],
            'a t too large for an integer' => ['t=99999999999999999999,v1=' . $hex],
        ];
    }

    public function test_spaces_around_parts_and_unknown_keys_are_accepted(): void
    {
        $hex = hash_hmac('sha256', self::NOW . '.' . self::PAYLOAD, self::SECRET);

        $this->signature()->verify(self::PAYLOAD, sprintf(' t=%d , v0=ignored ,  v1=%s ', self::NOW, $hex));

        $this->addToAssertionCount(1);
    }

    #[DataProvider('acceptedOffsets')]
    public function test_a_timestamp_within_the_tolerance_is_accepted(int $offset): void
    {
        $signature = $this->signature();

        $signature->verify(self::PAYLOAD, $signature->sign(self::PAYLOAD, self::NOW + $offset));

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function acceptedOffsets(): array
    {
        return [
            'now' => [0],
            '300 s ago' => [-300],
            '300 s ahead' => [300],
        ];
    }

    #[DataProvider('staleOffsets')]
    public function test_a_timestamp_beyond_the_tolerance_is_stale(int $offset): void
    {
        $this->assertRejectedAs(
            WebhookSignatureFailure::Stale,
            $this->signature()->sign(self::PAYLOAD, self::NOW + $offset),
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function staleOffsets(): array
    {
        return [
            '301 s ago' => [-301],
            '301 s ahead' => [301],
            'the epoch' => [-self::NOW],
        ];
    }

    public function test_a_signature_made_with_another_secret_is_a_mismatch(): void
    {
        $this->assertRejectedAs(
            WebhookSignatureFailure::Mismatch,
            $this->signature('whsec_another_secret')->sign(self::PAYLOAD, self::NOW),
        );
    }

    public function test_a_body_changed_by_one_byte_is_a_mismatch(): void
    {
        $header = $this->signature()->sign(self::PAYLOAD, self::NOW);

        $this->assertRejectedAs(WebhookSignatureFailure::Mismatch, $header, str_replace('"ping"', '"pinG"', self::PAYLOAD));
    }

    public function test_a_valid_signature_for_another_timestamp_is_a_mismatch(): void
    {
        $hex = hash_hmac('sha256', (self::NOW - 10) . '.' . self::PAYLOAD, self::SECRET);

        $this->assertRejectedAs(WebhookSignatureFailure::Mismatch, sprintf('t=%d,v1=%s', self::NOW, $hex));
    }

    public function test_an_upper_cased_signature_is_a_mismatch(): void
    {
        $hex = hash_hmac('sha256', self::NOW . '.' . self::PAYLOAD, self::SECRET);

        $this->assertRejectedAs(WebhookSignatureFailure::Mismatch, sprintf('t=%d,v1=%s', self::NOW, strtoupper($hex)));
    }

    /**
     * The signature is checked first, so only a genuine delivery is ever stale.
     */
    public function test_a_stale_timestamp_with_a_wrong_signature_is_a_mismatch(): void
    {
        $this->assertRejectedAs(
            WebhookSignatureFailure::Mismatch,
            $this->signature('whsec_another_secret')->sign(self::PAYLOAD, self::NOW - 3_600),
        );
    }

    #[DataProvider('unconfiguredSecrets')]
    public function test_signing_without_a_secret_throws(?string $secret): void
    {
        $this->expectException(WebhookSecretNotConfiguredException::class);

        $this->signature($secret)->sign(self::PAYLOAD, self::NOW);
    }

    /**
     * Even a header made with an empty key never verifies: the HMAC is never
     * computed without a secret.
     */
    #[DataProvider('unconfiguredSecrets')]
    public function test_verifying_without_a_secret_throws(?string $secret): void
    {
        $header = sprintf('t=%d,v1=%s', self::NOW, hash_hmac('sha256', self::NOW . '.' . self::PAYLOAD, ''));

        $this->expectException(WebhookSecretNotConfiguredException::class);

        $this->signature($secret)->verify(self::PAYLOAD, $header);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function unconfiguredSecrets(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
        ];
    }

    public function test_a_rejection_message_carries_neither_the_header_nor_the_secret(): void
    {
        $header = $this->signature('whsec_another_secret')->sign(self::PAYLOAD, self::NOW);

        try {
            $this->signature()->verify(self::PAYLOAD, $header);
            $this->fail('The signature was accepted.');
        } catch (InvalidWebhookSignatureException $exception) {
            $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
            $this->assertStringNotContainsString($header, $exception->getMessage());
            $this->assertStringNotContainsString(self::PAYLOAD, $exception->getMessage());
        }
    }

    private function signature(?string $secret = self::SECRET): WebhookSignature
    {
        return new WebhookSignature($secret, 300);
    }

    private function assertRejectedAs(WebhookSignatureFailure $reason, ?string $header, string $payload = self::PAYLOAD): void
    {
        try {
            $this->signature()->verify($payload, $header);
        } catch (InvalidWebhookSignatureException $exception) {
            $this->assertSame($reason, $exception->reason);

            return;
        }

        $this->fail(sprintf('The signature was accepted instead of rejected as %s.', $reason->value));
    }
}
