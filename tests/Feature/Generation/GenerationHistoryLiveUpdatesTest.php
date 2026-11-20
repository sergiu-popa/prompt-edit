<?php

declare(strict_types=1);

namespace Tests\Feature\Generation;

use App\Domain\Generation\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\TestCase;

/**
 * History's live updates are partial reloads of the `generations` prop (D-41).
 * There is deliberately no Http::fake() here: TestCase prevents stray requests,
 * so any call to Pixelforge during a reload would throw (AC 3).
 */
final class GenerationHistoryLiveUpdatesTest extends TestCase
{
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use RefreshDatabase;

    public function test_a_partial_reload_of_a_later_page_reads_that_page_from_our_database_alone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19T10:00:00Z'));
        $user = User::factory()->create();
        $lighthouse = $this->createGenerationFor($user, self::lighthouseRequest());
        $this->travelTo(CarbonImmutable::parse('2026-11-19T11:00:00Z'));
        Generation::factory()->count(20)->create(['user_id' => $user->id]);
        $this->travelTo(CarbonImmutable::parse('2026-11-19T11:00:05.125Z'));
        Queue::fake();

        $this->actingAs($user)
            ->get(route('generations.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('generations.data.0.status', 'pending')
                ->tap(fn () => $this->transitionGeneration($lighthouse, self::lighthouseSucceededSnapshotFor($lighthouse)))
                ->reloadOnly('generations', fn (Assert $reload) => $reload
                    ->missing('auth')
                    ->where('generations.meta.current_page', 2)
                    ->where('generations.server_time', '2026-11-19T11:00:05.125Z')
                    ->has('generations.data', 1)
                    ->where('generations.data.0.id', $lighthouse->id)
                    ->where('generations.data.0.status', 'succeeded')
                    ->where('generations.data.0.status_label', 'Succeeded')
                    ->where('generations.data.0.user_charge_nanodollars', 381_887_500)));

        Queue::assertNothingPushed();
    }

    public function test_a_partial_reload_returns_only_the_signed_in_users_generations(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $own = Generation::factory()->create(['user_id' => $user->id]);
        Generation::factory()->create(['user_id' => $other->id, 'prompt' => 'Another user\'s prompt']);

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->tap(fn () => Generation::factory()->create(['user_id' => $other->id, 'prompt' => 'Another user\'s new prompt']))
                ->reloadOnly('generations', fn (Assert $reload) => $reload
                    ->has('generations.data', 1)
                    ->where('generations.data.0.id', $own->id)
                    ->where('generations.meta.total', 1)
                    ->tap(fn (Assert $reloaded) => $this->assertStringNotContainsString(
                        'Another user',
                        (string) json_encode($reloaded->toArray()['props']),
                    ))));
    }
}
