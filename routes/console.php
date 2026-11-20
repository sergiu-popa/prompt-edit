<?php

use App\Console\Commands\BackupDatabaseCommand;
use App\Console\Commands\ReconcileGenerationsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Before Hestia's 05:10 backup of the admin user, which carries the dump (D-22). Only where
 * DB_BACKUP_PATH is set, so local `schedule:work` and CI never run it. withoutOverlapping()
 * uses the database cache lock (D-20). Its expiry is set just above the command's 300 s
 * pg_dump timeout: the default of 24 hours would let a run killed mid-dump (a reboot, the
 * OOM killer) leave a lock that skips the next run.
 */
Schedule::command(BackupDatabaseCommand::class)
    ->dailyAt('04:30')
    ->withoutOverlapping(expiresAt: 10)
    ->when(fn (): bool => filled(config('database.backups.path')));

/*
 * The reconciler (PE-014, D-54): every minute in production, where Hestia's cron already runs
 * schedule:run every minute on the one server. Dev and E2E may name a sub-minute frequency
 * (PIXELFORGE_RECONCILE_EVERY). onOneServer() and withoutOverlapping() use the database cache
 * (D-20); sub-minute repeats inside one schedule:run reuse its memoized onOneServer mutex. The
 * lock expires after 15 minutes, past the worst run (20 polls × 35 s), so a killed run never
 * blocks the next for a day.
 */
ReconcileGenerationsCommand::scheduleEvery(
    Schedule::command(ReconcileGenerationsCommand::class),
    config()->integer('pixelforge.reconcile.every_seconds'),
)
    ->withoutOverlapping(expiresAt: 15)
    ->onOneServer();
