<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\ValueObjects\ReconcilePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One run of the scheduled reconciler (PE-014): it catches up generations whose
 * webhooks never arrived. Confirmed generations with no news for a while are polled,
 * oldest last provider update first, up to the batch (D-51). Every row is isolated:
 * an exception on one is logged with its ULID, and the run goes on.
 */
final class ReconcileGenerationsAction
{
    public function __construct(
        private readonly PollGenerationAction $pollGeneration,
        private readonly ReconcilePolicy $policy,
    ) {}

    /**
     * Now is read once, in whole seconds, as the rows store their instants.
     */
    public function execute(): void
    {
        $now = CarbonImmutable::now()->startOfSecond();

        $this->pollStaleGenerations($now);
    }

    private function pollStaleGenerations(CarbonImmutable $now): void
    {
        $stale = Generation::query()
            ->staleAtPixelforge(
                $this->policy->staleBefore(GenerationStatus::Queued, $now),
                $this->policy->staleBefore(GenerationStatus::Processing, $now),
            )
            ->dueForRecheck($now)
            ->oldestProviderUpdateFirst()
            ->limit($this->policy->batchSize())
            ->get();

        foreach ($stale as $generation) {
            try {
                $this->pollGeneration->execute($generation, $now);
            } catch (Throwable $exception) {
                self::logFailedRow('poll', $generation, $exception);
            }
        }
    }

    private static function logFailedRow(string $step, Generation $generation, Throwable $exception): void
    {
        Log::error('The reconciler could not handle a generation; it carries on with the rest.', [
            'step' => $step,
            'generation_id' => $generation->id,
            'provider_id' => $generation->provider_id,
            'exception' => $exception::class,
        ]);
    }
}
