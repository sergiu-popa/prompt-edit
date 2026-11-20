<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Billing\Exceptions\InvalidRateException;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Generation\DataTransferObjects\LocalGenerationFailureData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Exceptions\ConcurrentGenerationTransitionException;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\ValueObjects\SubmitBookkeeping;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The submit job stops for good (D-46): its window has ended, or the queue gave up on
 * it. A generation that may exist at Pixelforge (latched, or with an attempt that
 * recorded no outcome) stays `pending` with its hold and is handed to PE-014's
 * reconciler (AC 13, 23). Any other fails free through the transition action, with
 * the reason its last outcome gives (AC 17).
 *
 * A row that is no longer `pending`, or is already handed over, is left alone, so a
 * second stop writes nothing. It needs no Pixelforge client, so it works when none
 * can be built.
 */
final class StopSubmittingGenerationAction
{
    public function __construct(
        private readonly TransitionGenerationAction $transitionGeneration,
    ) {}

    /**
     * @throws ConcurrentGenerationTransitionException
     * @throws InvalidRateException
     * @throws NanodollarOverflowException
     */
    public function execute(string $generationId): void
    {
        $generation = Generation::query()->whereKey($generationId)->firstOrFail();

        if ($generation->status !== GenerationStatus::Pending || $generation->submit_handed_over_at !== null) {
            return;
        }

        $bookkeeping = SubmitBookkeeping::fromGeneration($generation);
        $reason = self::freeFailureReason($bookkeeping);

        if ($reason === null) {
            $this->handOver($generation, $bookkeeping);

            return;
        }

        $outcome = $this->transitionGeneration->execute($generation, new LocalGenerationFailureData($reason, null, null, null));

        Log::warning('Stopped submitting a generation at the end of its window; failed it at no charge.', [
            'generation_id' => $generation->id,
            'reason' => $reason->value,
            'last_outcome' => $bookkeeping->lastOutcome?->value,
            'outcome' => $outcome->value,
        ]);
    }

    /**
     * Null when the generation may exist at Pixelforge. One never attempted was never sent.
     */
    private static function freeFailureReason(SubmitBookkeeping $bookkeeping): ?LocalFailureReason
    {
        if ($bookkeeping->isAmbiguous()) {
            return null;
        }

        if ($bookkeeping->lastOutcome === null) {
            return LocalFailureReason::ServiceUnavailable;
        }

        return $bookkeeping->lastOutcome->windowEndReason();
    }

    private function handOver(Generation $generation, SubmitBookkeeping $bookkeeping): void
    {
        $handedOver = $bookkeeping->handOver(CarbonImmutable::now());
        $generation->recordSubmitBookkeeping($handedOver);

        Log::warning('Stopped submitting a generation that Pixelforge may have; it stays pending with its hold for the reconciler.', [
            'generation_id' => $generation->id,
            'first_attempted_at' => $handedOver->firstAttemptedAt?->toIso8601ZuluString(),
            'last_attempted_at' => $handedOver->lastAttemptedAt?->toIso8601ZuluString(),
            'last_outcome' => $handedOver->lastOutcome?->value,
        ]);
    }
}
