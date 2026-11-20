<?php

declare(strict_types=1);

namespace App\Domain\Generation\ValueObjects;

use App\Domain\Generation\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use LogicException;

/**
 * The scheduled reconciler's rules, pure and from config (PE-014): when a confirmed
 * generation is stale enough to poll, when an unsubmitted one is re-dispatched, and
 * when any generation it handled is checked again (D-50, D-52). Every instant is compared in whole seconds, as the rows store them.
 */
final readonly class ReconcilePolicy
{
    /**
     * A row waits a quarter of its age between checks, so 15 min at 1 h.
     */
    public const int AGE_DIVISOR = 4;

    public function __construct(
        #[Config('pixelforge.reconcile.queued_after_seconds')]
        private int $queuedAfterSeconds,
        #[Config('pixelforge.reconcile.processing_after_seconds')]
        private int $processingAfterSeconds,
        #[Config('pixelforge.reconcile.recheck_seconds')]
        private int $recheckSeconds,
        #[Config('pixelforge.reconcile.max_recheck_seconds')]
        private int $maxRecheckSeconds,
        #[Config('pixelforge.reconcile.batch_size')]
        private int $batchSize,
        #[Config('pixelforge.reconcile.submit_reserve')]
        private int $submitReserve,
        #[Config('pixelforge.reconcile.redispatch_after_seconds')]
        private int $redispatchAfterSeconds,
        #[Config('pixelforge.reconcile.give_up_after_seconds')]
        private int $giveUpAfterSeconds,
    ) {}

    /**
     * A row in the status is stale when its last provider update is strictly before
     * this instant: 2 min ago while `queued`, 3 min ago while `processing`.
     *
     * @throws LogicException for a status that is never polled
     */
    public function staleBefore(GenerationStatus $status, CarbonImmutable $now): CarbonImmutable
    {
        return $now->subSeconds(match ($status) {
            GenerationStatus::Queued => $this->queuedAfterSeconds,
            GenerationStatus::Processing => $this->processingAfterSeconds,
            default => throw new LogicException(sprintf('A %s generation is never polled.', $status->value)),
        });
    }

    /**
     * When a row checked now is next due: now + its age ÷ 4, clamped between the
     * recheck and the max recheck. A max below the recheck means the recheck.
     */
    public function recheckAt(CarbonImmutable $createdAt, CarbonImmutable $now): CarbonImmutable
    {
        $ageSeconds = max(0, $now->getTimestamp() - $createdAt->getTimestamp());
        $waitSeconds = min(intdiv($ageSeconds, self::AGE_DIVISOR), max($this->maxRecheckSeconds, $this->recheckSeconds));

        return $now->addSeconds(max($waitSeconds, $this->recheckSeconds));
    }

    /**
     * The most rows one run handles, across polls and re-dispatches (D-51).
     */
    public function batchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * A poll is sent only while more than the reserve is left in this minute's
     * budget, so new submits keep priority (D-51): 12 left give 2 polls.
     */
    public function allowsPoll(int $remainingUnits): bool
    {
        return $remainingUnits > $this->submitReserve;
    }

    /**
     * An unsubmitted row is re-dispatched once its last attempt, else its creation, is
     * strictly before this instant: 5 min ago by default (AC 12).
     */
    public function redispatchBefore(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subSeconds($this->redispatchAfterSeconds);
    }

    /**
     * An unconfirmed generation is given up once this long has passed since its
     * creation: 24 h by default, at the instant itself (D-49).
     */
    public function isPastGiveUp(CarbonImmutable $createdAt, CarbonImmutable $now): bool
    {
        return $now->greaterThanOrEqualTo($createdAt->addSeconds($this->giveUpAfterSeconds));
    }

    /**
     * A confirmed generation is never given up (D-49), but once Pixelforge has
     * answered `not_found` for longer than the give-up age, the operator is told.
     */
    public function hasBeenNotFoundTooLong(CarbonImmutable $notFoundSince, CarbonImmutable $now): bool
    {
        return $now->greaterThan($notFoundSince->addSeconds($this->giveUpAfterSeconds));
    }
}
