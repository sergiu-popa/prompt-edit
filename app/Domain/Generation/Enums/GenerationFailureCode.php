<?php

declare(strict_types=1);

namespace App\Domain\Generation\Enums;

use App\Domain\Generation\Models\Generation;
use Illuminate\Support\Str;

/**
 * Why a generation failed, and what History tells the user about it: the single
 * mapping of failure copy (D-43). A failure's code is its local reason when it has
 * one, else Pixelforge's `error.code`, so the values are spelled as both spell them.
 * PE-013 and PE-014 append their local reasons here, each with its copy.
 */
enum GenerationFailureCode: string
{
    case ContentPolicyViolation = 'content_policy_violation';
    case ValidationFailed = 'validation_failed';
    case IdempotencyKeyReused = 'idempotency_key_reused';
    case GenerationFailed = 'generation_failed';
    case InternalError = 'internal_error';
    case ProviderOverloaded = 'provider_overloaded';
    case ProviderBusy = 'provider_busy';
    case ServiceUnavailable = 'service_unavailable';

    /**
     * What any other, or a missing, code reads.
     */
    public const string GENERIC_MESSAGE = 'This generation failed.';

    /**
     * How much of Pixelforge's own message the copy quotes. Its `param` is already at
     * most 255 characters, but the message is any length the provider sends.
     */
    public const int MAX_DETAIL_LENGTH = 300;

    /**
     * The local reason wins over the stored `error_code`, because a local reason
     * can be recorded with a provider code that differs from it.
     */
    public static function tryFromGeneration(Generation $generation): ?self
    {
        return self::tryFrom($generation->local_failure_reason->value ?? $generation->error_code ?? '');
    }

    public static function messageFor(Generation $generation): string
    {
        return self::tryFromGeneration($generation)?->message($generation->error_param, $generation->error_message)
            ?? self::GENERIC_MESSAGE;
    }

    /**
     * The copy for this code. Only `validation_failed` reads Pixelforge's own
     * words, its `param` and `message`; the page renders the copy as text.
     */
    public function message(?string $param, ?string $detail): string
    {
        return match ($this) {
            self::ContentPolicyViolation => 'Prompt rejected by Pixelforge\'s content policy. Edit the prompt and try again.',
            self::ValidationFailed => self::rejectedRequest($param, $detail),
            self::IdempotencyKeyReused => 'Something went wrong submitting this generation.',
            self::GenerationFailed => 'Pixelforge couldn\'t produce an image for this prompt. Try rephrasing it.',
            self::InternalError => 'Something went wrong on Pixelforge\'s side. Please try again.',
            self::ProviderOverloaded => 'Pixelforge was overloaded and didn\'t accept this request. You were not charged.',
            self::ProviderBusy => 'Pixelforge stayed busy for too long, so we stopped waiting. You were not charged.',
            self::ServiceUnavailable => 'Service unavailable — this generation wasn\'t sent. You were not charged.',
        };
    }

    /**
     * "Pixelforge rejected the request: {param}: {message}", leaving out what is blank,
     * with a message past MAX_DETAIL_LENGTH cut and marked with an ellipsis.
     */
    private static function rejectedRequest(?string $param, ?string $detail): string
    {
        $detail = $detail === null ? null : Str::limit($detail, self::MAX_DETAIL_LENGTH, '…');
        $parts = array_filter([$param, $detail], fn (?string $part): bool => $part !== null && $part !== '');

        return $parts === []
            ? 'Pixelforge rejected the request.'
            : 'Pixelforge rejected the request: ' . implode(': ', $parts);
    }
}
