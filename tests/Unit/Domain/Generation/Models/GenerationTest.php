<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Models;

use App\Domain\Generation\Enums\SubmitAttemptOutcome;
use App\Domain\Generation\Enums\SubmitRetryNote;
use App\Domain\Generation\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\AssertsInstants;
use Tests\TestCase;

final class GenerationTest extends TestCase
{
    use AssertsInstants;
    use RefreshDatabase;

    private string $phpTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phpTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->phpTimezone);

        parent::tearDown();
    }

    public function test_the_ulid_is_the_key_and_no_column_draws_from_a_sequence(): void
    {
        $columns = collect(Schema::getColumns('generations'))->keyBy('name');

        $this->assertSame('character(26)', $columns['id']['type']);

        foreach ($columns as $name => $column) {
            $this->assertStringNotContainsString('nextval', (string) $column['default'], sprintf('%s draws from a sequence.', $name));
        }
    }

    public function test_the_owner_is_the_only_foreign_key(): void
    {
        $foreignKeys = Schema::getForeignKeys('generations');

        $this->assertCount(1, $foreignKeys);
        $this->assertSame(['user_id'], $foreignKeys[0]['columns']);
        $this->assertSame('users', $foreignKeys[0]['foreign_table']);
    }

    /**
     * Backs History's owner-scoped, newest-first list, and the user foreign key,
     * which Postgres does not index on its own.
     */
    public function test_a_users_generations_are_indexed_newest_first(): void
    {
        $indexes = collect(Schema::getIndexes('generations'))->keyBy('name');

        $this->assertTrue($indexes->has('generations_user_id_created_at_id_index'), 'No history index.');
        $this->assertSame(['user_id', 'created_at', 'id'], $indexes['generations_user_id_created_at_id_index']['columns']);
        $this->assertFalse($indexes['generations_user_id_created_at_id_index']['unique']);
    }

    /**
     * One generation per form submission and user (D-38). Postgres keeps NULLs
     * distinct, so rows created without a token never collide.
     */
    public function test_a_submission_token_is_unique_per_user(): void
    {
        $indexes = collect(Schema::getIndexes('generations'))->keyBy('name');

        $this->assertTrue($indexes->has('generations_user_id_submission_token_unique'), 'No submission token index.');
        $this->assertSame(['user_id', 'submission_token'], $indexes['generations_user_id_submission_token_unique']['columns']);
        $this->assertTrue($indexes['generations_user_id_submission_token_unique']['unique']);
        $this->assertSame('uuid', collect(Schema::getColumns('generations'))->keyBy('name')['submission_token']['type']);
    }

    public function test_the_model_uses_a_lowercase_ulid_string_key(): void
    {
        $generation = Generation::factory()->create();

        $this->assertFalse($generation->getIncrementing());
        $this->assertSame('string', $generation->getKeyType());
        $this->assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/', $generation->getKey());
    }

    public function test_any_number_of_rows_may_have_no_provider_id_but_a_provider_id_is_unique(): void
    {
        Generation::factory()->count(2)->create(['provider_id' => null]);
        Generation::factory()->create(['provider_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

        $this->assertSame(2, Generation::query()->whereNull('provider_id')->count());

        $this->expectException(UniqueConstraintViolationException::class);

        Generation::factory()->create(['provider_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
    }

    /**
     * A row from before PE-013, inserted without any `submit_*` column, reads as
     * never attempted.
     */
    public function test_a_row_without_bookkeeping_reads_as_never_attempted(): void
    {
        $generation = Generation::factory()->create();

        $row = Generation::query()->whereKey($generation->getKey())->firstOrFail();

        $this->assertNull($row->submit_first_attempted_at);
        $this->assertNull($row->submit_last_attempted_at);
        $this->assertNull($row->submit_last_outcome);
        $this->assertNull($row->submit_ambiguous_at);
        $this->assertNull($row->submit_handed_over_at);
        $this->assertNull($row->submit_first_overloaded_at);
        $this->assertNull($row->submit_first_concurrency_limited_at);
        $this->assertSame(0, $row->submit_invalid_api_key_count);
        $this->assertNull($row->submit_retry_note);
    }

    public function test_the_submit_bookkeeping_round_trips_through_its_casts(): void
    {
        $generation = Generation::factory()->create();
        $instants = [
            'submit_first_attempted_at' => '2026-11-20T10:00:00Z',
            'submit_last_attempted_at' => '2026-11-20T10:00:30Z',
            'submit_ambiguous_at' => '2026-11-20T10:00:05Z',
            'submit_handed_over_at' => '2026-11-20T10:30:00Z',
            'submit_first_overloaded_at' => '2026-11-20T10:00:01Z',
            'submit_first_concurrency_limited_at' => '2026-11-20T10:00:02Z',
        ];
        $generation->forceFill([
            ...array_map(fn (string $instant): CarbonImmutable => CarbonImmutable::parse($instant), $instants),
            'submit_last_outcome' => SubmitAttemptOutcome::ConcurrencyLimited,
            'submit_invalid_api_key_count' => 2,
            'submit_retry_note' => SubmitRetryNote::WaitingForSlot,
        ])->save();

        $row = Generation::query()->whereKey($generation->getKey())->firstOrFail();

        foreach ($instants as $column => $instant) {
            $this->assertSameInstant($instant, $row->getAttribute($column));
        }

        $this->assertSame(SubmitAttemptOutcome::ConcurrencyLimited, $row->submit_last_outcome);
        $this->assertSame(2, $row->submit_invalid_api_key_count);
        $this->assertSame(SubmitRetryNote::WaitingForSlot, $row->submit_retry_note);
        $this->assertSame('concurrency_limited', DB::table('generations')->where('id', $generation->getKey())->value('submit_last_outcome'));
    }

    public function test_the_last_provider_update_round_trips_through_its_cast(): void
    {
        $generation = Generation::factory()->create(['last_provider_update_at' => CarbonImmutable::parse('2026-11-20T10:03:10Z')]);

        $row = Generation::query()->whereKey($generation->getKey())->firstOrFail();

        $this->assertInstanceOf(CarbonImmutable::class, $row->last_provider_update_at);
        $this->assertSameInstant('2026-11-20T10:03:10Z', $row->last_provider_update_at);
    }

    public function test_a_pending_row_has_no_last_provider_update(): void
    {
        $generation = Generation::factory()->create();

        $this->assertNull(Generation::query()->whereKey($generation->getKey())->firstOrFail()->last_provider_update_at);
    }

    public function test_a_pixelforge_instant_survives_a_non_utc_php_and_database_timezone(): void
    {
        date_default_timezone_set('Europe/Bucharest');
        DB::statement("SET TIME ZONE 'Europe/Bucharest'");

        $completedAt = CarbonImmutable::parse('2026-11-18T23:59:59Z');
        $generation = Generation::factory()->create(['completed_at' => $completedAt]);

        $readBack = Generation::query()->whereKey($generation->getKey())->firstOrFail()->completed_at;

        $this->assertInstanceOf(CarbonImmutable::class, $readBack);
        $this->assertTrue($readBack->equalTo($completedAt), sprintf('Read back %s.', $readBack->toIso8601String()));
        $this->assertSame('2026-11-18', $readBack->utc()->toDateString());
        $this->assertSame(
            '2026-11-18',
            DB::scalar(
                "select (completed_at at time zone 'UTC')::date from generations where id = ?",
                [$generation->getKey()],
            ),
        );
    }
}
