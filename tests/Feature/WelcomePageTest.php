<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class WelcomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_is_rendered_by_the_welcome_inertia_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Welcome'));
    }

    public function test_a_guest_gets_no_signed_in_user_to_choose_the_calls_to_action(): void
    {
        $this->get('/')
            ->assertInertia(fn (Assert $page) => $page->component('Welcome')->where('auth.user', null));
    }

    public function test_a_signed_in_visitor_gets_their_user_to_choose_the_calls_to_action(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page->component('Welcome')->where('auth.user.id', $user->id));
    }
}
