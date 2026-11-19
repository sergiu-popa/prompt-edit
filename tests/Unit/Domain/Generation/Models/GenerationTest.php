<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Models;

use App\Domain\Generation\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class GenerationTest extends TestCase
{
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
