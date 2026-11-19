<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuthentication;
use Tests\TestCase;

final class SignOutTest extends TestCase
{
    use InteractsWithAuthentication;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->givenAnAccount('ada@example.com', 'correct-password');
        $this->attemptSignIn('ada@example.com', 'correct-password')->assertRedirect(route('dashboard'));
    }

    public function test_signing_out_ends_the_session_and_lands_on_the_welcome_page(): void
    {
        $this->post('/logout')->assertRedirect('/');

        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_signing_out_regenerates_the_csrf_token(): void
    {
        $tokenBeforeSignOut = $this->app['session.store']->token();

        $this->post('/logout');

        $tokenAfterSignOut = $this->app['session.store']->token();
        $this->assertSame(40, strlen($tokenAfterSignOut));
        $this->assertNotSame($tokenBeforeSignOut, $tokenAfterSignOut);
    }

    public function test_a_get_to_logout_does_not_sign_out(): void
    {
        $this->get('/logout')->assertMethodNotAllowed();

        $this->assertAuthenticated();
        $this->get('/dashboard')->assertOk();
    }

    public function test_a_guest_signing_out_is_sent_to_sign_in(): void
    {
        $this->post('/logout');

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
