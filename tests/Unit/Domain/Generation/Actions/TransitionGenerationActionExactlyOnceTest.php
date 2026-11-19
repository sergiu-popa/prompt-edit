<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Actions;

use App\Domain\Generation\DataTransferObjects\LocalGenerationFailureData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\GenerationTransitionOutcome;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Models\Generation;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\TestCase;

/**
 * A generation's money is written once: once it is terminal, no snapshot
 * changes its costs or its ledger, and a settlement that fails part-way
 * leaves nothing behind.
 */
final class TransitionGenerationActionExactlyOnceTest extends TestCase
{
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use RefreshDatabase;

    public function test_a_succeeded_snapshot_applied_twice_settles_once(): void
    {
        $generation = $this->givenTheLighthouseGeneration();
        $this->transitionGeneration($generation, self::lighthouseSucceededSnapshotFor($generation));
        $generation = $this->reloadedGeneration($generation);

        $outcome = $this->assertWritesNothing(
            $generation,
            fn (): GenerationTransitionOutcome => $this->transitionGeneration($generation, self::lighthouseSucceededSnapshotFor($generation)),
        );

        $this->assertSame(GenerationTransitionOutcome::IgnoredDuplicate, $outcome);
        $this->assertSame(
            [['hold', -457_150_000], ['release', 457_150_000], ['charge', -381_887_500]],
            $this->ledgerEntriesFor($generation),
        );
        $this->assertSame(305_510_000, $generation->provider_cost_nanodollars);
        $this->assertSame(381_887_500, $generation->user_charge_nanodollars);
    }

    public function test_a_failed_snapshot_applied_twice_releases_once(): void
    {
        $generation = $this->givenTheLighthouseGeneration();
        $this->transitionGeneration($generation, self::snapshotFor($generation, GenerationStatus::Failed));
        $generation = $this->reloadedGeneration($generation);

        $outcome = $this->assertWritesNothing(
            $generation,
            fn (): GenerationTransitionOutcome => $this->transitionGeneration($generation, self::snapshotFor($generation, GenerationStatus::Failed)),
        );

        $this->assertSame(GenerationTransitionOutcome::IgnoredDuplicate, $outcome);
        $this->assertSame([['hold', -457_150_000], ['release', 457_150_000]], $this->ledgerEntriesFor($generation));
    }

    /**
     * AC13. A succeeded snapshot after a local failure is D-09's case, pinned
     * in the conflicts test.
     *
     * @return array<string, array{GenerationStatus, GenerationStatus, GenerationTransitionOutcome}>
     */
    public static function lateSnapshots(): array
    {
        return [
            'a late processing after succeeded' => [GenerationStatus::Succeeded, GenerationStatus::Processing, GenerationTransitionOutcome::IgnoredTerminal],
            'failed after succeeded' => [GenerationStatus::Succeeded, GenerationStatus::Failed, GenerationTransitionOutcome::IgnoredConflict],
            'succeeded after a provider failure' => [GenerationStatus::Failed, GenerationStatus::Succeeded, GenerationTransitionOutcome::IgnoredConflict],
        ];
    }

    #[DataProvider('lateSnapshots')]
    public function test_a_later_snapshot_never_changes_a_terminal_generations_money(
        GenerationStatus $terminal,
        GenerationStatus $late,
        GenerationTransitionOutcome $expected,
    ): void {
        $generation = $this->givenTheLighthouseGeneration();
        $this->transitionGeneration($generation, $this->terminalSnapshotFor($generation, $terminal));
        $generation = $this->reloadedGeneration($generation);
        $settled = $this->ledgerEntriesFor($generation);

        $outcome = $this->assertWritesNothing(
            $generation,
            fn (): GenerationTransitionOutcome => $this->transitionGeneration($generation, $this->terminalSnapshotFor($generation, $late)),
        );

        $this->assertSame($expected, $outcome);
        $this->assertSame($settled, $this->ledgerEntriesFor($generation));
    }

    /**
     * AC17: each terminal change, with the ledger insert that fails under it.
     *
     * @return array<string, array{GenerationStatus|null, string}>
     */
    public static function failingSettlementWrites(): array
    {
        return [
            'succeeded, the charge insert fails' => [GenerationStatus::Succeeded, 'charge'],
            'succeeded, the release insert fails' => [GenerationStatus::Succeeded, 'release'],
            'failed, the release insert fails' => [GenerationStatus::Failed, 'release'],
            'a local failure, the release insert fails' => [null, 'release'],
        ];
    }

    /**
     * `null` stands for a local failure.
     */
    #[DataProvider('failingSettlementWrites')]
    public function test_a_failing_settlement_write_rolls_the_whole_transition_back(?GenerationStatus $terminal, string $failingType): void
    {
        $generation = $this->givenTheLighthouseGeneration();
        $change = $terminal === null
            ? new LocalGenerationFailureData(LocalFailureReason::ContentPolicyViolation, null, null, null)
            : $this->terminalSnapshotFor($generation, $terminal);
        $before = $this->rawGenerationRow($generation);
        $this->failTheLedgerInsertOf($failingType);

        try {
            $this->transitionGeneration($generation, $change);
            $this->fail('The settlement write did not fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(sprintf('The %s insert failed.', $failingType), $exception->getMessage());
        }

        $this->assertSame($before, $this->rawGenerationRow($generation));
        $this->assertSame('pending', $before['status']);
        $this->assertNull($before['provider_cost_nanodollars']);
        $this->assertNull($before['user_charge_nanodollars']);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($generation));
    }

    /**
     * Throws once, right after the ledger insert of that type has run, so the
     * row was already written inside the transaction.
     */
    private function failTheLedgerInsertOf(string $type): void
    {
        $failed = false;

        DB::listen(function (QueryExecuted $query) use ($type, &$failed): void {
            if ($failed || ! str_starts_with($query->sql, 'insert into "credit_ledger_entries"') || ! in_array($type, $query->bindings, true)) {
                return;
            }

            $failed = true;

            throw new RuntimeException(sprintf('The %s insert failed.', $type));
        });
    }

    private function terminalSnapshotFor(Generation $generation, GenerationStatus $status): GenerationSnapshot
    {
        return $status === GenerationStatus::Succeeded
            ? self::lighthouseSucceededSnapshotFor($generation)
            : self::snapshotFor($generation, $status);
    }

    /**
     * Runs the transition a minute later and asserts the row and its ledger
     * are exactly as before, down to `updated_at`.
     *
     * @param  callable(): GenerationTransitionOutcome  $transition
     */
    private function assertWritesNothing(Generation $generation, callable $transition): GenerationTransitionOutcome
    {
        $row = $this->rawGenerationRow($generation);
        $ledger = $this->ledgerEntriesFor($generation);
        $this->travel(1)->minutes();

        $outcome = $transition();

        $this->assertSame($row, $this->rawGenerationRow($generation));
        $this->assertSame($ledger, $this->ledgerEntriesFor($generation));

        return $outcome;
    }
}
