<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FortifyRoutesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The automated form of `php artisan route:list`: Fortify registers exactly these
     * routes, so a Fortify upgrade that adds one fails here and gets read.
     */
    public function test_fortify_registers_only_the_routes_this_app_serves(): void
    {
        $fortifyRoutes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => Str::startsWith($route->getActionName(), ['Laravel\\Fortify\\', 'Laravel\\Passkeys\\']))
            ->map(fn (Route $route): string => $route->methods()[0] . ' /' . ltrim($route->uri(), '/'))
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'GET /login',
            'GET /register',
            'GET /user/confirm-password',
            'GET /user/confirmed-password-status',
            'POST /login',
            'POST /logout',
            'POST /register',
            'POST /user/confirm-password',
        ], $fortifyRoutes);
    }

    #[DataProvider('disabledFeatureRoutes')]
    public function test_a_disabled_feature_route_is_not_found_for_a_guest(string $method, string $uri): void
    {
        $this->call($method, $uri)->assertNotFound();
    }

    #[DataProvider('disabledFeatureRoutes')]
    public function test_a_disabled_feature_route_is_not_found_for_a_signed_in_user(string $method, string $uri): void
    {
        $this->actingAs(User::factory()->create())
            ->call($method, $uri)
            ->assertNotFound();
    }

    public function test_a_guest_opening_the_signed_in_home_is_sent_to_sign_in(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_a_signed_in_user_sees_the_signed_in_home(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Generations/Create'));
    }

    public function test_a_guest_sees_the_sign_in_page(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
    }

    public function test_a_guest_sees_the_create_account_page(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Register'));
    }

    #[DataProvider('guestOnlyRoutes')]
    public function test_a_signed_in_user_opening_a_guest_route_is_sent_to_the_signed_in_home(string $method, string $uri): void
    {
        $this->actingAs(User::factory()->create())
            ->call($method, $uri)
            ->assertRedirect(route('dashboard'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function guestOnlyRoutes(): array
    {
        return [
            'sign-in page' => ['GET', '/login'],
            'sign in' => ['POST', '/login'],
            'create-account page' => ['GET', '/register'],
            'create account' => ['POST', '/register'],
        ];
    }

    public function test_a_guest_submitting_an_empty_sign_in_gets_field_errors(): void
    {
        $this->from('/login')
            ->post('/login')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email', 'password']);
    }

    public function test_a_guest_submitting_an_empty_sign_up_gets_field_errors(): void
    {
        $this->from('/register')
            ->post('/register')
            ->assertRedirect('/register')
            ->assertSessionHasErrors(['name', 'email', 'password']);
    }

    #[DataProvider('signedInOnlyRoutes')]
    public function test_a_guest_opening_a_signed_in_route_is_sent_to_sign_in(string $method, string $uri): void
    {
        $this->call($method, $uri)->assertRedirect('/login');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function signedInOnlyRoutes(): array
    {
        return [
            'sign out' => ['POST', '/logout'],
            'password confirmation page' => ['GET', '/user/confirm-password'],
            'password confirmation' => ['POST', '/user/confirm-password'],
            'password confirmation status' => ['GET', '/user/confirmed-password-status'],
        ];
    }

    public function test_a_signed_in_user_signing_out_lands_on_the_welcome_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect('/');
    }

    public function test_the_password_confirmation_page_is_not_found_for_a_signed_in_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/user/confirm-password')
            ->assertNotFound();
    }

    /**
     * No page asks for a password confirmation, so the endpoint never checks a password
     * (D-61): a wrong and a right one both get 404, and nothing is confirmed.
     */
    #[DataProvider('passwordConfirmations')]
    public function test_a_password_confirmation_is_not_found_and_confirms_nothing(string $password): void
    {
        $this->actingAs(User::factory()->create(['password' => 'correct-password']))
            ->from('/dashboard')
            ->post('/user/confirm-password', ['password' => $password])
            ->assertNotFound()
            ->assertSessionMissing('auth.password_confirmed_at');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function passwordConfirmations(): array
    {
        return [
            'a wrong password' => ['wrong-password'],
            'the right password' => ['correct-password'],
        ];
    }

    public function test_the_password_confirmation_status_answers_json_for_a_signed_in_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/user/confirmed-password-status')
            ->assertOk()
            ->assertExactJson(['confirmed' => false]);
    }

    /**
     * Every route Fortify's route file defines for password reset, email verification,
     * profile and password updates, two-factor authentication and passkeys.
     *
     * @return array<string, array{string, string}>
     */
    public static function disabledFeatureRoutes(): array
    {
        return [
            'password reset request page' => ['GET', '/forgot-password'],
            'password reset link' => ['POST', '/forgot-password'],
            'password reset page' => ['GET', '/reset-password/a-token'],
            'password reset' => ['POST', '/reset-password'],
            'email verification notice' => ['GET', '/email/verify'],
            'email verification link' => ['GET', '/email/verify/1/a-hash'],
            'email verification resend' => ['POST', '/email/verification-notification'],
            'profile update' => ['PUT', '/user/profile-information'],
            'password update' => ['PUT', '/user/password'],
            'two-factor challenge page' => ['GET', '/two-factor-challenge'],
            'two-factor challenge' => ['POST', '/two-factor-challenge'],
            'two-factor enable' => ['POST', '/user/two-factor-authentication'],
            'two-factor confirm' => ['POST', '/user/confirmed-two-factor-authentication'],
            'two-factor disable' => ['DELETE', '/user/two-factor-authentication'],
            'two-factor QR code' => ['GET', '/user/two-factor-qr-code'],
            'two-factor secret key' => ['GET', '/user/two-factor-secret-key'],
            'two-factor recovery codes' => ['GET', '/user/two-factor-recovery-codes'],
            'two-factor recovery codes regenerate' => ['POST', '/user/two-factor-recovery-codes'],
            'passkey login options' => ['GET', '/passkeys/login/options'],
            'passkey login' => ['POST', '/passkeys/login'],
            'passkey confirm options' => ['GET', '/passkeys/confirm/options'],
            'passkey confirm' => ['POST', '/passkeys/confirm'],
            'passkey registration options' => ['GET', '/user/passkeys/options'],
            'passkey store' => ['POST', '/user/passkeys'],
            'passkey destroy' => ['DELETE', '/user/passkeys/1'],
        ];
    }
}
