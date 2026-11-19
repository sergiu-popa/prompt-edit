<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
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
