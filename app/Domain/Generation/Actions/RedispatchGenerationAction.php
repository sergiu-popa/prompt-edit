<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\ValueObjects\ReconcilePolicy;
use App\Jobs\SubmitGenerationJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Hands an unsubmitted generation back to PE-013's submit job (D-52): the same row,
 * ULID and bytes, so Pixelforge replays a create that landed or creates it now. The
 * job carries the re-dispatch instant, which owes it one run past its window. The
 * next check is recorded first, so repeats follow D-50's cadence.
 */
final class RedispatchGenerationAction
{
    public function __construct(
        private readonly ReconcilePolicy $policy,
    ) {}

    /**
     * @param  CarbonImmutable  $now  the run's instant, in whole seconds
     */
    public function execute(Generation $generation, CarbonImmutable $now): void
    {
        $generation->recordReconcileBookkeeping([
            'reconcile_recheck_at' => $this->policy->recheckAt($generation->created_at, $now),
        ]);

        SubmitGenerationJob::dispatch($generation->id, $now);

        Log::info('Re-dispatched the submit job of a generation Pixelforge has not confirmed.', [
            'generation_id' => $generation->id,
            'handed_over' => $generation->submit_handed_over_at !== null,
            'last_attempted_at' => $generation->submit_last_attempted_at?->toIso8601ZuluString(),
        ]);
    }
}
