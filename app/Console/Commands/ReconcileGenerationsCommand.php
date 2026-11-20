<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Generation\Actions\ReconcileGenerationsAction;
use App\Support\Health\ReconcilerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;

/**
 * One run of the reconciler (PE-014), as the scheduler starts it every minute on
 * one server (D-54). A row that throws is logged inside the run, so the run itself
 * succeeds, and its end is recorded as the heartbeat `/health/reconciler` reads
 * (D-59). A run that throws as a whole records nothing.
 */
#[Signature('app:reconcile-generations')]
#[Description('Catch up generations whose Pixelforge webhooks never arrived')]
final class ReconcileGenerationsCommand extends Command
{
    public function handle(ReconcileGenerationsAction $reconcileGenerations, ReconcilerHeartbeat $heartbeat): int
    {
        $reconcileGenerations->execute();

        $heartbeat->recordSuccessfulRun(CarbonImmutable::now()->startOfSecond());

        return self::SUCCESS;
    }

    /**
     * One of Laravel's sub-minute frequencies for 1, 2, 5, 10, 15, 20 or 30 seconds
     * (dev and E2E), else every minute (production).
     */
    public static function scheduleEvery(Event $event, int $seconds): Event
    {
        return match ($seconds) {
            1 => $event->everySecond(),
            2 => $event->everyTwoSeconds(),
            5 => $event->everyFiveSeconds(),
            10 => $event->everyTenSeconds(),
            15 => $event->everyFifteenSeconds(),
            20 => $event->everyTwentySeconds(),
            30 => $event->everyThirtySeconds(),
            default => $event->everyMinute(),
        };
    }
}
