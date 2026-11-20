<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Each client IP may create 3 accounts an hour (D-57), counted when an account is
 * created, so a typo never uses one up. Past that, sign-up shows an inline message
 * under Email and creates nothing.
 */
final class RegistrationThrottleTest extends TestCase
{
    use RefreshDatabase;

    private const string IP = '198.51.100.7';

    private const string OTHER_IP = '203.0.113.9';

    private int $registrations = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
    }

    public function test_the_fourth_account_from_one_ip_within_an_hour_is_refused_inline(): void
    {
        $this->givenAccountsCreatedFrom(self::IP, 3);

        $this->registerFrom(self::IP)
            ->assertRedirect('/register')
            ->assertSessionHasErrors(['email' => 'Too many accounts were created from this network. Please try again in 60 minutes.']);

        $this->assertSame(3, User::query()->count());
        $this->assertGuest();
    }

    public function test_failed_validations_do_not_count(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->registerFrom(self::IP, ['password_confirmation' => 'other-password'])
                ->assertSessionHasErrors('password');
        }

        $this->givenAccountsCreatedFrom(self::IP, 3);

        $this->assertSame(3, User::query()->count());
    }

    public function test_another_ip_is_unaffected(): void
    {
        $this->givenAccountsCreatedFrom(self::IP, 3);

        $this->registerFrom(self::OTHER_IP)->assertRedirect(route('dashboard'));

        $this->assertSame(4, User::query()->count());
    }

    /**
     * No proxy is trusted (D-21), so a forwarded address never resets the count.
     */
    public function test_a_forwarded_for_header_is_ignored(): void
    {
        $this->givenAccountsCreatedFrom(self::IP, 3);

        $this->registerFrom(self::IP, headers: ['X-Forwarded-For' => self::OTHER_IP])
            ->assertSessionHasErrors('email');

        $this->assertSame(3, User::query()->count());
    }

    public function test_the_last_second_of_the_hour_reads_one_minute(): void
    {
        $this->givenAccountsCreatedFrom(self::IP, 3);

        $this->travel(3_599)->seconds();

        $this->registerFrom(self::IP)
            ->assertSessionHasErrors(['email' => 'Too many accounts were created from this network. Please try again in 1 minute.']);
    }

    public function test_a_partial_minute_rounds_up(): void
    {
        $this->givenAccountsCreatedFrom(self::IP, 3);

        $this->travel(3_600 - 61)->seconds();

        $this->registerFrom(self::IP)
            ->assertSessionHasErrors(['email' => 'Too many accounts were created from this network. Please try again in 2 minutes.']);
    }

    public function test_an_account_can_be_created_again_after_an_hour(): void
    {
        $this->givenAccountsCreatedFrom(self::IP, 3);

        $this->travel(3_600)->seconds();

        $this->registerFrom(self::IP)->assertRedirect(route('dashboard'));
        $this->assertSame(4, User::query()->count());
    }

    public function test_the_limit_is_configurable(): void
    {
        config(['auth.registrations_per_hour' => 1]);

        $this->givenAccountsCreatedFrom(self::IP, 1);

        $this->registerFrom(self::IP)->assertSessionHasErrors('email');
        $this->assertSame(1, User::query()->count());
    }

    /**
     * The dev containers set 1,000 for E2E; `phpunit.xml` clears it (D-48).
     */
    public function test_the_suite_runs_with_the_default_of_three(): void
    {
        $this->assertSame(3, config('auth.registrations_per_hour'));
    }

    /**
     * Fortify signs a new account in, so each one signs out before the next.
     */
    private function givenAccountsCreatedFrom(string $ip, int $count): void
    {
        for ($created = 0; $created < $count; $created++) {
            $this->registerFrom($ip)->assertRedirect(route('dashboard'));
            $this->app['auth']->guard()->logout();
        }
    }

    /**
     * @param  array<string, string>  $overrides
     * @param  array<string, string>  $headers
     */
    private function registerFrom(string $ip, array $overrides = [], array $headers = []): TestResponse
    {
        $this->registrations++;

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from('/register')
            ->post('/register', [
                'name' => 'Ada Lovelace',
                'email' => sprintf('ada.%d@example.com', $this->registrations),
                'password' => 'correct-password',
                'password_confirmation' => 'correct-password',
                ...$overrides,
            ], $headers);
    }
}
