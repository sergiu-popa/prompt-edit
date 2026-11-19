<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Actions;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\TestCase;

/**
 * Two processes, each on its own database connection, apply terminal
 * snapshots to one row at once. The children must see committed rows, so this
 * file truncates instead of wrapping each test in a transaction.
 */
final class TransitionGenerationActionConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use InteractsWithCreditLedger;
    use InteractsWithGenerationSnapshots;

    private const int LOCK_WAIT_DEADLINE_SECONDS = 15;

    private const int CHILD_TIMEOUT_SECONDS = 30;

    protected function setUp(): void
    {
        parent::setUp();

        // Committed rows must not leak into the RefreshDatabase tests that run after this one.
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('truncate table generations, users restart identity cascade');
        });
    }

    public function test_two_simultaneous_appliers_of_one_snapshot_write_the_row_once(): void
    {
        $generation = Generation::factory()->queued()->create();
        $object = json_encode(self::generationObjectFor($generation, GenerationStatus::Succeeded), JSON_THROW_ON_ERROR);

        DB::beginTransaction();

        try {
            Generation::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();

            $children = [$this->startChild($generation->id, $object), $this->startChild($generation->id, $object)];

            $bothWaiting = $this->waitUntilBackendsWaitOnALock(2);
        } finally {
            DB::commit();
        }

        $results = array_map(fn (InvokedProcess $child): array => $this->resultOf($child), $children);

        $this->assertTrue($bothWaiting, 'Both children did not wait on the row lock in time.');

        usort($results, fn (array $a, array $b): int => strcmp((string) $a['outcome'], (string) $b['outcome']));
        $this->assertSame(
            [
                ['outcome' => 'applied', 'generation_updates' => 1],
                ['outcome' => 'ignored_duplicate', 'generation_updates' => 0],
            ],
            $results,
        );

        $row = Generation::query()->whereKey($generation->id)->firstOrFail();
        $this->assertSame(GenerationStatus::Succeeded, $row->status);
        $this->assertSame(1_650, $row->output_tokens);
        $this->assertNotNull($row->completed_at);
        $this->assertSame('2026-11-18T23:59:59Z', $row->completed_at->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame(
            [['hold', -123_387_500], ['release', 123_387_500], ['charge', -109_312_500]],
            $this->ledgerEntriesFor($row),
        );
        $this->assertSame(-109_312_500, $this->ledgerNetFor($row));
    }

    /**
     * Whichever wins the lock decides the one terminal outcome; a failed
     * generation is never charged.
     */
    public function test_a_succeeded_and_a_failed_snapshot_at_once_settle_exactly_one_outcome(): void
    {
        $generation = Generation::factory()->queued()->create();
        $succeeded = json_encode(self::generationObjectFor($generation, GenerationStatus::Succeeded), JSON_THROW_ON_ERROR);
        $failed = json_encode(self::generationObjectFor($generation, GenerationStatus::Failed), JSON_THROW_ON_ERROR);

        DB::beginTransaction();

        try {
            Generation::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();

            $children = [$this->startChild($generation->id, $succeeded), $this->startChild($generation->id, $failed)];

            $bothWaiting = $this->waitUntilBackendsWaitOnALock(2);
        } finally {
            DB::commit();
        }

        $outcomes = array_map(fn (InvokedProcess $child): mixed => $this->resultOf($child)['outcome'], $children);

        $this->assertTrue($bothWaiting, 'Both children did not wait on the row lock in time.');

        sort($outcomes);
        $this->assertSame(['applied', 'ignored_conflict'], $outcomes);

        $row = Generation::query()->whereKey($generation->id)->firstOrFail();
        $released = [['hold', -123_387_500], ['release', 123_387_500]];

        if ($row->status === GenerationStatus::Succeeded) {
            $this->assertSame([...$released, ['charge', -109_312_500]], $this->ledgerEntriesFor($row));
            $this->assertSame([87_450_000, 109_312_500], [$row->provider_cost_nanodollars, $row->user_charge_nanodollars]);

            return;
        }

        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame($released, $this->ledgerEntriesFor($row));
        $this->assertSame([0, 0], [$row->provider_cost_nanodollars, $row->user_charge_nanodollars]);
    }

    private function startChild(string $generationId, string $object): InvokedProcess
    {
        /** @var array{host: string, port: int|string, database: string, username: string, password: string} $database */
        $database = config('database.connections.pgsql');

        return Process::path(base_path())
            ->timeout(self::CHILD_TIMEOUT_SECONDS)
            ->env([
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => $database['host'],
                'DB_PORT' => (string) $database['port'],
                'DB_DATABASE' => $database['database'],
                'DB_USERNAME' => $database['username'],
                'DB_PASSWORD' => $database['password'],
            ])
            ->start([PHP_BINARY, 'tests/scripts/transition-generation.php', $generationId, $object]);
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
