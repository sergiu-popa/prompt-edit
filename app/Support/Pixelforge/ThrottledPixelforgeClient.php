<?php

declare(strict_types=1);

namespace App\Support\Pixelforge;

use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\DataTransferObjects\CreatedGenerationData;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\Exceptions\RateBudgetExhaustedException;
use Illuminate\Cache\RateLimiter;

/**
 * Every call through the contract first takes one unit of one account-wide budget,
 * below Pixelforge's 60 a minute (D-44). It wraps whichever driver is bound, so no
 * caller can skip it, and it counts in a store every process shares. The unit is
 * taken before the limit is compared: the database store increments under a row
 * lock, so two workers can never both take the last one.
 */
final readonly class ThrottledPixelforgeClient implements PixelforgeClient
{
    /**
     * The rate limiter key of the account's requests.
     */
    public const string BUDGET_KEY = 'pixelforge-requests';

    public const int WINDOW_SECONDS = 60;

    public function __construct(
        public PixelforgeClient $client,
        private RateLimiter $rateLimiter,
        private int $requestsPerMinute,
    ) {}

    /**
     * @throws RateBudgetExhaustedException when this minute's budget is spent; nothing is sent
     */
    public function createGeneration(CreateGenerationPayloadData $payload): CreatedGenerationData
    {
        $this->takeOneUnit();

        return $this->client->createGeneration($payload);
    }

    /**
     * Sends nothing, so it takes nothing.
     */
    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @throws RateBudgetExhaustedException
     */
    private function takeOneUnit(): void
    {
        $taken = $this->rateLimiter->increment(self::BUDGET_KEY, self::WINDOW_SECONDS);

        if ($taken > $this->requestsPerMinute) {
            throw RateBudgetExhaustedException::freesUpIn($this->rateLimiter->availableIn(self::BUDGET_KEY));
        }
    }
}
