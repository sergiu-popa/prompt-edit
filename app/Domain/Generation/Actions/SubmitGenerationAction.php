<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Billing\Exceptions\InvalidRateException;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Generation\Exceptions\ConcurrentGenerationTransitionException;
use App\Domain\Generation\Models\Generation;
use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\Exceptions\AmbiguousPixelforgeOutcomeException;
use App\Support\Pixelforge\Exceptions\PixelforgeApiException;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use Illuminate\Support\Facades\Log;

/**
 * One submit attempt for a local generation (D-32). The row is re-read and the
 * body rebuilt from its stored columns, so every attempt sends the same bytes
 * under the same key, and Pixelforge replays instead of creating a second
 * generation. The create is sent outside any transaction or row lock, so a
 * webhook landing meanwhile is never blocked, and the answer goes through the
 * transition action, so it never undoes one that landed first (D-06).
 */
final class SubmitGenerationAction
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly TransitionGenerationAction $transitionGeneration,
    ) {}

    /**
     * A generation Pixelforge has already answered for (it has a provider id)
     * or that is terminal is left alone, with no request.
     *
     * @throws PixelforgeApiException
     * @throws AmbiguousPixelforgeOutcomeException
     * @throws PixelforgeNotConfiguredException
     * @throws ConcurrentGenerationTransitionException
     * @throws InvalidRateException
     * @throws NanodollarOverflowException
     */
    public function execute(string $generationId): void
    {
        $generation = Generation::query()->whereKey($generationId)->firstOrFail();

        if ($generation->provider_id !== null || $generation->status->isTerminal()) {
            return;
        }

        $created = $this->pixelforge->createGeneration(self::payloadFor($generation));

        $outcome = $this->transitionGeneration->execute($generation, $created->snapshot);

        Log::info('Applied Pixelforge\'s answer to a generation create.', [
            'generation_id' => $generation->id,
            'provider_id' => $created->snapshot->id,
            'replayed' => $created->replayed,
            'outcome' => $outcome->value,
        ]);
    }

    private static function payloadFor(Generation $generation): CreateGenerationPayloadData
    {
        return new CreateGenerationPayloadData(
            $generation->id,
            $generation->prompt,
            $generation->size,
            $generation->quality,
            $generation->reference_images,
        );
    }
}
