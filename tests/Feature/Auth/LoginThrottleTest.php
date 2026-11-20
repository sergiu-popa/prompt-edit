<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithAuthentication;
use Tests\TestCase;

/**
 * Fortify's pipeline throttle (D-17): 5 failures per minute per email + IP, cleared by a
 * success, reported as a field error. No route limiter counts successful sign-ins.
 */
final class LoginThrottleTest extends TestCase
{
    use InteractsWithAuthentication;
    use RefreshDatabase;

    /**
     * The headers Inertia's router sends, so the lockout is checked on the path the browser takes.
     */
    private const array INERTIA_HEADERS = [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->givenAnAccount('ada@example.com', 'correct-password');
    }

    public function test_the_sixth_attempt_is_refused_inline_even_with_the_correct_password(): void
    {
        $this->givenFailedSignIns(5, 'ada@example.com');

        $this->attemptSignIn('ada@example.com', 'correct-password', self::INERTIA_HEADERS)
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'Too many login attempts. Please try again in 60 seconds.']);

        $this->assertGuest();
    }

    /**
     * AC 15 (PE-015): the sixth wrong password within a minute is a redirect, never a
     * 429 page, and Sign in renders the lockout under Email.
     */
    public function test_the_sixth_wrong_password_renders_the_lockout_inline_not_a_429(): void
    {
        $this->givenFailedSignIns(5, 'ada@example.com');

        $response = $this->attemptSignIn('ada@example.com', 'wrong-password', self::INERTIA_HEADERS);

        $response->assertStatus(302)->assertRedirect('/login');

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('errors.email', 'Too many login attempts. Please try again in 60 seconds.'));

        $this->assertGuest();
    }

    public function test_the_lockout_still_holds_59_seconds_later(): void
    {
        $this->givenFailedSignIns(5, 'ada@example.com');

        $this->travel(59)->seconds();

        $this->attemptSignIn('ada@example.com', 'correct-password', self::INERTIA_HEADERS)
            ->assertSessionHasErrors(['email' => 'Too many login attempts. Please try again in 1 seconds.']);
        $this->assertGuest();
    }

    public function test_the_correct_password_works_once_the_window_has_passed(): void
    {
        $this->givenFailedSignIns(5, 'ada@example.com');

        $this->travel(60)->seconds();

        $this->attemptSignIn('ada@example.com', 'correct-password')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_another_email_from_the_same_ip_is_unaffected(): void
    {
        $this->givenAnAccount('grace@example.com', 'correct-password');
        $this->givenFailedSignIns(5, 'ada@example.com');

        $this->attemptSignIn('grace@example.com', 'correct-password')
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_the_locked_email_from_another_ip_is_unaffected(): void
    {
        $this->givenFailedSignIns(5, 'ada@example.com');

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->attemptSignIn('ada@example.com', 'correct-password')
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_failures_in_any_letter_case_share_one_bucket(): void
    {
        $this->givenFailedSignIns(3, 'ADA@example.com');
        $this->givenFailedSignIns(2, 'ada@example.com');

        $this->attemptSignIn('ada@example.com', 'correct-password')
            ->assertSessionHasErrors(['email' => 'Too many login attempts. Please try again in 60 seconds.']);
        $this->assertGuest();
    }

    public function test_a_successful_sign_in_clears_the_failure_count(): void
    {
        $this->givenFailedSignIns(4, 'ada@example.com');
        $this->attemptSignIn('ada@example.com', 'correct-password')->assertRedirect(route('dashboard'));
        $this->post('/logout');

        $this->givenFailedSignIns(4, 'ada@example.com');

        $this->attemptSignIn('ada@example.com', 'correct-password')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_successful_sign_ins_never_count_towards_the_limit(): void
    {
        foreach (range(1, 10) as $attempt) {
            $this->attemptSignIn('ada@example.com', 'correct-password')
                ->assertRedirect(route('dashboard'))
                ->assertSessionHasNoErrors();
            $this->assertAuthenticated();

            $this->post('/logout')->assertRedirect('/');
            $this->assertGuest();
        }
    }

    private function givenFailedSignIns(int $times, string $email): void
    {
        foreach (range(1, $times) as $attempt) {
            $this->attemptSignIn($email, 'wrong-password')
                ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        }
    }
}
