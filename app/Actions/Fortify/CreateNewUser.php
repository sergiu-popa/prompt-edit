<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Domain\Shared\Rules\StorableTextRule;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Fortify binds this as a singleton, so the request's IP and the limit are read at
 * call time, never injected.
 */
final class CreateNewUser implements CreatesNewUsers
{
    /**
     * The account limit's key, before the client IP.
     */
    public const string REGISTRATION_LIMIT_KEY_PREFIX = 'registrations:';

    private const int REGISTRATION_LIMIT_DECAY_SECONDS = 3_600;

    /**
     * Validate and create a newly registered user.
     *
     * Each client IP may create `auth.registrations_per_hour` accounts an hour (D-57).
     * The limit is checked before validation and counted only once an account exists,
     * so a typo never uses it up; concurrent sign-ups may overshoot by those in flight.
     *
     * The email is lowercased before the uniqueness check, so `Ada@…` and `ada@…` can't
     * become two accounts (D-18). Only a string is lowercased: anything else is left for
     * the `string` rule to reject. A name Postgres can't store (invalid UTF-8 or a NUL)
     * is a field error, not a 500.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $limitKey = self::REGISTRATION_LIMIT_KEY_PREFIX . request()->ip();

        if (RateLimiter::tooManyAttempts($limitKey, self::registrationsPerHour())) {
            throw ValidationException::withMessages([
                'email' => self::tooManyRegistrationsMessage(RateLimiter::availableIn($limitKey)),
            ]);
        }

        if (is_string($input['email'] ?? null)) {
            $input['email'] = Str::lower($input['email']);
        }

        /** @var array{name: string, email: string, password: string} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255', new StorableTextRule],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ])->validate();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        RateLimiter::hit($limitKey, self::REGISTRATION_LIMIT_DECAY_SECONDS);

        return $user;
    }

    private static function registrationsPerHour(): int
    {
        $limit = config('auth.registrations_per_hour');

        return is_int($limit) ? $limit : 3;
    }

    /**
     * The wait in whole minutes, rounded up and at least one.
     */
    private static function tooManyRegistrationsMessage(int $seconds): string
    {
        $minutes = max(1, intdiv($seconds + 59, 60));

        return sprintf(
            'Too many accounts were created from this network. Please try again in %d %s.',
            $minutes,
            $minutes === 1 ? 'minute' : 'minutes',
        );
    }
}
