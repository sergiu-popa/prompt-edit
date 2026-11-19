<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Auth\Events\Attempting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAuthentication;
use Tests\TestCase;

final class SignInTest extends TestCase
{
    use InteractsWithAuthentication;
    use RefreshDatabase;

    private const string CREDENTIALS_MESSAGE = 'These credentials do not match our records.';

    public function test_signing_in_lands_on_the_signed_in_home(): void
    {
        $user = $this->givenAnAccount('ada@example.com', 'correct-password');

        $this->attemptSignIn('ada@example.com', 'correct-password')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Generations/Create'));
    }

    public function test_a_guest_lands_on_the_page_they_asked_for_after_signing_in(): void
    {
        $this->givenAnAccount('ada@example.com', 'correct-password');

        $this->get('/dashboard?from=test')->assertRedirect('/login');

        $this->attemptSignIn('ada@example.com', 'correct-password')
            ->assertRedirect('/dashboard?from=test');
    }

    #[DataProvider('rejectedCredentials')]
    public function test_rejected_credentials_show_one_message_under_email_and_stay_a_guest(string $email): void
    {
        $this->givenAnAccount('ada@example.com', 'correct-password');

        $this->attemptSignIn($email, 'wrong-password')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => self::CREDENTIALS_MESSAGE])
            ->assertSessionHasInput('email', $email)
            ->assertSessionMissing('_old_input.password');

        $this->assertSame(['email'], session('errors')->getBag('default')->keys());
        $this->assertGuest();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedCredentials(): array
    {
        return [
            'wrong password' => ['ada@example.com'],
            'email with no account' => ['nobody@example.com'],
        ];
    }

    /**
     * @param  list<string>  $emptyFields
     */
    #[DataProvider('incompleteSubmissions')]
    public function test_an_empty_field_shows_a_field_message_without_an_attempt(string $email, string $password, array $emptyFields): void
    {
        Event::fake([Attempting::class]);

        $this->attemptSignIn($email, $password)
            ->assertRedirect('/login')
            ->assertSessionHasErrors($emptyFields);

        $this->assertSame($emptyFields, session('errors')->getBag('default')->keys());
        Event::assertNotDispatched(Attempting::class);
        $this->assertSame(0, RateLimiter::attempts(Str::lower($email) . '|127.0.0.1'));
        $this->assertGuest();
    }

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function incompleteSubmissions(): array
    {
        return [
            'both empty' => ['', '', ['email', 'password']],
            'email empty' => ['', 'correct-password', ['email']],
            'password empty' => ['ada@example.com', '', ['password']],
        ];
    }

    public function test_a_successful_sign_in_regenerates_the_session_id(): void
    {
        $this->givenAnAccount('ada@example.com', 'correct-password');
        $sessionId = Str::random(40);

        $this->withCookie(config('session.cookie'), $sessionId)
            ->attemptSignIn('ada@example.com', 'correct-password')
            ->assertRedirect(route('dashboard'));

        $this->assertNotSame($sessionId, $this->app['session.store']->getId());
    }

    /**
     * The control row: without it, the regeneration test above could pass because every
     * test request simply got a fresh session.
     */
    public function test_a_failed_sign_in_keeps_the_session_id(): void
    {
        $this->givenAnAccount('ada@example.com', 'correct-password');
        $sessionId = Str::random(40);

        $this->withCookie(config('session.cookie'), $sessionId)
            ->attemptSignIn('ada@example.com', 'wrong-password')
            ->assertRedirect('/login');

        $this->assertSame($sessionId, $this->app['session.store']->getId());
    }

    public function test_the_email_is_matched_whatever_its_case(): void
    {
        $user = $this->givenAnAccount('ada@example.com', 'correct-password');

        $this->attemptSignIn('ADA@example.com', 'correct-password')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }
}
