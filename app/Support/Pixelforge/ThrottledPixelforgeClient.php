<?php

declare(strict_types=1);

namespace App\Support\Pixelforge;

use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\DataTransferObjects\CreatedGenerationData;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use App\Support\Pixelforge\Exceptions\RateBudgetExhaustedException;

/**
 * Every call through the contract first takes one unit of the account-wide budget
 * (D-44). It wraps whichever driver is bound, so no caller can skip it.
 */
final readonly class ThrottledPixelforgeClient implements PixelforgeClient
{
    public function __construct(
        public PixelforgeClient $client,
        private PixelforgeRequestBudget $budget,
    ) {}

    /**
     * @throws RateBudgetExhaustedException when this minute's budget is spent; nothing is sent
     */
    public function createGeneration(CreateGenerationPayloadData $payload): CreatedGenerationData
    {
        $this->budget->take();

        return $this->client->createGeneration($payload);
    }

    /**
     * @throws RateBudgetExhaustedException when this minute's budget is spent; nothing is sent
     */
    public function retrieveGeneration(string $providerId): GenerationSnapshot
    {
        $this->budget->take();

        return $this->client->retrieveGeneration($providerId);
    }

    /**
     * Sends nothing, so it takes nothing.
     */
    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }
}
