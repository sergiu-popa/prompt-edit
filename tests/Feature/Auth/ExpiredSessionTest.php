<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Responses\RedirectExpiredSession;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithAuthentication;
use Tests\Concerns\SubmitsGenerations;
use Tests\TestCase;

/**
 * An expired session on any Inertia form lands back on its page, or on Sign in,
 * with "Your session expired. Please try again." (D-60), never a 419 page or
 * Inertia's error modal. CSRF is checked for real here: Laravel skips it in tests.
 */
final class ExpiredSessionTest extends TestCase
{
    use InteractsWithAuthentication;
    use RefreshDatabase;
    use SubmitsGenerations;

    /**
     * The headers Inertia's router sends.
     */
    private const array INERTIA_HEADERS = [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(PreventRequestForgery::class, fn (Application $app): PreventRequestForgery => new class($app, $app->make('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    public function test_an_expired_sign_in_goes_back_to_sign_in_with_the_notice(): void
    {
        $this->givenAnAccount('ada@example.com', 'correct-password');

        $this->attemptSignIn('ada@example.com', 'correct-password', self::INERTIA_HEADERS)
            ->assertStatus(303)
            ->assertRedirect('/login')
            ->assertInertiaFlash(RedirectExpiredSession::FLASH_KEY, RedirectExpiredSession::NOTICE);

        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->hasFlash(RedirectExpiredSession::FLASH_KEY, RedirectExpiredSession::NOTICE));

        $this->assertGuest();
        $this->assertSame(0, RateLimiter::attempts('ada@example.com|127.0.0.1'), 'The expired attempt counted as a failure.');
    }

    public function test_an_expired_create_account_goes_back_with_the_notice_and_creates_nothing(): void
    {
        $this->from(url('/register'))
            ->post(url('/register'), [
                'name' => 'Ada Lovelace',
                'email' => 'ada.new@example.com',
                'password' => 'correct-password',
                'password_confirmation' => 'correct-password',
            ], self::INERTIA_HEADERS)
            ->assertStatus(303)
            ->assertRedirect(url('/register'))
            ->assertInertiaFlash(RedirectExpiredSession::FLASH_KEY, RedirectExpiredSession::NOTICE);

        $this->assertSame(0, User::query()->count());
        $this->assertGuest();
    }

    public function test_an_expired_generate_goes_back_to_generate_with_the_notice_and_creates_nothing(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('dashboard'))
            ->post(route('generations.store'), $this->lighthouseFields(), self::INERTIA_HEADERS)
            ->assertStatus(303)
            ->assertRedirect(route('dashboard'))
            ->assertInertiaFlash(RedirectExpiredSession::FLASH_KEY, RedirectExpiredSession::NOTICE);

        $this->assertNothingWasCreated();
    }

    public function test_a_previous_page_on_another_host_is_never_the_target(): void
    {
        $this->post(url('/login'), ['email' => 'ada@example.com', 'password' => 'correct-password'], [
            ...self::INERTIA_HEADERS,
            'Referer' => 'https://attacker.example/register',
        ])
            ->assertStatus(303)
            ->assertRedirect(route('login'))
            ->assertInertiaFlash(RedirectExpiredSession::FLASH_KEY, RedirectExpiredSession::NOTICE);
    }

    public function test_without_a_previous_page_it_goes_to_sign_in(): void
    {
        $this->post(url('/register'), [], self::INERTIA_HEADERS)
            ->assertStatus(303)
            ->assertRedirect(route('login'))
            ->assertInertiaFlash(RedirectExpiredSession::FLASH_KEY, RedirectExpiredSession::NOTICE);
    }

    public function test_a_plain_json_call_keeps_its_419(): void
    {
        $this->postJson('/login', ['email' => 'ada@example.com', 'password' => 'correct-password'])
            ->assertStatus(419);
    }

    /**
     * A modern browser sends `Sec-Fetch-Site: same-origin`, which passes the CSRF check
     * without a token, so an expired session reaches `auth` as a guest instead.
     */
    public function test_a_guest_posting_to_a_signed_in_route_gets_the_notice_on_its_way_to_sign_in(): void
    {
        $this->from(route('dashboard'))
            ->post(route('generations.store'), $this->lighthouseFields(), [...self::INERTIA_HEADERS, 'Sec-Fetch-Site' => 'same-origin'])
            ->assertRedirect('/login')
            ->assertInertiaFlash(RedirectExpiredSession::FLASH_KEY, RedirectExpiredSession::NOTICE)
            ->assertSessionHas('url.intended', route('dashboard'));

        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->hasFlash(RedirectExpiredSession::FLASH_KEY, RedirectExpiredSession::NOTICE));

        $this->assertNothingWasCreated();
    }

    /**
     * The webhook route sits outside the web group, so the real CSRF check never reaches
     * it, and its own refusal of an unsigned delivery stays a 400 with no redirect (D-28).
     */
    public function test_the_webhook_route_keeps_its_own_answer_without_a_token(): void
    {
        config(['pixelforge.webhook_secret' => 'whsec_test_expired_session_4b8e1d6a2c9f3e7b']);

        $response = $this->post(route('webhooks.pixelforge'), ['id' => 'evt_unsigned'])
            ->assertStatus(400)
            ->assertHeaderMissing('Location');

        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_a_guest_opening_a_signed_in_page_gets_no_notice(): void
    {
        $this->get('/dashboard')
            ->assertRedirect('/login')
            ->assertInertiaFlashMissing(RedirectExpiredSession::FLASH_KEY);
    }
}
