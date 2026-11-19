<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Actions;

use App\Domain\Billing\Enums\CreditLedgerEntryType;
use App\Domain\Billing\Exceptions\InvalidRateException;
use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Domain\Generation\DataTransferObjects\LocalGenerationFailureData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\GenerationTransitionOutcome;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Models\Generation;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use App\Support\Pixelforge\Exceptions\InvalidGenerationSnapshotException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CapturesLogMessages;
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
    use CapturesLogMessages;
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
     * AC15, the backstop for a bypassed status guard: the unique index ignores
     * the duplicates without aborting the transaction, and each one is logged.
     */
    public function test_duplicates_left_by_a_bypassed_status_guard_are_ignored_and_logged(): void
    {
        $generation = $this->givenTheLighthouseGeneration();

        foreach ([[CreditLedgerEntryType::Release, 457_150_000], [CreditLedgerEntryType::Charge, -381_887_500]] as [$type, $amount]) {
            CreditLedgerEntry::query()->create([
                'user_id' => $generation->user_id,
                'generation_id' => $generation->id,
                'type' => $type,
                'amount_nanodollars' => $amount,
            ]);
        }

        $outcome = $this->transitionGeneration($generation, self::lighthouseSucceededSnapshotFor($generation));

        $this->assertSame(GenerationTransitionOutcome::Applied, $outcome);
        $this->assertSame(1, DB::scalar('select 1'));
        $this->assertSame(GenerationStatus::Succeeded, $this->reloadedGeneration($generation)->status);
        $this->assertSame(
            [['hold', -457_150_000], ['release', 457_150_000], ['charge', -381_887_500]],
            $this->ledgerEntriesFor($generation),
        );

        $logged = $this->loggedMessages();
        $this->assertCount(2, $logged);

        foreach (['release', 'charge'] as $index => $type) {
            $this->assertSame('error', $logged[$index]->level);
            $this->assertSame('Ignored a duplicate credit ledger entry: its generation already has one of this type.', $logged[$index]->message);
            $this->assertSame(['generation_id' => $generation->id, 'type' => $type], $logged[$index]->context);
        }
    }

    /**
     * AC18: 5.0001 USD per 1M tokens is 5,000.1 n$ per token, which is never
     * rounded. The generation stays pending with only its hold.
     */
    public function test_a_rate_that_is_not_a_whole_nanodollar_is_refused_logged_and_writes_nothing(): void
    {
        $generation = $this->givenTheLighthouseGeneration();
        $snapshot = self::snapshotFor($generation, GenerationStatus::Succeeded, [
            'usage' => ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6_450, 'reasoning_tokens' => 3_184],
            'rates' => ['input_text_tokens' => '5.0001', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        ]);
        $before = $this->rawGenerationRow($generation);

        try {
            $this->transitionGeneration($generation, $snapshot);
            $this->fail('A rate that is not a whole nanodollar was priced.');
        } catch (InvalidRateException $exception) {
            $this->assertSame('Rate input_text_tokens "5.0001" is not a whole number of nanodollars per token.', $exception->getMessage());
        }

        $this->assertSame($before, $this->rawGenerationRow($generation));
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($generation));

        $logged = $this->loggedMessages();
        $this->assertCount(1, $logged);
        $this->assertSame('error', $logged[0]->level);
        $this->assertSame('Refused a succeeded Pixelforge snapshot that cannot be priced exactly.', $logged[0]->message);
        $this->assertSame(
            ['generation_id' => $generation->id, 'provider_id' => $snapshot->id, 'error' => $exception->getMessage()],
            $logged[0]->context,
        );
    }

    /**
     * AC18's missing usage is refused at the Pixelforge boundary, so it never
     * reaches settlement; logging the object's generation id is the job of
     * the callers that parse a succeeded object (PE-008, PE-014).
     */
    public function test_a_succeeded_object_without_usage_is_refused_before_it_reaches_the_generation(): void
    {
        $generation = $this->givenTheLighthouseGeneration();
        $before = $this->rawGenerationRow($generation);

        try {
            GenerationSnapshot::from(self::generationObjectFor($generation, GenerationStatus::Succeeded, ['usage' => null]));
            $this->fail('A succeeded object without usage was parsed.');
        } catch (InvalidGenerationSnapshotException $exception) {
            $this->assertStringContainsString('usage', $exception->getMessage());
        }

        $this->assertSame($before, $this->rawGenerationRow($generation));
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
