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
}
