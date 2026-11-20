<?php

namespace Database\Factories;

use App\Domain\Billing\Actions\GrantStarterCreditsAction;
use App\Domain\Billing\Enums\CreditLedgerEntryType;
use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A created user is granted the configured starter credit, as every real account is
 * (D-70), so a test that creates generations can hold them. `withCredits()` grants
 * another amount instead and `withoutCredits()` none, for a legacy account from
 * before balances. `make()` writes nothing.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The after-creating callback's key, so a state can replace or drop the grant.
     */
    private const string STARTER_GRANT = 'starter-grant';

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function configure(): static
    {
        $this->afterCreating->put(self::STARTER_GRANT, function (User $user): void {
            app(GrantStarterCreditsAction::class)->execute($user);
        });

        return $this;
    }

    /**
     * One grant of exactly this amount instead of the configured starter.
     */
    public function withCredits(int $nanodollars): static
    {
        return $this->newInstance([
            'afterCreating' => $this->afterCreating->merge([
                self::STARTER_GRANT => function (User $user) use ($nanodollars): void {
                    CreditLedgerEntry::query()->create([
                        'user_id' => $user->getKey(),
                        'type' => CreditLedgerEntryType::Grant,
                        'amount_nanodollars' => $nanodollars,
                    ]);
                },
            ]),
        ]);
    }

    /**
     * No grant at all: an account created before balances existed.
     */
    public function withoutCredits(): static
    {
        return $this->newInstance(['afterCreating' => $this->afterCreating->except(self::STARTER_GRANT)]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * An operator, who may open the operator report (D-62).
     */
    public function operator(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_operator' => true,
        ]);
    }
}
