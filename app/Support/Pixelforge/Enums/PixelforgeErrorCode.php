<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Enums;

/**
 * The documented `error.code` values of the API calls we make, spelled as the
 * docs spell them. Callers branch on the code, never on the `type`.
 * `date_range_too_long` is left to PE-018, its only caller.
 */
enum PixelforgeErrorCode: string
{
    case ContentPolicyViolation = 'content_policy_violation';
    case InvalidApiKey = 'invalid_api_key';
    case NotFound = 'not_found';
    case IdempotencyKeyReused = 'idempotency_key_reused';
    case ValidationFailed = 'validation_failed';
    case RateLimitExceeded = 'rate_limit_exceeded';
    case ConcurrencyLimitExceeded = 'concurrency_limit_exceeded';
    case DailyLimitExceeded = 'daily_limit_exceeded';
    case Overloaded = 'overloaded';

    /**
     * The only HTTP status the docs give the code. An envelope with any other
     * status is not documented.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::ContentPolicyViolation => 400,
            self::InvalidApiKey => 401,
            self::NotFound => 404,
            self::IdempotencyKeyReused => 409,
            self::ValidationFailed => 422,
            self::RateLimitExceeded, self::ConcurrencyLimitExceeded, self::DailyLimitExceeded => 429,
            self::Overloaded => 503,
        };
    }
}
