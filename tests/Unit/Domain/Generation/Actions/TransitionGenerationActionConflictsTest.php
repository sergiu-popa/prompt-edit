<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Actions;

use App\Domain\Generation\Actions\TransitionGenerationAction;
use App\Domain\Generation\DataTransferObjects\LocalGenerationFailureData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\GenerationTransitionOutcome;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Models\Generation;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\TestCase;

/**
 * A snapshot is applied only to the generation it belongs to, and every
 * conflict is logged at error level for the operator.
 */
final class TransitionGenerationActionConflictsTest extends TestCase
{
    use InteractsWithGenerationSnapshots;
    use RefreshDatabase;

    private const string MISSING_GENERATION_ID = 'missing generation id';

    private const string OTHER_GENERATION_ID = 'another generation\'s id';

    private const string OTHER_PROVIDER_ID = 'another provider id';

    private const string FOREIGN_PROVIDER_ID = 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K';

    /**
     * @var list<MessageLogged>
     */
    private array $loggedMessages = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(MessageLogged::class, function (MessageLogged $message): void {
            $this->loggedMessages[] = $message;
        });
    }

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
        $generation = $this->givenAGeneration($rowStatus);
        $incomingStatus = $rowStatus->isTerminal() ? GenerationStatus::Processing : GenerationStatus::Succeeded;
        $snapshot = self::snapshotFor($generation, $incomingStatus, match ($cause) {
            self::MISSING_GENERATION_ID => ['metadata' => ['order_id' => '1042']],
            self::OTHER_GENERATION_ID => ['metadata' => ['generation_id' => Generation::factory()->create()->id]],
            default => ['id' => self::FOREIGN_PROVIDER_ID],
        });
        $before = $this->rawRow($generation);
        $this->travel(1)->minutes();

        $outcome = $this->transition($generation, $snapshot);

        $this->assertSame(GenerationTransitionOutcome::RefusedConflict, $outcome);
        $this->assertSame($before, $this->rawRow($generation));
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
        $generation = $this->givenAGeneration($rowStatus);
        $snapshot = self::snapshotFor($generation, $incomingStatus);

        $this->assertSame(GenerationTransitionOutcome::IgnoredConflict, $this->transition($generation, $snapshot));

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
        $generation = $this->givenAGeneration($rowStatus);

        $outcome = $this->transition($generation, new LocalGenerationFailureData(LocalFailureReason::ContentPolicyViolation, null, null, null));

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
        $generation = $this->givenAGeneration(GenerationStatus::Succeeded);

        $this->transition($generation, new LocalGenerationFailureData(LocalFailureReason::ContentPolicyViolation, null, null, null));

        $this->assertSame([], $this->loggedMessages);
    }

    public function test_duplicate_stale_and_terminal_snapshots_are_not_logged(): void
    {
        $processing = $this->givenAGeneration(GenerationStatus::Processing);
        $succeeded = $this->givenAGeneration(GenerationStatus::Succeeded);

        $this->transition($processing, self::snapshotFor($processing, GenerationStatus::Processing));
        $this->transition($processing, self::snapshotFor($processing, GenerationStatus::Queued));
        $this->transition($succeeded, self::snapshotFor($succeeded, GenerationStatus::Processing));

        $this->assertSame([], $this->loggedMessages);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function assertConflictLogged(string $message, array $context): void
    {
        $this->assertCount(1, $this->loggedMessages);
        $this->assertSame('error', $this->loggedMessages[0]->level);
        $this->assertSame($message, $this->loggedMessages[0]->message);
        $this->assertSame($context, $this->loggedMessages[0]->context);
    }

    private function givenAGeneration(GenerationStatus $status): Generation
    {
        $factory = Generation::factory();

        return match ($status) {
            GenerationStatus::Pending => $factory->create(),
            GenerationStatus::Queued => $factory->queued()->create(),
            GenerationStatus::Processing => $factory->processing()->create(),
            GenerationStatus::Succeeded => $factory->succeeded()->create(),
            GenerationStatus::Failed => $factory->failed()->create(),
        };
    }

    private function transition(Generation $generation, GenerationSnapshot|LocalGenerationFailureData $change): GenerationTransitionOutcome
    {
        return $this->app->make(TransitionGenerationAction::class)->execute($generation, $change);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRow(Generation $generation): array
    {
        return (array) DB::table('generations')->where('id', $generation->id)->first();
    }
}
