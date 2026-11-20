<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\QueryBuilders;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\SubmitAttemptOutcome;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\QueryBuilders\GenerationQueryBuilder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GenerationQueryBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_model_queries_through_the_generation_query_builder(): void
    {
        $this->assertInstanceOf(GenerationQueryBuilder::class, Generation::query());
    }

    public function test_owned_by_keeps_only_the_users_generations(): void
    {
        $owner = User::factory()->create();
        $mine = Generation::factory()->count(2)->create(['user_id' => $owner->id]);
        Generation::factory()->count(3)->create();

        $ids = Generation::query()->ownedBy($owner)->pluck('id')->sort()->values()->all();

        $this->assertSame($mine->pluck('id')->sort()->values()->all(), $ids);
    }

    public function test_owned_by_finds_nothing_for_a_user_with_no_generations(): void
    {
        Generation::factory()->count(2)->create();

        $this->assertSame(0, Generation::query()->ownedBy(User::factory()->create())->count());
    }

    public function test_newest_first_orders_by_creation_time_descending(): void
    {
        $owner = User::factory()->create();
        $oldest = $this->givenAGenerationCreatedAt($owner, '2026-11-19T10:00:00Z');
        $newest = $this->givenAGenerationCreatedAt($owner, '2026-11-19T12:00:00Z');
        $middle = $this->givenAGenerationCreatedAt($owner, '2026-11-19T11:00:00Z');

        $this->assertSame(
            [$newest->id, $middle->id, $oldest->id],
            Generation::query()->ownedBy($owner)->newestFirst()->pluck('id')->all(),
        );
    }

    /**
     * Creation times are stored to the second, so two submissions in one second tie;
     * the ULID, which grows with time, puts the later one first.
     */
    public function test_newest_first_breaks_a_same_second_tie_by_id_descending(): void
    {
        $owner = User::factory()->create();
        $first = $this->givenAGenerationCreatedAt($owner, '2026-11-19T10:00:00Z');
        $second = $this->givenAGenerationCreatedAt($owner, '2026-11-19T10:00:00Z');
        $third = $this->givenAGenerationCreatedAt($owner, '2026-11-19T10:00:00Z');

        $expected = collect([$first->id, $second->id, $third->id])->sortDesc()->values()->all();

        $this->assertSame($expected, Generation::query()->ownedBy($owner)->newestFirst()->pluck('id')->all());
    }

    /**
     * AC 5's example: a processing row last updated 2 min 50 s ago is skipped, and
     * at 3 min 10 s it is polled; a queued row likewise around 2 min.
     *
     * @return array<string, array{GenerationStatus, int, bool}>
     */
    public static function staleness(): array
    {
        return [
            'processing, 2:50' => [GenerationStatus::Processing, 170, false],
            'processing, exactly 3:00' => [GenerationStatus::Processing, 180, false],
            'processing, 3:10' => [GenerationStatus::Processing, 190, true],
            'queued, 1:50' => [GenerationStatus::Queued, 110, false],
            'queued, 2:10' => [GenerationStatus::Queued, 130, true],
        ];
    }

    #[DataProvider('staleness')]
    public function test_stale_at_pixelforge_selects_a_row_by_its_last_provider_update(GenerationStatus $status, int $secondsAgo, bool $selected): void
    {
        $generation = $this->givenAConfirmedGeneration($status, self::now()->subSeconds($secondsAgo));

        $this->assertSame($selected ? [$generation->id] : [], $this->staleIds());
    }

    /**
     * Only `updated_at` moved, as PE-013's markers and the reconciler's bookkeeping
     * move it: the row is still fresh.
     */
    public function test_stale_at_pixelforge_never_reads_updated_at(): void
    {
        $generation = $this->givenAConfirmedGeneration(GenerationStatus::Processing, self::now()->subSeconds(30));
        DB::table('generations')->where('id', $generation->id)->update(['updated_at' => self::now()->subHour()]);

        $this->assertSame([], $this->staleIds());
    }

    public function test_pending_and_terminal_rows_are_never_stale_at_pixelforge(): void
    {
        $longAgo = self::now()->subDay();
        Generation::factory()->create(['last_provider_update_at' => $longAgo]);
        Generation::factory()->succeeded()->create(['last_provider_update_at' => $longAgo]);
        Generation::factory()->failed()->create(['last_provider_update_at' => $longAgo]);
        Generation::factory()->failedLocally()->create();

        $this->assertSame([], $this->staleIds());
    }

    public function test_due_for_recheck_keeps_rows_never_checked_or_due_now(): void
    {
        $never = $this->givenAConfirmedGeneration(GenerationStatus::Processing, self::now()->subHour());
        $due = $this->givenAConfirmedGeneration(GenerationStatus::Processing, self::now()->subHour(), self::now());
        $overdue = $this->givenAConfirmedGeneration(GenerationStatus::Processing, self::now()->subHour(), self::now()->subMinute());
        $this->givenAConfirmedGeneration(GenerationStatus::Processing, self::now()->subHour(), self::now()->addSecond());

        $ids = Generation::query()->dueForRecheck(self::now())->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$never->id, $due->id, $overdue->id])->sort()->values()->all(), $ids);
    }

    public function test_oldest_provider_update_first_breaks_a_tie_by_id(): void
    {
        $newest = $this->givenAConfirmedGeneration(GenerationStatus::Processing, self::now()->subMinutes(4));
        $tiedA = $this->givenAConfirmedGeneration(GenerationStatus::Queued, self::now()->subMinutes(10));
        $oldest = $this->givenAConfirmedGeneration(GenerationStatus::Processing, self::now()->subMinutes(30));
        $tiedB = $this->givenAConfirmedGeneration(GenerationStatus::Processing, self::now()->subMinutes(10));
        $tied = collect([$tiedA->id, $tiedB->id])->sort()->values()->all();

        $this->assertSame([$oldest->id, ...$tied, $newest->id], $this->staleIds());
    }

    /**
     * The poll selection is one range scan of the partial index, however many
     * terminal rows the table holds.
     */
    public function test_the_poll_selection_reads_the_partial_poll_index(): void
    {
        $this->givenAConfirmedGeneration(GenerationStatus::Processing, self::now()->subHour());
        DB::statement('SET LOCAL enable_seqscan = off');
        $query = $this->staleSelection()->limit(20);

        $plan = implode("\n", array_map(fn (object $line): string => (string) current((array) $line), DB::select('explain ' . $query->toSql(), $query->getBindings())));

        $this->assertStringContainsString('generations_reconcile_poll_index', $plan);
    }

    /**
     * AC 12: PE-013 handed it over, so it is due whatever its last attempt.
     */
    public function test_a_handed_over_row_awaits_redispatch(): void
    {
        $handedOver = Generation::factory()->submitAttempted(SubmitAttemptOutcome::Ambiguous)->submitLatched()->create([
            'submit_handed_over_at' => self::now()->subSeconds(10),
        ]);

        $this->assertSame([$handedOver->id], $this->awaitingRedispatchIds());
    }

    /**
     * AC 12's example: an attempt 6 min ago is re-dispatched, one 4 min ago is left to
     * its job; with no attempt, the creation time counts.
     *
     * @return array<string, array{?int, int, bool}>
     */
    public static function redispatchCutoff(): array
    {
        return [
            'last attempt 6 min ago' => [360, 400, true],
            'last attempt 4 min ago' => [240, 400, false],
            'last attempt exactly 5 min ago' => [300, 400, false],
            'never attempted, created 6 min ago' => [null, 360, true],
            'never attempted, created 4 min ago' => [null, 240, false],
        ];
    }

    #[DataProvider('redispatchCutoff')]
    public function test_a_row_awaits_redispatch_once_its_last_attempt_or_creation_is_older_than_5_minutes(?int $attemptedSecondsAgo, int $createdSecondsAgo, bool $selected): void
    {
        $attemptedAt = $attemptedSecondsAgo === null ? null : self::now()->subSeconds($attemptedSecondsAgo);
        $generation = Generation::factory()->create([
            'created_at' => self::now()->subSeconds($createdSecondsAgo),
            'submit_first_attempted_at' => $attemptedAt,
            'submit_last_attempted_at' => $attemptedAt,
            'submit_last_outcome' => $attemptedAt === null ? null : SubmitAttemptOutcome::Overloaded,
        ]);

        $this->assertSame($selected ? [$generation->id] : [], $this->awaitingRedispatchIds());
    }

    public function test_confirmed_and_terminal_rows_never_await_redispatch(): void
    {
        $longAgo = ['created_at' => self::now()->subDay(), 'submit_handed_over_at' => self::now()->subHour()];
        Generation::factory()->queued()->create($longAgo);
        Generation::factory()->processing()->create($longAgo);
        Generation::factory()->succeeded()->create($longAgo);
        Generation::factory()->failedLocally()->create($longAgo);

        $this->assertSame([], $this->awaitingRedispatchIds());
    }

    public function test_oldest_created_first_breaks_a_tie_by_id(): void
    {
        $newest = Generation::factory()->create(['created_at' => self::now()->subMinutes(6)]);
        $tiedA = Generation::factory()->create(['created_at' => self::now()->subMinutes(10)]);
        $oldest = Generation::factory()->create(['created_at' => self::now()->subHour()]);
        $tiedB = Generation::factory()->create(['created_at' => self::now()->subMinutes(10)]);
        $tied = collect([$tiedA->id, $tiedB->id])->sort()->values()->all();

        $this->assertSame([$oldest->id, ...$tied, $newest->id], $this->awaitingRedispatchIds());
    }

    public function test_the_redispatch_selection_reads_the_partial_pending_index(): void
    {
        Generation::factory()->create(['created_at' => self::now()->subHour()]);
        DB::statement('SET LOCAL enable_seqscan = off');
        $query = $this->awaitingRedispatchSelection()->limit(20);

        $plan = implode("\n", array_map(fn (object $line): string => (string) current((array) $line), DB::select('explain ' . $query->toSql(), $query->getBindings())));

        $this->assertStringContainsString('generations_pending_index', $plan);
    }

    public function test_stuck_unwarned_keeps_non_terminal_rows_past_the_age_not_yet_warned_about(): void
    {
        $at = ['created_at' => self::now()->subMinutes(15)];
        $pending = Generation::factory()->create($at);
        $queued = Generation::factory()->queued()->create($at);
        $processing = Generation::factory()->processing()->create($at);
        Generation::factory()->processing()->create(['created_at' => self::now()->subSeconds(899)]);
        Generation::factory()->processing()->create([...$at, 'reconcile_stuck_warned_at' => self::now()->subMinute()]);
        Generation::factory()->succeeded()->create($at);
        Generation::factory()->failed()->create($at);

        $ids = Generation::query()->stuckUnwarned(self::now()->subSeconds(900))->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$pending->id, $queued->id, $processing->id])->sort()->values()->all(), $ids);
    }

    /**
     * Terminal rows are never marked as warned, so only the partial index keeps this
     * step from scanning all history every run.
     */
    public function test_the_stuck_selection_reads_the_partial_stuck_index(): void
    {
        Generation::factory()->processing()->create(['created_at' => self::now()->subHour()]);
        DB::statement('SET LOCAL enable_seqscan = off');
        $query = Generation::query()->stuckUnwarned(self::now()->subSeconds(900))->oldestCreatedFirst()->limit(20);

        $plan = implode("\n", array_map(fn (object $line): string => (string) current((array) $line), DB::select('explain ' . $query->toSql(), $query->getBindings())));

        $this->assertStringContainsString('generations_stuck_unwarned_index', $plan);
    }

    private function awaitingRedispatchSelection(): GenerationQueryBuilder
    {
        return Generation::query()
            ->awaitingRedispatch(self::now()->subSeconds(300))
            ->dueForRecheck(self::now())
            ->oldestCreatedFirst();
    }

    /**
     * @return list<mixed>
     */
    private function awaitingRedispatchIds(): array
    {
        return array_values($this->awaitingRedispatchSelection()->pluck('id')->all());
    }

    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-20T12:00:00Z');
    }

    private function givenAConfirmedGeneration(GenerationStatus $status, CarbonImmutable $lastProviderUpdateAt, ?CarbonImmutable $recheckAt = null): Generation
    {
        $factory = $status === GenerationStatus::Queued ? Generation::factory()->queued() : Generation::factory()->processing();

        return $factory->create(['last_provider_update_at' => $lastProviderUpdateAt, 'reconcile_recheck_at' => $recheckAt]);
    }

    private function staleSelection(): GenerationQueryBuilder
    {
        return Generation::query()
            ->staleAtPixelforge(self::now()->subSeconds(120), self::now()->subSeconds(180))
            ->dueForRecheck(self::now())
            ->oldestProviderUpdateFirst();
    }

    /**
     * @return list<mixed>
     */
    private function staleIds(): array
    {
        return array_values($this->staleSelection()->pluck('id')->all());
    }

    private function givenAGenerationCreatedAt(User $owner, string $instant): Generation
    {
        $this->travelTo(CarbonImmutable::parse($instant));

        try {
            return Generation::factory()->create(['user_id' => $owner->id]);
        } finally {
            $this->travelBack();
        }
    }
}
