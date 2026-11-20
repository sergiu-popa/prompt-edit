<?php

declare(strict_types=1);

namespace Tests\Feature\Generation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\Concerns\InteractsWithPricing;
use Tests\TestCase;

final class GenerationFormPageTest extends TestCase
{
    use InteractsWithPixelforgeApi;
    use InteractsWithPricing;
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('dashboard'))->assertRedirect('/login');
    }

    public function test_the_signed_in_home_is_the_generate_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Generations/Create'));
    }

    public function test_the_pricing_prop_is_the_shared_estimate_fixture_pricing_block(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $this->assertSame(
                self::estimatesFixture()['pricing'],
                $page->toArray()['props']['pricing'],
            ));
    }

    public function test_the_limits_prop_holds_pixelforges_input_limits(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $this->assertSame(
                ['max_prompt_characters' => 4000, 'max_reference_images' => 4],
                $page->toArray()['props']['limits'],
            ));
    }

    public function test_the_page_gets_only_the_shared_props_pricing_limits_and_can_submit(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $this->assertEqualsCanonicalizing(
                ['auth', 'operator_navigation', 'errors', 'pricing', 'limits', 'can_submit'],
                array_keys($page->toArray()['props']),
            ));
    }

    public function test_generate_is_open_once_pixelforge_is_configured(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('can_submit', true));
    }

    /**
     * Until PE-015 sets a key, the page still estimates, but Generate stays closed (D-40).
     */
    public function test_generate_is_closed_until_pixelforge_is_configured(): void
    {
        config(['pixelforge.api_key' => null]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('can_submit', false)->has('pricing'));
    }
}
