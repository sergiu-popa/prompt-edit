<?php

declare(strict_types=1);

namespace App\Support\Pixelforge;

use App\Support\Pixelforge\Exceptions\RateBudgetExhaustedException;
use Illuminate\Cache\RateLimiter;

/**
 * The one account-wide budget of Pixelforge requests, below its 60 a minute (D-44).
 * It owns the key, the window and the limit, and counts in a store every process
 * shares, so every reader sees the same count. A unit is taken before the limit is
 * compared: the database store increments under a row lock, so two workers can
 * never both take the last one. Bound in AppServiceProvider.
 */
final readonly class PixelforgeRequestBudget
{
    /**
     * The rate limiter key of the account's requests.
     */
    public const string BUDGET_KEY = 'pixelforge-requests';

    public const int WINDOW_SECONDS = 60;

    public function __construct(
        private RateLimiter $rateLimiter,
        private int $requestsPerMinute,
    ) {}

    /**
     * @throws RateBudgetExhaustedException when this minute's budget is spent
     */
    public function take(): void
    {
        $taken = $this->rateLimiter->increment(self::BUDGET_KEY, self::WINDOW_SECONDS);

        if ($taken > $this->requestsPerMinute) {
            throw RateBudgetExhaustedException::freesUpIn($this->rateLimiter->availableIn(self::BUDGET_KEY));
        }
    }
}
