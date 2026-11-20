<?php

declare(strict_types=1);

namespace Tests\Feature\Credits;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * AC 15: the fake Buy credits page, for signed-in users only.
 */
final class BuyCreditsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/credits')->assertRedirect(route('login'));
    }

    public function test_it_renders_exactly_the_three_packs_in_integer_nanodollars(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/credits')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Credits/Buy')
                ->where('packs_nanodollars', [1_000_000_000, 5_000_000_000, 10_000_000_000])
                ->tap(fn (Assert $page) => $this->assertEqualsCanonicalizing(
                    ['auth', 'operator_navigation', 'credits', 'errors', 'packs_nanodollars'],
                    array_keys($page->toArray()['props']),
                )));
    }
}
