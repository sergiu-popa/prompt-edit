<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Factories;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\TestCase;

/**
 * Factory users are funded the way every real account is (D-70).
 */
final class UserFactoryTest extends TestCase
{
    use InteractsWithCreditLedger;
    use RefreshDatabase;

    public function test_a_created_user_has_one_starter_grant(): void
    {
        $user = User::factory()->create();

        $this->assertSame([['grant', 1_000_000_000]], $this->topUpsFor($user));
    }

    public function test_the_starter_grant_follows_the_config(): void
    {
        config(['credits.starter_grant_nanodollars' => 10_000_000]);

        $this->assertSame([['grant', 10_000_000]], $this->topUpsFor(User::factory()->create()));
    }

    public function test_each_created_user_gets_its_own_grant(): void
    {
        $users = User::factory()->count(2)->create();

        foreach ($users as $user) {
            $this->assertSame([['grant', 1_000_000_000]], $this->topUpsFor($user));
        }
    }

    public function test_with_credits_grants_exactly_that_amount_instead(): void
    {
        $user = User::factory()->withCredits(86_312_500)->create();

        $this->assertSame([['grant', 86_312_500]], $this->topUpsFor($user));
    }

    public function test_without_credits_grants_nothing(): void
    {
        $user = User::factory()->withoutCredits()->create();

        $this->assertSame([], $this->topUpsFor($user));
    }

    public function test_the_last_credit_state_wins(): void
    {
        $user = User::factory()->withCredits(5)->withoutCredits()->create();
        $other = User::factory()->withoutCredits()->withCredits(7)->create();

        $this->assertSame([], $this->topUpsFor($user));
        $this->assertSame([['grant', 7]], $this->topUpsFor($other));
    }

    public function test_a_made_user_writes_nothing(): void
    {
        User::factory()->make();

        $this->assertSame(0, DB::table('credit_ledger_entries')->count());
    }
}
