<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Actions;

use App\Domain\Generation\Actions\TransitionGenerationAction;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\GenerationTransitionOutcome;
use App\Domain\Generation\Models\Generation;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\TestCase;

final class TransitionGenerationActionTest extends TestCase
{
    use InteractsWithGenerationSnapshots;
    use RefreshDatabase;

    /**
     * Every row × incoming Pixelforge status pair of AC8.
     *
     * @return array<string, array{GenerationStatus, GenerationStatus, GenerationTransitionOutcome}>
     */
    public static function snapshotPairs(): array
    {
        $pending = GenerationStatus::Pending;
        $queued = GenerationStatus::Queued;
        $processing = GenerationStatus::Processing;
        $succeeded = GenerationStatus::Succeeded;
        $failed = GenerationStatus::Failed;

        $applied = GenerationTransitionOutcome::Applied;
        $duplicate = GenerationTransitionOutcome::IgnoredDuplicate;
        $stale = GenerationTransitionOutcome::IgnoredStale;
        $terminal = GenerationTransitionOutcome::IgnoredTerminal;
        $conflict = GenerationTransitionOutcome::IgnoredConflict;

        $table = [
            [$pending, $queued, $applied],
            [$pending, $processing, $applied],
            [$pending, $succeeded, $applied],
            [$pending, $failed, $applied],
            [$queued, $queued, $duplicate],
            [$queued, $processing, $applied],
            [$queued, $succeeded, $applied],
            [$queued, $failed, $applied],
            [$processing, $queued, $stale],
            [$processing, $processing, $duplicate],
            [$processing, $succeeded, $applied],
            [$processing, $failed, $applied],
            [$succeeded, $queued, $terminal],
            [$succeeded, $processing, $terminal],
            [$succeeded, $succeeded, $duplicate],
            [$succeeded, $failed, $conflict],
            [$failed, $queued, $terminal],
            [$failed, $processing, $terminal],
            [$failed, $succeeded, $conflict],
            [$failed, $failed, $duplicate],
        ];

        $pairs = [];

        foreach ($table as [$row, $incoming, $outcome]) {
            $pairs[sprintf('%s row, %s snapshot', $row->value, $incoming->value)] = [$row, $incoming, $outcome];
        }

        return $pairs;
    }

    #[DataProvider('snapshotPairs')]
    public function test_each_snapshot_pair_reports_its_outcome_and_only_an_applied_one_writes(
        GenerationStatus $rowStatus,
        GenerationStatus $incomingStatus,
        GenerationTransitionOutcome $expected,
    ): void {
        $generation = $this->givenAGeneration($rowStatus);
        $snapshot = self::snapshotFor($generation, $incomingStatus);
        $before = $this->rawRow($generation);
        $this->travel(1)->minutes();

        $outcome = $this->transition($generation, $snapshot);

        $this->assertSame($expected, $outcome);

        if ($expected === GenerationTransitionOutcome::Applied) {
            $this->assertSame($incomingStatus->value, $this->rawRow($generation)['status']);

            return;
        }

        $this->assertSame($before, $this->rawRow($generation));
    }

    public function test_an_applied_queued_snapshot_records_the_provider_id_and_creation_time(): void
    {
        $generation = Generation::factory()->create();

        $this->transition($generation, self::snapshotFor($generation, GenerationStatus::Queued));

        $row = $this->reloaded($generation);
        $this->assertSame(GenerationStatus::Queued, $row->status);
        $this->assertSame('gen_' . strtoupper($generation->id), $row->provider_id);
        $this->assertInstant('2026-11-18T23:59:40Z', $row->provider_created_at);
        $this->assertNull($row->started_at);
        $this->assertNull($row->completed_at);
        $this->assertNull($row->output_tokens);
        $this->assertNull($row->error_code);
    }

    public function test_an_applied_processing_snapshot_records_when_it_started(): void
    {
        $generation = Generation::factory()->queued()->create();

        $this->transition($generation, self::snapshotFor($generation, GenerationStatus::Processing));

        $row = $this->reloaded($generation);
        $this->assertSame(GenerationStatus::Processing, $row->status);
        $this->assertSame($generation->provider_id, $row->provider_id);
        $this->assertInstant('2026-11-18T23:59:42Z', $row->started_at);
        $this->assertNull($row->completed_at);
    }

    public function test_an_applied_succeeded_snapshot_records_the_whole_result(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->transition($generation, self::snapshotFor($generation, GenerationStatus::Succeeded));

        $row = $this->reloaded($generation);
        $this->assertSame(GenerationStatus::Succeeded, $row->status);
        $this->assertSame(
            [10, 765, 1_650, 1_100],
            [$row->input_text_tokens, $row->input_image_tokens, $row->output_tokens, $row->reasoning_tokens],
        );
        $this->assertSame(
            ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            $row->rates,
        );
        $this->assertSame(
            sprintf('https://pixelforge.promptedit.dev/images/%s.png?expires=1763596799&signature=test', $generation->provider_id),
            $row->image_url,
        );
        $this->assertInstant('2026-11-19T23:59:59Z', $row->image_url_expires_at);
        $this->assertInstant('2026-11-18T23:59:40Z', $row->provider_created_at);
        $this->assertInstant('2026-11-18T23:59:42Z', $row->started_at);
        $this->assertInstant('2026-11-18T23:59:59Z', $row->completed_at);
        $this->assertNull($row->error_code);
        $this->assertNull($row->error_message);
    }

    public function test_an_applied_failed_snapshot_records_the_error(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->transition($generation, self::snapshotFor($generation, GenerationStatus::Failed));

        $row = $this->reloaded($generation);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame('generation_failed', $row->error_code);
        $this->assertSame('The model could not produce an image for this prompt.', $row->error_message);
        $this->assertInstant('2026-11-18T23:59:59Z', $row->completed_at);
        $this->assertNull($row->output_tokens);
        $this->assertNull($row->rates);
        $this->assertNull($row->image_url);
        $this->assertNull($row->local_failure_reason);
    }

    public function test_the_given_model_is_never_mutated(): void
    {
        $generation = Generation::factory()->create();

        $this->transition($generation, self::snapshotFor($generation, GenerationStatus::Queued));

        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertNull($generation->provider_id);
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

    private function transition(Generation $generation, GenerationSnapshot $change): GenerationTransitionOutcome
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

    private function reloaded(Generation $generation): Generation
    {
        return Generation::query()->whereKey($generation->id)->firstOrFail();
    }

    private function assertInstant(string $expected, mixed $actual): void
    {
        $this->assertInstanceOf(CarbonImmutable::class, $actual);
        $this->assertSame($expected, $actual->utc()->format('Y-m-d\TH:i:s\Z'));
    }
}
