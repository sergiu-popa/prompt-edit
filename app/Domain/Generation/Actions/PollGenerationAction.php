<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Billing\Exceptions\InvalidRateException;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Generation\Enums\GenerationTransitionOutcome;
use App\Domain\Generation\Exceptions\ConcurrentGenerationTransitionException;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\ValueObjects\ReconcilePolicy;
use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\Contracts\ReportsPixelforgeContext;
use App\Support\Pixelforge\Exceptions\NotFoundException;
use App\Support\Pixelforge\Exceptions\RateBudgetExhaustedException;
use App\Support\Pixelforge\Exceptions\RateLimitExceededException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Asks Pixelforge for one confirmed generation as it is now, and hands the answer
 * to the single transition action (D-06), so a poll settles exactly as a webhook
 * would, and exactly once however the two interleave. The next check is recorded
 * before the request (D-50), so a poll that fails still waits its turn. A failed
 * poll is never retried here: the cadence is the retry.
 */
final class PollGenerationAction
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly TransitionGenerationAction $transitionGeneration,
        private readonly ReconcilePolicy $policy,
    ) {}

    /**
     * @throws RateBudgetExhaustedException when the budget is spent, so the caller stops polling
     * @throws RateLimitExceededException when Pixelforge refuses for the rate, likewise
     * @throws ConcurrentGenerationTransitionException
     * @throws InvalidRateException when a succeeded snapshot cannot be priced exactly
     * @throws NanodollarOverflowException
     */
    public function execute(Generation $generation, CarbonImmutable $now): void
    {
        $providerId = $generation->provider_id ?? throw new LogicException('Only a generation Pixelforge confirmed is polled.');

        $generation->recordReconcileBookkeeping([
            'reconcile_recheck_at' => $this->policy->recheckAt($generation->created_at, $now),
        ]);

        try {
            $snapshot = $this->pixelforge->retrieveGeneration($providerId);
        } catch (RateBudgetExhaustedException|RateLimitExceededException $exception) {
            throw $exception;
        } catch (NotFoundException) {
            Log::warning('Pixelforge did not find a generation we polled; it is left unchanged.', [
                'generation_id' => $generation->id,
                'provider_id' => $providerId,
            ]);

            return;
        } catch (ReportsPixelforgeContext $exception) {
            Log::warning('Could not poll a generation; it waits for its next check.', [
                'generation_id' => $generation->id,
                'provider_id' => $providerId,
                ...$exception->context(),
            ]);

            return;
        }

        $outcome = $this->transitionGeneration->execute($generation, $snapshot);

        if ($outcome === GenerationTransitionOutcome::Applied) {
            Log::info('Applied a polled Pixelforge snapshot to a generation.', [
                'generation_id' => $generation->id,
                'provider_id' => $providerId,
                'status' => $snapshot->status->value,
                'outcome' => $outcome->value,
            ]);
        }
    }
}
