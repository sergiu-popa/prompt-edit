<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Child processes on their own database connections, and a parent that holds
 * a lock until it sees them all wait on it. The children must see committed
 * rows, so a test using this truncates instead of wrapping in a transaction.
 *
 * @phpstan-require-extends TestCase
 */
trait WaitsForDatabaseLocks
{
    private const int LOCK_WAIT_DEADLINE_SECONDS = 15;

    private const int CHILD_TIMEOUT_SECONDS = 30;

    /**
     * The test database, for a child process's environment.
     *
     * @return array<string, string>
     */
    private function childDatabaseEnvironment(): array
    {
        /** @var array{host: string, port: int|string, database: string, username: string, password: string} $database */
        $database = config('database.connections.pgsql');

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => $database['database'],
            'DB_USERNAME' => $database['username'],
            'DB_PASSWORD' => $database['password'],
        ];
    }

    /**
     * Runs inside the lock-holding transaction, where Postgres keeps one
     * snapshot of pg_stat_activity from its first read, so each poll clears it.
     */
    private function waitUntilBackendsWaitOnALock(int $backends): bool
    {
        $deadline = microtime(true) + self::LOCK_WAIT_DEADLINE_SECONDS;

        while (microtime(true) < $deadline) {
            DB::statement('select pg_stat_clear_snapshot()');
            $waiting = (int) DB::scalar(
                "select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and pid <> pg_backend_pid()",
            );

            if ($waiting >= $backends) {
                return true;
            }

            usleep(50_000);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function resultOf(InvokedProcess $child): array
    {
        $result = $child->wait();

        $this->assertTrue($result->successful(), sprintf('A child failed: %s', $result->errorOutput()));

        $output = json_decode($result->output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($output);

        /** @var array<string, mixed> $output */
        return $output;
    }
}
