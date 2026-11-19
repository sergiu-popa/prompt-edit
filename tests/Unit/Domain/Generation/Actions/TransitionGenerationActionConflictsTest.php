<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Actions;

use App\Domain\Generation\DataTransferObjects\LocalGenerationFailureData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\GenerationTransitionOutcome;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\TestCase;

/**
 * A snapshot is applied only to the generation it belongs to, and every
 * conflict is logged at error level for the operator.
 */
final class TransitionGenerationActionConflictsTest extends TestCase
{
    use CapturesLogMessages;
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use RefreshDatabase;

    private const string MISSING_GENERATION_ID = 'missing generation id';

    private const string OTHER_GENERATION_ID = 'another generation\'s id';

    private const string OTHER_PROVIDER_ID = 'another provider id';

    private const string FOREIGN_PROVIDER_ID = 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K';

    /**
     * @return array<string, array{GenerationStatus, string}>
     */
    public static function identityConflicts(): array
    {
        $conflicts = [];

        foreach (GenerationStatus::cases() as $status) {
            foreach ([self::MISSING_GENERATION_ID, self::OTHER_GENERATION_ID, self::OTHER_PROVIDER_ID] as $cause) {
                if ($status === GenerationStatus::Pending && $cause === self::OTHER_PROVIDER_ID) {
                    continue;
                }

                $conflicts[sprintf('%s row, %s', $status->value, $cause)] = [$status, $cause];
            }
        }

        return $conflicts;
    }

    #[DataProvider('identityConflicts')]
    public function test_a_snapshot_of_another_generation_is_refused_and_logged(GenerationStatus $rowStatus, string $cause): void
    {
        $generation = $this->givenAGenerationIn($rowStatus);
        $incomingStatus = $rowStatus->isTerminal() ? GenerationStatus::Processing : GenerationStatus::Succeeded;
        $snapshot = self::snapshotFor($generation, $incomingStatus, match ($cause) {
            self::MISSING_GENERATION_ID => ['metadata' => ['order_id' => '1042']],
            self::OTHER_GENERATION_ID => ['metadata' => ['generation_id' => Generation::factory()->create()->id]],
            default => ['id' => self::FOREIGN_PROVIDER_ID],
        });
        $before = $this->rawGenerationRow($generation);
        $this->travel(1)->minutes();

        $outcome = $this->transitionGeneration($generation, $snapshot);

        $this->assertSame(GenerationTransitionOutcome::RefusedConflict, $outcome);
        $this->assertSame($before, $this->rawGenerationRow($generation));
        $this->assertConflictLogged(
            match ($cause) {
                self::MISSING_GENERATION_ID => 'Refused a Pixelforge snapshot without our generation id.',
                self::OTHER_GENERATION_ID => 'Refused a Pixelforge snapshot of another generation.',
                default => 'Refused a Pixelforge snapshot whose id differs from the stored provider id.',
            },
            [
                'generation_id' => $generation->id,
                'status' => $rowStatus->value,
                'provider_id' => $generation->provider_id,
                'incoming_status' => $incomingStatus->value,
                'incoming_provider_id' => $snapshot->id,
                'outcome' => 'refused_conflict',
            ],
        );
    }

    /**
     * @return array<string, array{GenerationStatus, GenerationStatus}>
     */
    public static function oppositeTerminalStatuses(): array
    {
        return [
            'succeeded row, failed snapshot' => [GenerationStatus::Succeeded, GenerationStatus::Failed],
            'failed row, succeeded snapshot' => [GenerationStatus::Failed, GenerationStatus::Succeeded],
        ];
    }

    #[DataProvider('oppositeTerminalStatuses')]
    public function test_the_opposite_terminal_status_is_ignored_and_logged(GenerationStatus $rowStatus, GenerationStatus $incomingStatus): void
    {
        $generation = $this->givenAGenerationIn($rowStatus);
        $snapshot = self::snapshotFor($generation, $incomingStatus);

        $this->assertSame(GenerationTransitionOutcome::IgnoredConflict, $this->transitionGeneration($generation, $snapshot));

        $this->assertConflictLogged(
            'Ignored a Pixelforge snapshot whose terminal status contradicts the generation\'s.',
            [
                'generation_id' => $generation->id,
                'status' => $rowStatus->value,
                'provider_id' => $generation->provider_id,
                'incoming_status' => $incomingStatus->value,
                'incoming_provider_id' => $snapshot->id,
                'outcome' => 'ignored_conflict',
            ],
        );
    }

    /**
     * D-09: a 409 made us fail the row locally, but the generation Pixelforge
     * already held under our key went on to succeed. Pixelforge bills it, so
     * its cost is recorded for the operator report, but the user is not
     * charged.
     */
    public function test_a_succeeded_snapshot_over_a_locally_failed_row_records_the_provider_cost_and_is_logged(): void
    {
        $generation = Generation::factory()->failedLocally()->create();
        $snapshot = self::snapshotFor($generation, GenerationStatus::Succeeded);

        $this->assertSame(GenerationTransitionOutcome::IgnoredConflict, $this->transitionGeneration($generation, $snapshot));

        $row = $this->reloadedGeneration($generation);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(87_450_000, $row->provider_cost_nanodollars);
        $this->assertSame(0, $row->user_charge_nanodollars);
        $this->assertConflictLogged(
            'Ignored a Pixelforge snapshot whose terminal status contradicts the generation\'s.',
            [
                'generation_id' => $generation->id,
                'status' => 'failed',
                'provider_id' => null,
                'incoming_status' => 'succeeded',
                'incoming_provider_id' => $snapshot->id,
                'outcome' => 'ignored_conflict',
            ],
        );
    }

