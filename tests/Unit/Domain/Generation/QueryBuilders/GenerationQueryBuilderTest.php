<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\QueryBuilders;

use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\QueryBuilders\GenerationQueryBuilder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
