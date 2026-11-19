<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAuthentication;
use Tests\TestCase;

/**
 * Hestia's nginx proxies to Apache's HTTPS vhost, and mod_remoteip rewrites `REMOTE_ADDR`
 * to the visitor, so PHP sees `HTTPS=on` and the real client IP natively. Laravel trusts no
 * proxy, so every forwarded header is ignored, whoever sends it (D-21).
 *
 * An absolute `https://` URL makes the test request carry exactly `HTTPS=on` and port 443,
 * which is what Apache hands PHP-FPM.
 */
final class HttpsBehindHestiaProxyTest extends TestCase
{
    use InteractsWithAuthentication;
    use RefreshDatabase;

    private const string HOST = 'prompt.sergiupopa.dev';

    private const string LOCKOUT_MESSAGE = 'Too many login attempts. Please try again in 60 seconds.';

    public function test_a_guest_on_https_is_sent_to_the_https_sign_in_page(): void
    {
        $this->get('https://' . self::HOST . '/dashboard')
            ->assertRedirect('https://' . self::HOST . '/login');
    }

    /**
     * The control row: the scheme follows `HTTPS`, so the row above can fail.
     */
    public function test_a_guest_on_plain_http_is_sent_to_the_http_sign_in_page(): void
    {
        $this->get('http://' . self::HOST . '/dashboard')
            ->assertRedirect('http://' . self::HOST . '/login');
    }

    public function test_signing_in_on_https_lands_on_the_https_signed_in_home(): void
    {
        $this->givenAnAccount('ada@example.com', 'correct-password');

        $this->from('https://' . self::HOST . '/login')
            ->post('https://' . self::HOST . '/login', ['email' => 'ada@example.com', 'password' => 'correct-password'])
            ->assertRedirect('https://' . self::HOST . '/dashboard');

        $this->assertAuthenticated();
    }

    #[DataProvider('proxyHops')]
    public function test_forwarded_headers_are_ignored_from_every_address(string $remoteAddress): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
            ->get('http://' . self::HOST . '/dashboard', [
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'attacker.example',
                'X-Forwarded-Port' => '443',
                'X-Forwarded-For' => '203.0.113.9',
                'Forwarded' => 'for=203.0.113.9;proto=https;host=attacker.example',
            ])
            ->assertRedirect('http://' . self::HOST . '/login');

        $this->assertSame($remoteAddress, $this->app->make('request')->ip());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function proxyHops(): array
    {
        return [
            'nginx loopback hop' => ['127.0.0.1'],
            'server public IP hop' => ['164.90.236.77'],
        ];
    }

    public function test_the_sign_in_throttle_keys_on_the_remote_address_not_a_forwarded_one(): void
    {
        $this->freezeTime();
        $this->givenAnAccount('ada@example.com', 'correct-password');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7']);

        foreach (range(1, 5) as $attempt) {
            $this->attemptSignIn('ada@example.com', 'wrong-password', ['X-Forwarded-For' => "203.0.113.{$attempt}"])
                ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        }

        $this->attemptSignIn('ada@example.com', 'correct-password', ['X-Forwarded-For' => '203.0.113.99'])
            ->assertSessionHasErrors(['email' => self::LOCKOUT_MESSAGE]);
        $this->assertGuest();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])
            ->attemptSignIn('ada@example.com', 'correct-password', ['X-Forwarded-For' => '198.51.100.7'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }
}
