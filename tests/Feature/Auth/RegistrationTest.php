<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAuthentication;
use Tests\TestCase;

final class RegistrationTest extends TestCase
{
    use InteractsWithAuthentication;
    use RefreshDatabase;

    public function test_registering_creates_the_account_signs_in_and_lands_on_the_signed_in_home(): void
    {
        $this->register()
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $user = User::query()->sole();
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame('ada.new@example.com', $user->email);
        $this->assertTrue(Hash::check('correct-password', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    #[DataProvider('invalidRegistrations')]
    public function test_an_invalid_registration_shows_a_field_message_and_keeps_the_non_password_inputs(
        array $overrides,
        string $field,
        string $message,
    ): void {
        $this->givenAnAccount('ada@example.com', 'existing-password');

        $response = $this->register($overrides)
            ->assertRedirect('/register')
            ->assertSessionHasErrors([$field => $message])
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');

        foreach (['name', 'email'] as $kept) {
            $response->assertSessionHasInput($kept, $this->registrationInput($overrides)[$kept]);
        }

        $this->assertSame(1, User::query()->count());
        $this->assertGuest();
    }

    /**
     * Each registration starts from a valid one whose email is new; `ada@example.com` exists.
     *
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidRegistrations(): array
    {
        return [
            'missing name' => [['name' => ''], 'name', 'The name field is required.'],
            'name over 255 characters' => [['name' => str_repeat('a', 256)], 'name', 'The name field must not be greater than 255 characters.'],
            'invalid email' => [['email' => 'not-an-email'], 'email', 'The email field must be a valid email address.'],
            'email already registered' => [['email' => 'ada@example.com'], 'email', 'The email has already been taken.'],
            'email registered in another case' => [['email' => 'Ada@Example.com'], 'email', 'The email has already been taken.'],
            'password under 8 characters' => [['password' => 'short12', 'password_confirmation' => 'short12'], 'password', 'The password field must be at least 8 characters.'],
            'confirmation does not match' => [['password_confirmation' => 'other-password'], 'password', 'The password field confirmation does not match.'],
        ];
    }

    public function test_the_email_is_stored_lowercase(): void
    {
        $this->register(['email' => 'Ada@Example.COM'])->assertRedirect(route('dashboard'));

        $this->assertSame('ada@example.com', User::query()->sole()->email);
    }

    public function test_the_email_is_stored_without_surrounding_spaces(): void
    {
        $this->register(['email' => ' Ada@Example.com '])->assertRedirect(route('dashboard'));

        $this->assertSame('ada@example.com', User::query()->sole()->email);
    }

    public function test_a_name_of_255_characters_and_a_password_of_8_are_accepted(): void
    {
        $this->register(['name' => str_repeat('a', 255), 'password' => 'exactly8', 'password_confirmation' => 'exactly8'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, User::query()->count());
    }

    public function test_an_email_submitted_as_a_list_is_a_field_error(): void
    {
        $this->from('/register')
            ->post('/register', [...$this->registrationInput(), 'email' => ['ada@example.com']])
            ->assertRedirect('/register')
            ->assertSessionHasErrors(['email' => 'The email field must be a string.']);

        $this->assertSame(0, User::query()->count());
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function register(array $overrides = []): TestResponse
    {
        return $this->from('/register')->post('/register', $this->registrationInput($overrides));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function registrationInput(array $overrides = []): array
    {
        return [
            'name' => 'Ada Lovelace',
            'email' => 'ada.new@example.com',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
            ...$overrides,
        ];
    }
}
