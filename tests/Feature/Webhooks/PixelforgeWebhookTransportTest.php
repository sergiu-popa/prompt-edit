<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Http\Middleware\VerifyPixelforgeWebhookSignature;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\DeliversPixelforgeWebhooks;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\TestCase;

/**
 * Only a request whose signature matches its exact raw body, made now with
 * our secret, gets through. Pixelforge sends no session, cookie or CSRF token
 * and never follows a redirect.
 */
final class PixelforgeWebhookTransportTest extends TestCase
{
    use CapturesLogMessages;
    use DeliversPixelforgeWebhooks;
    use InteractsWithCreditLedger;
    use RefreshDatabase;

    public function test_a_signed_ping_is_accepted_without_a_session_csrf_token_or_user(): void
    {
        $response = $this->deliverPixelforgeEvent(self::pixelforgeEvent('ping', null));

        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertGuest();
    }

    public function test_the_route_runs_only_the_signature_middleware(): void
    {
        $route = Route::getRoutes()->getByName('webhooks.pixelforge');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('webhooks/pixelforge', $route->uri());
        $this->assertSame([VerifyPixelforgeWebhookSignature::class], $route->gatherMiddleware());
    }

    public function test_a_hundred_back_to_back_pings_are_all_accepted(): void
    {
        for ($ping = 1; $ping <= 100; $ping++) {
            $this->deliverPixelforgeEvent(self::pixelforgeEvent('ping', null))->assertOk();
        }

        $this->assertCount(100, $this->storedWebhookEvents());
    }

