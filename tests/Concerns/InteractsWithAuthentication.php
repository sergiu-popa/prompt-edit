<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * @phpstan-require-extends TestCase
 */
trait InteractsWithAuthentication
{
    protected function givenAnAccount(string $email, string $password): User
    {
        return User::factory()->create(['email' => $email, 'password' => $password]);
    }

    /**
     * A sign-in submitted from the sign-in page, as the browser sends it.
     *
     * @param  array<string, string>  $headers
     */
    protected function attemptSignIn(string $email, string $password, array $headers = []): TestResponse
    {
        return $this->from('/login')->post('/login', ['email' => $email, 'password' => $password], $headers);
    }
}