    /**
     * AC20, on the docs' example: the provider cost, usage, rates and
     * completion are kept to match `/v1/usage`, and the net stays 0.
     */
    public function test_the_lighthouse_succeeding_after_a_local_failure_records_its_cost_but_charges_nothing(): void
    {
        $generation = $this->givenTheLighthouseGeneration();
        $this->transitionGeneration($generation, new LocalGenerationFailureData(LocalFailureReason::ContentPolicyViolation, null, null, null));
        $snapshot = self::lighthouseSucceededSnapshotFor($generation);

        $outcome = $this->transitionGeneration($generation, $snapshot);

        $row = $this->reloadedGeneration($generation);
        $this->assertSame(GenerationTransitionOutcome::IgnoredConflict, $outcome);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(305_510_000, $row->provider_cost_nanodollars);
        $this->assertSame(0, $row->user_charge_nanodollars);
        $this->assertSame(
            [12, 765, 6_450, 3_184],
            [$row->input_text_tokens, $row->input_image_tokens, $row->output_tokens, $row->reasoning_tokens],
        );
        $this->assertSame(
            ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            $row->rates,
        );
        $this->assertSameInstant('2026-11-18T23:59:59Z', $row->completed_at);
        $this->assertSame([['hold', -457_150_000], ['release', 457_150_000]], $this->ledgerEntriesFor($generation));
        $this->assertSame(0, $this->ledgerNetFor($generation));

        $logged = $this->loggedMessages();
        $this->assertCount(1, $logged);
        $this->assertSame($generation->id, $logged[0]->context['generation_id']);
        $this->assertSame($snapshot->id, $logged[0]->context['incoming_provider_id']);

        $before = $this->rawGenerationRow($generation);
        $this->travel(1)->minutes();

        $this->assertSame(GenerationTransitionOutcome::IgnoredConflict, $this->transitionGeneration($generation, self::lighthouseSucceededSnapshotFor($row)));

        $this->assertSame($before, $this->rawGenerationRow($generation));
        $this->assertSame([['hold', -457_150_000], ['release', 457_150_000]], $this->ledgerEntriesFor($generation));
    }

    /**
     * @return array<string, array{GenerationStatus}>
     */
    public static function rowsPixelforgeAccepted(): array
    {
        return [
            'queued row' => [GenerationStatus::Queued],
            'processing row' => [GenerationStatus::Processing],
        ];
    }

    #[DataProvider('rowsPixelforgeAccepted')]
    public function test_a_local_failure_after_pixelforge_accepted_the_row_is_refused_and_logged(GenerationStatus $rowStatus): void
    {
        $generation = $this->givenAGenerationIn($rowStatus);

        $outcome = $this->transitionGeneration($generation, new LocalGenerationFailureData(LocalFailureReason::ContentPolicyViolation, null, null, null));

        $this->assertSame(GenerationTransitionOutcome::RefusedConflict, $outcome);
        $this->assertConflictLogged(
            'Refused to fail a generation locally after Pixelforge accepted it.',
            [
                'generation_id' => $generation->id,
                'status' => $rowStatus->value,
                'provider_id' => $generation->provider_id,
                'incoming_status' => 'failed',
                'incoming_provider_id' => null,
                'outcome' => 'refused_conflict',
            ],
        );
    }

    public function test_a_local_failure_on_a_terminal_row_is_not_logged(): void
    {
        $generation = $this->givenAGenerationIn(GenerationStatus::Succeeded);

        $this->transitionGeneration($generation, new LocalGenerationFailureData(LocalFailureReason::ContentPolicyViolation, null, null, null));

        $this->assertSame([], $this->loggedMessages());
    }

    public function test_duplicate_stale_and_terminal_snapshots_are_not_logged(): void
    {
        $processing = $this->givenAGenerationIn(GenerationStatus::Processing);
        $succeeded = $this->givenAGenerationIn(GenerationStatus::Succeeded);

        $this->transitionGeneration($processing, self::snapshotFor($processing, GenerationStatus::Processing));
        $this->transitionGeneration($processing, self::snapshotFor($processing, GenerationStatus::Queued));
        $this->transitionGeneration($succeeded, self::snapshotFor($succeeded, GenerationStatus::Processing));

        $this->assertSame([], $this->loggedMessages());
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function assertConflictLogged(string $message, array $context): void
    {
        $logged = $this->loggedMessages();

        $this->assertCount(1, $logged);
        $this->assertSame('error', $logged[0]->level);
        $this->assertSame($message, $logged[0]->message);
        $this->assertSame($context, $logged[0]->context);
    }
}
