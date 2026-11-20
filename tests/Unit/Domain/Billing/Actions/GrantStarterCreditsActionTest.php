<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\Actions;

use App\Domain\Billing\Actions\GrantStarterCreditsAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\TestCase;

/**
 * AC 2: one starter grant per account, whatever creates it (D-70). The users are
 * made without the factory's own grant, so only the action writes one.
 */
final class GrantStarterCreditsActionTest extends TestCase
{
    use InteractsWithCreditLedger;
    use RefreshDatabase;

    public function test_it_grants_the_default_starter_of_one_dollar(): void
    {
        $user = User::factory()->withoutCredits()->create();

        $this->grant($user);

        $this->assertSame([['grant', 1_000_000_000]], $this->topUpsFor($user));
    }

    public function test_a_second_call_adds_nothing(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $this->grant($user);

        $this->grant($user);

        $this->assertSame([['grant', 1_000_000_000]], $this->topUpsFor($user));
    }

    /**
     * Read at call time, not when the action is built.
     */
    public function test_the_configured_amount_wins(): void
    {
        $action = $this->app->make(GrantStarterCreditsAction::class);
        config(['credits.starter_grant_nanodollars' => 10_000_000]);
        $user = User::factory()->withoutCredits()->create();

        $action->execute($user);

        $this->assertSame([['grant', 10_000_000]], $this->topUpsFor($user));
    }

    /**
     * `phpunit.xml` clears `CREDITS_STARTER_GRANT`, so the suite sees the default.
     */
    public function test_an_empty_env_means_the_default(): void
    {
        $this->assertSame(1_000_000_000, config('credits.starter_grant_nanodollars'));
    }

    private function grant(User $user): void
    {
        $this->app->make(GrantStarterCreditsAction::class)->execute($user);
    }
}
