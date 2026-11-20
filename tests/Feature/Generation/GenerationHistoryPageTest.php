<?php

declare(strict_types=1);

namespace Tests\Feature\Generation;

use App\Domain\Generation\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\ReadsGenerationHistory;
use Tests\TestCase;

final class GenerationHistoryPageTest extends TestCase
{
    use InteractsWithGenerations;
    use ReadsGenerationHistory;
    use RefreshDatabase;

    /** GenerationResource's fields, and nothing else (AC 16). */
    private const array ITEM_KEYS = [
        'id',
        'prompt',
        'size',
        'quality',
        'reference_image_count',
        'status',
        'status_label',
        'user_estimate_nanodollars',
        'created_at',
        'started_at',
        'completed_at',
        'user_charge_nanodollars',
        'image_url',
        'image_url_expires_at',
        'image_expired',
        'failure_message',
        'status_note',
    ];

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('generations.index'))->assertRedirect('/login');
    }

    public function test_history_is_the_generations_index_page_with_only_the_shared_props_and_generations(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/generations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Generations/Index')
                ->tap(fn (Assert $page) => $this->assertEqualsCanonicalizing(
                    ['auth', 'errors', 'generations'],
                    array_keys($page->toArray()['props']),
                )));
    }

    public function test_an_item_holds_exactly_the_resources_fields_with_integer_nanodollars(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19T10:15:30Z'));
        $user = User::factory()->create();
        $lighthouse = $this->createGenerationFor($user, self::lighthouseRequest());

        $item = $this->historyFor($user)['data'][0];

        $this->assertSame(self::ITEM_KEYS, array_keys($item));
        $this->assertSame([
            'id' => $lighthouse->id,
            'prompt' => self::LIGHTHOUSE_PROMPT,
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_image_count' => 1,
            'status' => 'pending',
            'status_label' => 'Queued',
            'user_estimate_nanodollars' => ['min' => 347_750_000, 'max' => 457_150_000],
            'created_at' => '2026-11-19T10:15:30Z',
            'started_at' => null,
            'completed_at' => null,
            'user_charge_nanodollars' => null,
            'image_url' => null,
            'image_url_expires_at' => null,
            'image_expired' => false,
            'failure_message' => null,
            'status_note' => null,
        ], $item);
    }

    public function test_the_generations_prop_is_the_page_of_items_and_the_servers_time(): void
    {
        $this->assertSame(['data', 'links', 'meta', 'server_time'], array_keys($this->historyFor(User::factory()->create())));
    }

    public function test_a_queued_generation_also_reads_queued_and_counts_its_references(): void
    {
        $user = User::factory()->create();
        Generation::factory()->queued()->create(['user_id' => $user->id, 'reference_images' => []]);

        $item = $this->historyFor($user)['data'][0];

        $this->assertSame('queued', $item['status']);
        $this->assertSame('Queued', $item['status_label']);
        $this->assertSame(0, $item['reference_image_count']);
    }

    public function test_a_users_generations_come_newest_first(): void
    {
        $user = User::factory()->create();
        $oldest = $this->givenAGenerationCreatedAt($user, '2026-11-19T09:00:00Z');
        $newest = $this->givenAGenerationCreatedAt($user, '2026-11-19T11:00:00Z');
        $middle = $this->givenAGenerationCreatedAt($user, '2026-11-19T10:00:00Z');

        $this->assertSame(
            [$newest->id, $middle->id, $oldest->id],
            array_column($this->historyFor($user)['data'], 'id'),
        );
    }

    public function test_history_shows_twenty_generations_a_page(): void
    {
        $user = User::factory()->create();
        Generation::factory()->count(21)->create(['user_id' => $user->id]);

        $firstPage = $this->historyFor($user);
        $secondPage = $this->historyFor($user, page: 2);

        $this->assertCount(20, $firstPage['data']);
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 20, 'total' => 21], $this->pageNumbers($firstPage));
        $this->assertNull($firstPage['links']['prev']);
        $this->assertStringEndsWith('/generations?page=2', (string) $firstPage['links']['next']);

        $this->assertCount(1, $secondPage['data']);
        $this->assertSame(['current_page' => 2, 'last_page' => 2, 'per_page' => 20, 'total' => 21], $this->pageNumbers($secondPage));
        $this->assertNull($secondPage['links']['next']);
    }

    public function test_a_user_with_no_generations_sees_none_even_when_another_user_has_some(): void
    {
        $other = User::factory()->create();
        Generation::factory()->count(2)->create(['user_id' => $other->id, 'prompt' => 'Somebody else\'s secret castle']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('generations.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('generations.meta.total', 0)
            ->has('generations.data', 0)
            ->tap(fn (Assert $page) => $this->assertStringNotContainsString(
                'secret castle',
                (string) json_encode($page->toArray()),
            )));
        $this->assertStringNotContainsString('secret castle', (string) $response->getContent());
    }

    public function test_history_lists_only_the_signed_in_users_generations(): void
    {
        $user = User::factory()->create();
        $mine = Generation::factory()->create(['user_id' => $user->id]);
        Generation::factory()->create(['prompt' => 'Somebody else\'s secret castle']);

        $history = $this->historyFor($user);

        $this->assertSame([$mine->id], array_column($history['data'], 'id'));
        $this->assertStringNotContainsString('secret castle', (string) json_encode($history));
    }

    /**
     * The resource reads no relation, so a full page costs what one row costs (no N+1).
     */
    public function test_the_query_count_does_not_grow_with_the_page(): void
    {
        $oneRow = User::factory()->create();
        Generation::factory()->create(['user_id' => $oneRow->id]);
        $fullPage = User::factory()->create();
        Generation::factory()->count(20)->create(['user_id' => $fullPage->id]);

        $queriesForOneRow = $this->queriesToShowHistoryTo($oneRow);

        $this->assertGreaterThan(0, $queriesForOneRow);
        $this->assertSame($queriesForOneRow, $this->queriesToShowHistoryTo($fullPage));
    }

    public function test_a_plain_visit_highlights_nothing(): void
    {
        $user = User::factory()->create();
        Generation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page->missingFlash('highlighted_generation_id'));
    }

    /**
     * @param  array{meta: array<string, mixed>}  $history
     * @return array<string, mixed>
     */
    private function pageNumbers(array $history): array
    {
        return array_intersect_key($history['meta'], array_flip(['current_page', 'last_page', 'per_page', 'total']));
    }

    private function queriesToShowHistoryTo(User $user): int
    {
        $this->actingAs($user);
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->get(route('generations.index'))->assertOk();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    private function givenAGenerationCreatedAt(User $user, string $instant): Generation
    {
        $this->travelTo(CarbonImmutable::parse($instant));

        try {
            return Generation::factory()->create(['user_id' => $user->id]);
        } finally {
            $this->travelBack();
        }
    }
}
