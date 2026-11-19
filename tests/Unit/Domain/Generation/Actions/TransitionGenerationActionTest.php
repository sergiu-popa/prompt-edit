<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Actions;

use App\Domain\Generation\Actions\TransitionGenerationAction;
use App\Domain\Generation\DataTransferObjects\LocalGenerationFailureData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\GenerationTransitionOutcome;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Exceptions\ConcurrentGenerationTransitionException;
use App\Domain\Generation\Models\Generation;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
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

    /**
     * AC8's local-failure column: Pixelforge has created nothing, so only a
     * pending row may fail here.
     *
     * @return array<string, array{GenerationStatus, GenerationTransitionOutcome}>
     */
    public static function localFailurePairs(): array
    {
        return [
            'pending row' => [GenerationStatus::Pending, GenerationTransitionOutcome::Applied],
            'queued row' => [GenerationStatus::Queued, GenerationTransitionOutcome::RefusedConflict],
            'processing row' => [GenerationStatus::Processing, GenerationTransitionOutcome::RefusedConflict],
            'succeeded row' => [GenerationStatus::Succeeded, GenerationTransitionOutcome::IgnoredTerminal],
            'failed row' => [GenerationStatus::Failed, GenerationTransitionOutcome::IgnoredTerminal],
        ];
    }

    #[DataProvider('localFailurePairs')]
    public function test_a_local_failure_reports_its_outcome_and_only_an_applied_one_writes(
        GenerationStatus $rowStatus,
        GenerationTransitionOutcome $expected,
    ): void {
        $generation = $this->givenAGeneration($rowStatus);
        $before = $this->rawRow($generation);
        $this->travel(1)->minutes();

        $outcome = $this->transition($generation, new LocalGenerationFailureData(LocalFailureReason::ContentPolicyViolation, null, null, null));

        $this->assertSame($expected, $outcome);

        if ($expected === GenerationTransitionOutcome::Applied) {
            $this->assertSame('failed', $this->rawRow($generation)['status']);

            return;
        }

        $this->assertSame($before, $this->rawRow($generation));
    }

    public function test_an_applied_local_failure_records_the_reason_and_the_providers_error(): void
    {
        $generation = Generation::factory()->create();

        $outcome = $this->transition($generation, new LocalGenerationFailureData(
            LocalFailureReason::ContentPolicyViolation,
            'content_policy_violation',
            'The prompt was rejected by the content policy.',
            'prompt',
        ));

        $row = $this->reloaded($generation);
        $this->assertSame(GenerationTransitionOutcome::Applied, $outcome);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(LocalFailureReason::ContentPolicyViolation, $row->local_failure_reason);
        $this->assertSame('content_policy_violation', $row->error_code);
        $this->assertSame('The prompt was rejected by the content policy.', $row->error_message);
        $this->assertSame('prompt', $row->error_param);
        $this->assertNull($row->provider_id);
    }

    public function test_a_local_failure_with_only_a_reason_leaves_every_error_column_empty(): void
    {
        $generation = Generation::factory()->create();

        $outcome = $this->transition($generation, new LocalGenerationFailureData(LocalFailureReason::ContentPolicyViolation, null, null, null));

        $row = $this->rawRow($generation);
        $this->assertSame(GenerationTransitionOutcome::Applied, $outcome);
        $this->assertSame('content_policy_violation', $row['local_failure_reason']);
        $this->assertNull($row['error_code']);
        $this->assertNull($row['error_message']);
        $this->assertNull($row['error_param']);
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

    public function test_an_applied_snapshot_keeps_its_instants_exact_under_a_non_utc_session(): void
    {
        $phpTimezone = date_default_timezone_get();
        $generation = Generation::factory()->processing()->create();

        try {
            date_default_timezone_set('Europe/Bucharest');
            DB::statement("SET TIME ZONE 'Europe/Bucharest'");

            $this->transition($generation, self::snapshotFor($generation, GenerationStatus::Succeeded));

            $this->assertInstant('2026-11-18T23:59:59Z', $this->reloaded($generation)->completed_at);
            $this->assertSame(
                '2026-11-18',
                DB::scalar("select (completed_at at time zone 'UTC')::date from generations where id = ?", [$generation->id]),
            );
        } finally {
            date_default_timezone_set($phpTimezone);
        }
    }

    /**
     * Defence in depth: even if the row lock were missed, the write only lands
     * on the status the action read.
     */
    public function test_a_row_that_changed_under_a_missed_lock_is_never_overwritten(): void
    {
        $generation = Generation::factory()->processing()->create();
        $snapshot = self::snapshotFor($generation, GenerationStatus::Succeeded);
        $before = $this->rawRow($generation);
        $changedUnderTheLock = false;

        DB::listen(function (QueryExecuted $query) use ($generation, &$changedUnderTheLock): void {
            if ($changedUnderTheLock || ! str_contains($query->sql, 'for update')) {
                return;
            }

            $changedUnderTheLock = true;
            DB::table('generations')->where('id', $generation->id)->update(['status' => GenerationStatus::Succeeded->value]);
        });

        try {
            $this->transition($generation, $snapshot);
            $this->fail('The transition overwrote a row that changed under it.');
        } catch (ConcurrentGenerationTransitionException $exception) {
            $this->assertStringContainsString($generation->id, $exception->getMessage());
            $this->assertStringContainsString('processing', $exception->getMessage());
            $this->assertStringContainsString('succeeded', $exception->getMessage());
        }

        $this->assertTrue($changedUnderTheLock);
        $this->assertSame($before, $this->rawRow($generation));
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
