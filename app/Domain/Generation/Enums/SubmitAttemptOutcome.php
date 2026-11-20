<?php

declare(strict_types=1);

namespace App\Domain\Generation\Enums;

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
}
