<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class UserTest extends TestCase
{
    public function test_password_is_hashed_when_assigned(): void
    {
        $user = User::factory()->make(['password' => 'plain-text-secret']);

        $this->assertNotSame('plain-text-secret', $user->password);
        $this->assertTrue(Hash::check('plain-text-secret', $user->password));
    }

    public function test_credentials_are_never_serialized(): void
    {
        $serialized = User::factory()->make()->toArray();

        $this->assertArrayNotHasKey('password', $serialized);
        $this->assertArrayNotHasKey('remember_token', $serialized);
    }

    public function test_a_user_is_not_an_operator_by_default(): void
    {
        $this->assertFalse(User::factory()->make()->is_operator);
        $this->assertTrue(User::factory()->operator()->make()->is_operator);
    }

    /**
     * Only `app:grant-operator` and the local seeder grant operator access (D-62), so mass
     * assignment, as registration and `app:upsert-user` use it, never sets the flag.
     */
    public function test_operator_access_is_never_mass_assigned(): void
    {
        $user = new User(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret-password', 'is_operator' => true]);

        $this->assertFalse($user->is_operator);
        $this->assertSame('ada@example.com', $user->email);
    }
}
