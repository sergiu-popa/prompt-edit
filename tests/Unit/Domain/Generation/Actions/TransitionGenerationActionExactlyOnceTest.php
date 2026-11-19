<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Actions;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\GenerationTransitionOutcome;
use App\Domain\Generation\Models\Generation;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\TestCase;

/**
 * A generation's money is written once: once it is terminal, no snapshot
 * changes its costs or its ledger.
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
