<?php

use App\Console\Commands\BackupDatabaseCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Before Hestia's 05:10 backup of the admin user, which carries the dump (D-22). Only where
 * DB_BACKUP_PATH is set, so local `schedule:work` and CI never run it. withoutOverlapping()
 * uses the database cache lock (D-20).
 */
Schedule::command(BackupDatabaseCommand::class)
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->when(fn (): bool => filled(config('database.backups.path')));
