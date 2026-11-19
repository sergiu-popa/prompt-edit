<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/**
 * The nightly `pg_dump` (D-22): seven rotating weekday files in `DB_BACKUP_PATH`, the
 * password only in the process env, and last week's copy kept when a dump fails.
 */
final class BackupDatabaseCommandTest extends TestCase
{
    private const string PASSWORD = 'Backup-Pw-9f3e1c';

    private const string WEDNESDAY = '2026-11-18 04:30:00';

    private string $root;

    private string $backupPath;

    /**
     * @var list<MessageLogged>
     */
    private array $loggedMessages = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/pe-004-backup-' . Str::random(8);
        $this->backupPath = $this->root . '/private/backups';

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => 'db.internal',
            'database.connections.pgsql.port' => '5433',
            'database.connections.pgsql.database' => 'prompt_edit',
            'database.connections.pgsql.username' => 'prompt_edit',
            'database.connections.pgsql.password' => self::PASSWORD,
        ]);

        Event::listen(MessageLogged::class, function (MessageLogged $message): void {
            $this->loggedMessages[] = $message;
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_refuses_to_run_without_a_backup_path(): void
    {
        Process::fake();
        config(['database.backups.path' => null]);

        $this->backup()
            ->expectsOutputToContain('Set DB_BACKUP_PATH')
            ->assertExitCode(1);

        Process::assertNothingRan();
    }

    public function test_it_dumps_the_database_into_the_weekday_file(): void
    {
        $this->givenSuccessfulDumps();
        $this->travelTo(Carbon::parse(self::WEDNESDAY));

        $this->backup()->assertExitCode(0);

        $target = $this->backupPath . '/prompt_edit-wednesday.dump';

        Process::assertRan(function (PendingProcess $process) use ($target): bool {
            $this->assertSame([
                'pg_dump',
                '--format=custom',
                '--no-password',
                '--host=db.internal',
                '--port=5433',
                '--username=prompt_edit',
                "--file={$target}.partial",
                'prompt_edit',
            ], $process->command);
            $this->assertSame(self::PASSWORD, $process->environment['PGPASSWORD'] ?? null);
            $this->assertSame(300, $process->timeout);

            return true;
        });

        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->backupPath)), -4));
        $this->assertFileExists($target);
        $this->assertFileDoesNotExist("{$target}.partial");

        $this->assertCount(1, $this->loggedMessages);
        $this->assertSame('info', $this->loggedMessages[0]->level);
        $this->assertSame($target, $this->loggedMessages[0]->context['path'] ?? null);
        $this->assertPasswordNeverLogged();
    }

    public function test_eight_daily_runs_keep_one_file_per_weekday(): void
    {
        $this->givenSuccessfulDumps();
        $this->travelTo(Carbon::parse(self::WEDNESDAY));

        foreach (range(1, 8) as $day) {
            $this->backup()->assertExitCode(0);
            $this->travel(1)->day();
        }

        $files = array_map(fn (SplFileInfo $file): string => $file->getFilename(), File::files($this->backupPath));
        sort($files);

        $this->assertSame([
            'prompt_edit-friday.dump',
            'prompt_edit-monday.dump',
            'prompt_edit-saturday.dump',
            'prompt_edit-sunday.dump',
            'prompt_edit-thursday.dump',
            'prompt_edit-tuesday.dump',
            'prompt_edit-wednesday.dump',
        ], $files);
    }

    public function test_a_failed_dump_keeps_last_weeks_copy(): void
    {
        config(['database.backups.path' => $this->backupPath]);
        File::ensureDirectoryExists($this->backupPath, 0700);
        $lastWeek = $this->backupPath . '/prompt_edit-wednesday.dump';
        File::put($lastWeek, 'last week');

        Process::fake(function (PendingProcess $process) {
            File::put(self::fileOption($process), 'half a dump');

            return Process::result(errorOutput: 'pg_dump: error: connection to server failed', exitCode: 1);
        });
        $this->travelTo(Carbon::parse(self::WEDNESDAY));

        $this->backup()->assertExitCode(1);

        $this->assertSame('last week', File::get($lastWeek));
        $this->assertFileDoesNotExist("{$lastWeek}.partial");

        $this->assertCount(1, $this->loggedMessages);
        $this->assertSame('error', $this->loggedMessages[0]->level);
        $this->assertSame(1, $this->loggedMessages[0]->context['exit_code'] ?? null);
        $this->assertSame('pg_dump: error: connection to server failed', $this->loggedMessages[0]->context['error_output'] ?? null);
        $this->assertPasswordNeverLogged();
    }

    public function test_a_timed_out_dump_keeps_last_weeks_copy(): void
    {
        config(['database.backups.path' => $this->backupPath]);
        File::ensureDirectoryExists($this->backupPath, 0700);
        $lastWeek = $this->backupPath . '/prompt_edit-wednesday.dump';
        File::put($lastWeek, 'last week');

        Process::fake(function (PendingProcess $process): never {
            File::put(self::fileOption($process), 'half a dump');

            throw new ProcessTimedOutException(new SymfonyProcess(['pg_dump']), ProcessTimedOutException::TYPE_GENERAL);
        });
        $this->travelTo(Carbon::parse(self::WEDNESDAY));

        $this->backup()
            ->expectsOutputToContain('pg_dump took longer than 300 seconds')
            ->assertExitCode(1);

        $this->assertSame('last week', File::get($lastWeek));
        $this->assertFileDoesNotExist("{$lastWeek}.partial");

        $this->assertCount(1, $this->loggedMessages);
        $this->assertSame('error', $this->loggedMessages[0]->level);
        $this->assertSame(300, $this->loggedMessages[0]->context['timeout_seconds'] ?? null);
        $this->assertPasswordNeverLogged();
    }

    /**
     * `withoutOverlapping()` takes the database cache lock (D-20), the pattern later every-minute
     * schedules copy. A lock left by a killed run must not outlive the next due run.
     */
    public function test_the_schedule_runs_it_at_0430_without_overlapping_only_where_a_backup_path_is_set(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->sole(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'app:backup-database'));

        $this->assertSame('30 4 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt, 'The lock expires in minutes, just past the 300 s pg_dump timeout.');

        config(['database.backups.path' => null]);
        $this->assertFalse($event->filtersPass($this->app));

        config(['database.backups.path' => $this->backupPath]);
        $this->assertTrue($event->filtersPass($this->app));
    }

    private function backup(): PendingCommand
    {
        $command = $this->artisan('app:backup-database');
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function givenSuccessfulDumps(): void
    {
        config(['database.backups.path' => $this->backupPath]);

        Process::fake(function (PendingProcess $process) {
            File::put(self::fileOption($process), 'PGDMP');

            return Process::result();
        });
    }

    private static function fileOption(PendingProcess $process): string
    {
        $command = is_array($process->command) ? $process->command : [];

        foreach ($command as $argument) {
            if (str_starts_with($argument, '--file=')) {
                return substr($argument, strlen('--file='));
            }
        }

        self::fail('pg_dump was run without --file.');
    }

    private function assertPasswordNeverLogged(): void
    {
        foreach ($this->loggedMessages as $logged) {
            $this->assertStringNotContainsString(self::PASSWORD, $logged->message);
            $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode($logged->context));
        }
    }
}
