<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Enums;

use App\Domain\Generation\Enums\GenerationFailureCode;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Models\Generation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GenerationFailureCodeTest extends TestCase
{
    /**
     * @return array<string, array{GenerationFailureCode, string}>
     */
    public static function messages(): array
    {
        return [
            'content policy' => [
                GenerationFailureCode::ContentPolicyViolation,
                'Prompt rejected by Pixelforge\'s content policy. Edit the prompt and try again.',
            ],
            'idempotency key reused' => [
                GenerationFailureCode::IdempotencyKeyReused,
                'Something went wrong submitting this generation.',
            ],
            'generation failed' => [
                GenerationFailureCode::GenerationFailed,
                'Pixelforge couldn\'t produce an image for this prompt. Try rephrasing it.',
            ],
            'internal error' => [
                GenerationFailureCode::InternalError,
                'Something went wrong on Pixelforge\'s side. Please try again.',
            ],
            'provider overloaded' => [
                GenerationFailureCode::ProviderOverloaded,
                'Pixelforge was overloaded and didn\'t accept this request. You were not charged.',
            ],
            'provider busy' => [
                GenerationFailureCode::ProviderBusy,
                'Pixelforge stayed busy for too long, so we stopped waiting. You were not charged.',
            ],
            'service unavailable' => [
                GenerationFailureCode::ServiceUnavailable,
                'Service unavailable — this generation wasn\'t sent. You were not charged.',
            ],
            'daily limit' => [
                GenerationFailureCode::DailyLimit,
                'Daily capacity reached, try after 00:00 UTC. You were not charged.',
            ],
            'lost' => [
                GenerationFailureCode::Lost,
                'We couldn\'t confirm this generation with Pixelforge. You were not charged.',
            ],
        ];
    }

    #[DataProvider('messages')]
    public function test_each_code_reads_its_one_message(GenerationFailureCode $code, string $message): void
    {
        $this->assertSame($message, $code->message(null, null));
        $this->assertSame($message, $code->message('prompt', 'Ignored detail.'));
    }

    /**
     * @return array<string, array{?string, ?string, string}>
     */
    public static function validationFailures(): array
    {
        return [
            'a param and a message' => ['size', 'size must be one of 1024x1024, 1536x1024, 1024x1536.', 'Pixelforge rejected the request: size: size must be one of 1024x1024, 1536x1024, 1024x1536.'],
            'a message only' => [null, 'The request is invalid.', 'Pixelforge rejected the request: The request is invalid.'],
            'neither' => [null, null, 'Pixelforge rejected the request.'],
            'blank values' => ['', '', 'Pixelforge rejected the request.'],
        ];
    }

    #[DataProvider('validationFailures')]
    public function test_validation_failed_says_what_pixelforge_rejected(?string $param, ?string $detail, string $message): void
    {
        $this->assertSame($message, GenerationFailureCode::ValidationFailed->message($param, $detail));
    }

    public function test_a_long_pixelforge_message_is_cut_to_a_readable_length(): void
    {
        $message = GenerationFailureCode::ValidationFailed->message('prompt', str_repeat('é', 5_000));

        $this->assertSame('Pixelforge rejected the request: prompt: ' . str_repeat('é', 300) . '…', $message);
    }

    public function test_a_pixelforge_message_at_the_limit_is_kept_whole(): void
    {
        $detail = str_repeat('a', 300);

        $this->assertSame('Pixelforge rejected the request: ' . $detail, GenerationFailureCode::ValidationFailed->message(null, $detail));
    }

    public function test_the_codes_are_spelled_as_the_local_reasons_and_pixelforges_error_codes(): void
    {
        $this->assertSame(
            [
                'content_policy_violation',
                'validation_failed',
                'idempotency_key_reused',
                'generation_failed',
                'internal_error',
                'provider_overloaded',
                'provider_busy',
                'service_unavailable',
                'daily_limit',
                'lost',
            ],
            array_column(GenerationFailureCode::cases(), 'value'),
        );
    }

    public function test_every_local_failure_reason_has_its_copy(): void
    {
        $this->assertSame([], array_values(array_diff(
            array_column(LocalFailureReason::cases(), 'value'),
            array_column(GenerationFailureCode::cases(), 'value'),
        )), 'Local reasons without copy');
    }

    public function test_a_local_reason_wins_over_a_different_error_code(): void
    {
        $generation = self::failedGeneration(LocalFailureReason::IdempotencyKeyReused, 'generation_failed');

        $this->assertSame(GenerationFailureCode::IdempotencyKeyReused, GenerationFailureCode::tryFromGeneration($generation));
        $this->assertSame('Something went wrong submitting this generation.', GenerationFailureCode::messageFor($generation));
    }

    public function test_a_provider_failure_is_read_from_its_error_code(): void
    {
        $generation = self::failedGeneration(null, 'internal_error');

        $this->assertSame(GenerationFailureCode::InternalError, GenerationFailureCode::tryFromGeneration($generation));
    }

    public function test_the_message_carries_the_stored_param_and_detail(): void
    {
        $generation = self::failedGeneration(LocalFailureReason::ValidationFailed, 'validation_failed', 'prompt', 'prompt must be at most 4000 characters.');

        $this->assertSame('Pixelforge rejected the request: prompt: prompt must be at most 4000 characters.', GenerationFailureCode::messageFor($generation));
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function unknownCodes(): array
    {
        return [
            'an unknown code' => ['model_on_fire'],
            'no code' => [null],
            'a code in another case' => ['GENERATION_FAILED'],
        ];
    }

    #[DataProvider('unknownCodes')]
    public function test_an_unknown_or_missing_code_reads_the_generic_message(?string $errorCode): void
    {
        $generation = self::failedGeneration(null, $errorCode);

        $this->assertNull(GenerationFailureCode::tryFromGeneration($generation));
        $this->assertSame('This generation failed.', GenerationFailureCode::messageFor($generation));
        $this->assertSame('This generation failed.', GenerationFailureCode::GENERIC_MESSAGE);
    }

    private static function failedGeneration(?LocalFailureReason $reason, ?string $errorCode, ?string $param = null, ?string $detail = null): Generation
    {
        return new Generation([
            'status' => GenerationStatus::Failed,
            'local_failure_reason' => $reason,
            'error_code' => $errorCode,
            'error_param' => $param,
            'error_message' => $detail,
        ]);
    }
}
