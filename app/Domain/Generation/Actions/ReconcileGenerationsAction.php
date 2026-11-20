<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\ValueObjects\ReconcilePolicy;
use App\Support\Pixelforge\Exceptions\RateBudgetExhaustedException;
use App\Support\Pixelforge\Exceptions\RateLimitExceededException;
use App\Support\Pixelforge\PixelforgeRequestBudget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One run of the scheduled reconciler (PE-014): it catches up generations whose
 * webhooks never arrived. Confirmed generations with no news for a while are polled,
 * oldest last provider update first, up to the batch, and only while more than the
 * reserve of D-44's budget is left for submits (D-51). A spent budget or Pixelforge's
 * 429 stops the run's polls. Generations Pixelforge never confirmed, whose submit job
 * was handed over, stopped or died, then take the rest of the batch, oldest created
 * first: each is re-dispatched (D-52), or given up as `lost` once it is a day old
 * (D-49). Every row is isolated: an exception on one is
 * logged with its ULID, and the run goes on.
 */
final class ReconcileGenerationsAction
{
    public function __construct(
        private readonly PollGenerationAction $pollGeneration,
        private readonly RedispatchGenerationAction $redispatchGeneration,
        private readonly GiveUpUnconfirmedGenerationAction $giveUpUnconfirmedGeneration,
        private readonly ReconcilePolicy $policy,
        private readonly PixelforgeRequestBudget $budget,
    ) {}

    /**
     * Now is read once, in whole seconds, as the rows store their instants.
     */
    public function execute(): void
    {
        $now = CarbonImmutable::now()->startOfSecond();

        $polled = $this->pollStaleGenerations($now);
        $this->handleUnsubmittedGenerations($now, $this->policy->batchSize() - $polled);
    }

    /**
     * @return int how many rows it handled, whatever their outcome
     */
    private function pollStaleGenerations(CarbonImmutable $now): int
    {
        $handled = 0;

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
            $remainingUnits = $this->budget->remaining();

            if (! $this->policy->allowsPoll($remainingUnits)) {
                Log::info('The reconciler left the rest of this minute\'s budget to submits.', ['remaining_units' => $remainingUnits]);

                return $handled;
            }

            $handled++;

            try {
                $this->pollGeneration->execute($generation, $now);
            } catch (RateBudgetExhaustedException|RateLimitExceededException $exception) {
                Log::warning('The rate limit stopped this run\'s polls; the rest wait for the next run.', [
                    'generation_id' => $generation->id,
                    'provider_id' => $generation->provider_id,
                    ...$exception->context(),
                ]);

                return $handled;
            } catch (Throwable $exception) {
                self::logFailedRow('poll', $generation, $exception);
            }
        }

        return $handled;
    }

    /**
     * Re-dispatches never take a budget unit here: the job takes one when it runs. A
     * give-up can come up to one recheck late, when the row is next due.
     */
    private function handleUnsubmittedGenerations(CarbonImmutable $now, int $limit): void
    {
        if ($limit < 1) {
            return;
        }

        $unsubmitted = Generation::query()
            ->awaitingRedispatch($this->policy->redispatchBefore($now))
            ->dueForRecheck($now)
            ->oldestCreatedFirst()
            ->limit($limit)
            ->get();

        foreach ($unsubmitted as $generation) {
            $isPastGiveUp = $this->policy->isPastGiveUp($generation->created_at, $now);

            try {
                if ($isPastGiveUp) {
                    $this->giveUpUnconfirmedGeneration->execute($generation);
                } else {
                    $this->redispatchGeneration->execute($generation, $now);
                }
            } catch (Throwable $exception) {
                self::logFailedRow($isPastGiveUp ? 'give_up' : 'redispatch', $generation, $exception);
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