    /**
     * @param  Closure(array<string, mixed>): string  $reEncode
     */
    #[DataProvider('reEncodings')]
    public function test_the_same_event_re_encoded_under_the_original_signature_is_a_mismatch(Closure $reEncode): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $event = self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($lighthouse));
        $original = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $reEncoded = $reEncode($event);
        $this->assertNotSame($original, $reEncoded);
        $this->assertEquals(json_decode($original, true), json_decode($reEncoded, true));

        $response = $this->deliverPixelforgeWebhook($reEncoded, [
            'Pixelforge-Signature' => $this->pixelforgeSignatureFor($original, now()->getTimestamp()),
        ]);

        $response->assertStatus(400);
        $this->assertSame([], $this->storedWebhookEvents());
        $this->assertRejectionLogged('mismatch', $this->stringField($event, 'id'));
    }

    /**
     * @return array<string, array{Closure(array<string, mixed>): string}>
     */
    public static function reEncodings(): array
    {
        return [
            'keys reordered' => [static fn (array $event): string => json_encode(array_reverse($event, true), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
            'pretty-printed' => [static fn (array $event): string => json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)],
        ];
    }

    /**
     * The global TrimStrings middleware cleans only the parsed input, never
     * the raw bytes the signature covers.
     */
    public function test_a_pretty_printed_body_with_padded_strings_signed_as_sent_is_accepted(): void
    {
        $event = [...self::pixelforgeEvent('ping', null), 'note' => '  padded  ', 'empty' => ''];

        $this->deliverPixelforgeWebhook(json_encode($event, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT))->assertOk();
    }

    /**
     * @param  Closure(string, int): array{string, ?string}  $tamper  the body as sent and its signature header, from the signed body and now
     */
    #[DataProvider('rejectedSignatures')]
    public function test_a_request_without_a_valid_signature_is_rejected_and_changes_nothing(Closure $tamper, string $reason): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $event = self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($lighthouse));
        $before = [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)];
        $this->travel(1)->minutes();

        [$body, $signature] = $tamper(json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), now()->getTimestamp());
        $response = $this->deliverPixelforgeWebhook($body, ['Pixelforge-Signature' => $signature]);

        $response->assertStatus(400);
        $this->assertSame($before, [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)]);
        $this->assertSame([], $this->storedWebhookEvents());
        $this->assertRejectionLogged($reason, $this->stringField($event, 'id'));
    }

    /**
     * @return array<string, array{Closure(string, int): array{string, ?string}, string}>
     */
    public static function rejectedSignatures(): array
    {
        $hmac = static fn (string $body, int $timestamp, string $secret = self::WEBHOOK_SECRET): string => hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        return [
            'no signature header' => [static fn (string $body, int $now): array => [$body, null], 'missing'],
            'no t' => [static fn (string $body, int $now): array => [$body, 'v1=' . $hmac($body, $now)], 'malformed'],
            'no v1' => [static fn (string $body, int $now): array => [$body, 't=' . $now], 'malformed'],
            'a non-numeric t' => [static fn (string $body, int $now): array => [$body, 't=now,v1=' . $hmac($body, $now)], 'malformed'],
            'another secret' => [
                static fn (string $body, int $now): array => [$body, sprintf('t=%d,v1=%s', $now, $hmac($body, $now, 'whsec_another_secret'))],
                'mismatch',
            ],
            'the body changed by one byte' => [
                static fn (string $body, int $now): array => [str_replace('"12.50"', '"12.51"', $body), sprintf('t=%d,v1=%s', $now, $hmac($body, $now))],
                'mismatch',
            ],
            'a valid signature for another t' => [
                static fn (string $body, int $now): array => [$body, sprintf('t=%d,v1=%s', $now, $hmac($body, $now - 1))],
                'mismatch',
            ],
        ];
    }

    #[DataProvider('signatureAges')]
    public function test_the_replay_window_is_300_seconds_either_way(int $offset, int $status): void
    {
        $event = self::pixelforgeEvent('ping', null);
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        $response = $this->deliverPixelforgeWebhook($body, [
            'Pixelforge-Signature' => $this->pixelforgeSignatureFor($body, now()->getTimestamp() + $offset),
        ]);

        $response->assertStatus($status);
        $this->assertCount($status === 200 ? 1 : 0, $this->storedWebhookEvents());

        if ($status === 400) {
            $this->assertRejectionLogged('stale', $this->stringField($event, 'id'));
        }
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function signatureAges(): array
    {
        return [
            '300 s ago' => [-300, 200],
            '300 s ahead' => [300, 200],
            '301 s ago' => [-301, 400],
            '301 s ahead' => [301, 400],
        ];
    }

    public function test_a_rejection_without_an_event_id_header_logs_a_null_event_id(): void
    {
        $this->deliverPixelforgeWebhook('{}', ['Pixelforge-Signature' => null])->assertStatus(400);

        $this->assertRejectionLogged('missing', null);
    }

    /**
     * @param  Closure(string, int): ?string  $sign
     */
    #[DataProvider('unconfiguredSecrets')]
    public function test_without_a_secret_every_request_is_a_500_logged_as_critical(?string $secret, Closure $sign): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $before = [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)];
        config(['pixelforge.webhook_secret' => $secret]);
        $event = self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($lighthouse));
        $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $response = $this->deliverPixelforgeWebhook($body, ['Pixelforge-Signature' => $sign($body, now()->getTimestamp())]);

        $response->assertStatus(500);
        $this->assertSame($before, [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)]);
        $this->assertSame([], $this->storedWebhookEvents());

        $critical = array_values(array_filter($this->loggedMessages(), fn (MessageLogged $message): bool => $message->level === 'critical'));
        $this->assertCount(1, $critical);
        $this->assertSame(['event_id' => $event['id']], $critical[0]->context);
    }

    /**
     * @return array<string, array{?string, Closure(string, int): ?string}>
     */
    public static function unconfiguredSecrets(): array
    {
        $requests = [
            'unsigned' => static fn (string $body, int $now): ?string => null,
            'signed with an empty key' => static fn (string $body, int $now): string => sprintf('t=%d,v1=%s', $now, hash_hmac('sha256', $now . '.' . $body, '')),
            'signed with another key' => static fn (string $body, int $now): string => sprintf('t=%d,v1=%s', $now, hash_hmac('sha256', $now . '.' . $body, 'whsec_another_secret')),
        ];

        $rows = [];

        foreach (['null' => null, 'empty' => ''] as $secretName => $secret) {
            foreach ($requests as $requestName => $sign) {
                $rows[sprintf('a %s secret, %s', $secretName, $requestName)] = [$secret, $sign];
            }
        }

        return $rows;
    }

    /**
     * With a sentinel secret, no log line of any outcome carries the secret
     * or the body, in its message or its context.
     *
     * @param  Closure(Generation): string  $bodyFor
     * @param  Closure(string, int, string): ?string  $sign  the signature header, from the body, now and the secret
     */
    #[DataProvider('loggedOutcomes')]
    public function test_no_log_line_carries_the_secret_or_the_body(Closure $bodyFor, Closure $sign, int $status, bool $logs): void
    {
        $sentinel = 'whsec_SENTINEL_9f3c1a7e5b2d8046';
        config(['pixelforge.webhook_secret' => $sentinel]);
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $body = $bodyFor($lighthouse);

        $this->deliverPixelforgeWebhook($body, ['Pixelforge-Signature' => $sign($body, now()->getTimestamp(), $sentinel)])->assertStatus($status);

        $this->assertSame($logs, $this->loggedMessages() !== []);

        foreach ($this->loggedMessages() as $message) {
            $line = $message->message . ' ' . json_encode($message->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            $this->assertStringNotContainsString($sentinel, $line);
            $this->assertStringNotContainsString($body, $line);
        }
    }

    /**
     * @return array<string, array{Closure(Generation): string, Closure(string, int, string): ?string, int, bool}>
     */
    public static function loggedOutcomes(): array
    {
        $encode = static fn (array $event): string => json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $succeeded = static fn (Generation $generation): string => $encode(self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($generation)));
        $signed = static fn (string $body, int $now, string $secret): string => sprintf('t=%d,v1=%s', $now, hash_hmac('sha256', $now . '.' . $body, $secret));

        return [
            'missing' => [$succeeded, static fn (): ?string => null, 400, true],
            'malformed' => [$succeeded, static fn (string $body, int $now): string => 't=' . $now, 400, true],
            'mismatch' => [$succeeded, static fn (string $body, int $now): string => $signed($body, $now, 'whsec_another_secret'), 400, true],
            'stale' => [$succeeded, static fn (string $body, int $now, string $secret): string => $signed($body, $now - 301, $secret), 400, true],
            'an unreadable JSON body' => [static fn (): string => '{"id":"evt_1","type":"ping",', $signed, 400, true],
            'an unreadable snapshot' => [
                static fn (Generation $generation): string => $encode(self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($generation, ['usage' => null]))),
                $signed,
                400,
                true,
            ],
            'an unknown generation' => [
                static fn (Generation $generation): string => $encode(self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($generation, ['id' => 'gen_UNKNOWN', 'metadata' => ['generation_id' => 'not-a-ulid']]))),
                $signed,
                200,
                true,
            ],
            'an applied success' => [$succeeded, $signed, 200, false],
            'an applied processing' => [
                static fn (Generation $generation): string => $encode(self::pixelforgeEvent('generation.processing', self::generationObjectFor($generation, GenerationStatus::Processing))),
                $signed,
                200,
                false,
            ],
        ];
    }

    public function test_no_critical_log_carries_the_body_when_no_secret_is_configured(): void
    {
        config(['pixelforge.webhook_secret' => null]);
        $body = json_encode(self::pixelforgeEvent('ping', null), JSON_THROW_ON_ERROR);

        $this->deliverPixelforgeWebhook($body)->assertStatus(500);

        $this->assertCount(1, $this->loggedMessages());
        $this->assertStringNotContainsString($body, json_encode($this->loggedMessages()[0]->context, JSON_THROW_ON_ERROR));
    }

    private function assertRejectionLogged(string $reason, ?string $eventId): void
    {
        $warnings = array_values(array_filter($this->loggedMessages(), fn (MessageLogged $message): bool => $message->level === 'warning'));

        $this->assertCount(1, $warnings);
        $this->assertSame('Refused a Pixelforge webhook with an invalid signature.', $warnings[0]->message);
        $this->assertSame(['reason' => $reason, 'event_id' => $eventId], $warnings[0]->context);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function stringField(array $event, string $key): string
    {
        $this->assertIsString($event[$key]);

        return $event[$key];
    }
}
