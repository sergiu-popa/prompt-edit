<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SharedAuthUserPropTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_user_is_null_for_a_guest(): void
    {
        $this->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('auth.user', null));
    }

    /**
     * The closure-scoped `has()` fails on any key it doesn't touch, so a password hash,
     * remember token, timestamp or any other column would fail here.
     */
    public function test_the_user_is_exactly_their_id_name_and_email_for_a_signed_in_user(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('auth', fn (Assert $auth) => $auth
                    ->has('user', fn (Assert $sharedUser) => $sharedUser
                        ->where('id', $user->id)
                        ->where('name', 'Ada Lovelace')
                        ->where('email', 'ada@example.com'))));
    }
}
