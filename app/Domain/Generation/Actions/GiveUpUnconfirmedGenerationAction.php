<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Billing\Exceptions\InvalidRateException;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Generation\DataTransferObjects\LocalGenerationFailureData;
use App\Domain\Generation\Enums\GenerationTransitionOutcome;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Exceptions\ConcurrentGenerationTransitionException;
use App\Domain\Generation\Models\Generation;
use App\Support\Pixelforge\PixelforgeCreateLock;
use Illuminate\Support\Facades\Log;

/**
 * Gives up on a generation Pixelforge never confirmed, a day after its creation
 * (D-49): it is failed `lost` through the transition action, free, with its hold
 * released. Such a row is always `pending`, the only status a local failure may end.
 * It takes the create lock without waiting (D-53), so no re-dispatched create is in
 * flight while it fails the row; if a submit run holds it, the row is left for the
 * next run. If the row has moved on meanwhile (a re-dispatched create landed), the
 * transition's row lock orders the two and refuses the failure, and only an info
 * line says nothing was given up. A later success of a `lost` row records provider
 * cost only (D-09).
 */
final class GiveUpUnconfirmedGenerationAction
{
    public function __construct(
        private readonly TransitionGenerationAction $transitionGeneration,
        private readonly PixelforgeCreateLock $createLock,
    ) {}

    /**
     * @throws ConcurrentGenerationTransitionException
     * @throws InvalidRateException
     * @throws NanodollarOverflowException
     */
    public function execute(Generation $generation): void
    {
        $context = [
            'generation_id' => $generation->id,
            'created_at' => $generation->created_at->toIso8601ZuluString(),
            'last_attempted_at' => $generation->submit_last_attempted_at?->toIso8601ZuluString(),
        ];
        $lock = $this->createLock->for($generation->id);

        if (! $lock->get()) {
            Log::info('A submit run holds the create lock; the reconciler gives up on the generation at its next run instead.', $context);

            return;
        }

        try {
            $outcome = $this->transitionGeneration->execute(
                $generation,
                new LocalGenerationFailureData(LocalFailureReason::Lost, null, null, null),
            );
        } finally {
            $lock->release();
        }

        $context['outcome'] = $outcome->value;

        if ($outcome !== GenerationTransitionOutcome::Applied) {
            Log::info('A generation moved on before the reconciler gave up on it; nothing was given up.', $context);

            return;
        }

        Log::error('Gave up on a generation Pixelforge never confirmed; it is failed as lost and not charged.', $context);
    }
}
