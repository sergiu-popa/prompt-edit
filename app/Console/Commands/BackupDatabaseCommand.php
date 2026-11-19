<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The nightly database backup (D-22). It writes `<database>-<weekday>.dump` with `pg_dump`
 * into `DB_BACKUP_PATH`, so seven files rotate. The dump goes to a `.partial` file first, so a
 * failed or timed-out run never replaces last week's copy. The password reaches `pg_dump` only through
 * `PGPASSWORD`: it is never on the command line, in the output or in a log.
 */
#[Signature('app:backup-database')]
#[Description('Dump the database with pg_dump into DB_BACKUP_PATH, one file per weekday')]
final class BackupDatabaseCommand extends Command
{
    private const int TIMEOUT_SECONDS = 300;

    public function handle(): int
    {
        $directory = config('database.backups.path');

        if (! is_string($directory) || trim($directory) === '') {
            $this->components->error('Set DB_BACKUP_PATH to the backup directory first.');

            return self::FAILURE;
        }

        $connection = config()->array('database.connections.' . config()->string('database.default'));
        $database = (string) $connection['database'];

        File::ensureDirectoryExists($directory, 0700);

        $target = rtrim($directory, '/') . "/{$database}-" . Str::lower(now()->format('l')) . '.dump';
        $partial = "{$target}.partial";

        try {
            $result = Process::timeout(self::TIMEOUT_SECONDS)
                ->env(['PGPASSWORD' => (string) $connection['password']])
                ->run([
                    'pg_dump',
                    '--format=custom',
                    '--no-password',
                    '--host=' . $connection['host'],
                    '--port=' . $connection['port'],
                    '--username=' . $connection['username'],
                    "--file={$partial}",
                    $database,
                ]);
        } catch (ProcessTimedOutException) {
            File::delete($partial);

            Log::error('Database backup timed out', ['timeout_seconds' => self::TIMEOUT_SECONDS]);
            $this->components->error('pg_dump took longer than ' . self::TIMEOUT_SECONDS . ' seconds; the previous backups are untouched.');

            return self::FAILURE;
        }

        if ($result->failed()) {
            File::delete($partial);

            Log::error('Database backup failed', [
                'exit_code' => $result->exitCode(),
                'error_output' => trim($result->errorOutput()),
            ]);
            $this->components->error("pg_dump failed with exit code {$result->exitCode()}; the previous backups are untouched.");

            return self::FAILURE;
        }

        File::move($partial, $target);

        Log::info('Database backed up', ['path' => $target, 'bytes' => File::size($target)]);
        $this->components->info("Backed up {$database} to {$target}.");

        return self::SUCCESS;
    }
}
