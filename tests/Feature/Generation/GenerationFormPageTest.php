<?php

declare(strict_types=1);

namespace Tests\Feature\Generation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithPricing;
use Tests\TestCase;

final class GenerationFormPageTest extends TestCase
{
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

    public function test_the_page_gets_only_the_shared_props_pricing_and_limits(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $this->assertEqualsCanonicalizing(
                ['auth', 'errors', 'pricing', 'limits'],
                array_keys($page->toArray()['props']),
            ));
    }
}
