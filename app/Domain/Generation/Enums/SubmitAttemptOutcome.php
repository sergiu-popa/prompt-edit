<?php

declare(strict_types=1);

namespace App\Domain\Generation\Enums;

use App\Support\Pixelforge\Contracts\ReportsPixelforgeContext;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use App\Support\Pixelforge\Exceptions\PixelforgeApiException;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use App\Support\Pixelforge\Exceptions\RateBudgetExhaustedException;

/**
 * How one run of the submit job ended, as the generation's bookkeeping records it
 * (D-45). A run whose start is recorded but whose outcome is not died after it may
 * have sent its request, so it is ambiguous.
 */
enum SubmitAttemptOutcome: string
{
    /**
     * A `202`, or a replay: Pixelforge has the generation.
     */
    case Accepted = 'accepted';

    /**
     * A definitive `400`, `422` or `409`: Pixelforge created nothing (D-31).
     */
    case Rejected = 'rejected';

    /**
     * Our own account-wide budget was spent, so nothing was sent (D-44).
     */
    case BudgetExhausted = 'budget_exhausted';

    case RateLimited = 'rate_limited';

    case ConcurrencyLimited = 'concurrency_limited';

    case DailyLimited = 'daily_limited';

    /**
     * `503 overloaded`: nothing was created.
     */
    case Overloaded = 'overloaded';

    /**
     * The answer does not say whether Pixelforge created the generation.
     */
    case Ambiguous = 'ambiguous';

    case InvalidApiKey = 'invalid_api_key';

    /**
     * Nothing could be sent: no key, or no webhook secret for the fake.
     */
    case NotConfigured = 'not_configured';

    /**
     * The outcome of a create that failed. A documented code that is not one of ours
     * (a `404` or a `422 date_range_too_long` at create) and every ambiguous answer may
     * have created the generation, so they are ambiguous (D-31, D-73).
     */
    public static function fromFailure(ReportsPixelforgeContext $failure): self
    {
        if ($failure instanceof RateBudgetExhaustedException) {
            return self::BudgetExhausted;
        }

        if ($failure instanceof PixelforgeNotConfiguredException) {
            return self::NotConfigured;
        }

        if (! $failure instanceof PixelforgeApiException) {
            return self::Ambiguous;
        }

        return match ($failure->errorCode()) {
            PixelforgeErrorCode::ContentPolicyViolation, PixelforgeErrorCode::ValidationFailed, PixelforgeErrorCode::IdempotencyKeyReused => self::Rejected,
            PixelforgeErrorCode::RateLimitExceeded => self::RateLimited,
            PixelforgeErrorCode::ConcurrencyLimitExceeded => self::ConcurrencyLimited,
            PixelforgeErrorCode::DailyLimitExceeded => self::DailyLimited,
            PixelforgeErrorCode::Overloaded => self::Overloaded,
            PixelforgeErrorCode::InvalidApiKey => self::InvalidApiKey,
            PixelforgeErrorCode::NotFound, PixelforgeErrorCode::DateRangeTooLong => self::Ambiguous,
        };
    }

    /**
     * What History says while the generation waits after this outcome: null for an
     * outcome that ends the waiting, so the note of the wait before it stays.
     */
    public function retryNote(): ?SubmitRetryNote
    {
        return match ($this) {
            self::Accepted, self::Rejected => null,
            self::ConcurrencyLimited => SubmitRetryNote::WaitingForSlot,
            self::BudgetExhausted, self::RateLimited, self::DailyLimited, self::Overloaded,
            self::Ambiguous, self::InvalidApiKey, self::NotConfigured => SubmitRetryNote::Retrying,
        };
    }

    /**
     * Why the generation fails free when the window ends after this outcome (AC 17).
     * Null when it may exist at Pixelforge, or was answered, so it is handed over
     * instead: the safe side.
     */
    public function windowEndReason(): ?LocalFailureReason
    {
        return match ($this) {
            self::BudgetExhausted, self::RateLimited, self::ConcurrencyLimited, self::DailyLimited => LocalFailureReason::ProviderBusy,
            self::Overloaded => LocalFailureReason::ProviderOverloaded,
            self::InvalidApiKey, self::NotConfigured => LocalFailureReason::ServiceUnavailable,
            self::Accepted, self::Rejected, self::Ambiguous => null,
        };
    }
}
