<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithGenerations;
use Tests\TestCase;

/**
 * AC 3: every signed-in page carries the balance the header shows, read from
 * the ledger, in integer n$.
 */
final class SharedCreditBalancePropTest extends TestCase
{
    use InteractsWithGenerations;
    use RefreshDatabase;

    public function test_the_balance_is_null_for_a_guest(): void
    {
        $this->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('credits', null));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function signedInPages(): array
    {
        return [
            'Generate' => ['dashboard'],
            'History' => ['generations.index'],
        ];
    }

    /**
     * The closure-scoped `has()` fails on any key it doesn't touch, and `where()`
     * compares strictly, so a sum sent as a string fails.
     */
    #[DataProvider('signedInPages')]
    public function test_the_balance_is_exactly_the_integer_amounts(string $route): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route($route))
            ->assertInertia(fn (Assert $page) => $page
                ->has('credits', fn (Assert $credits) => $credits
                    ->where('available_nanodollars', 1_000_000_000)
                    ->where('reserved_nanodollars', 0)
                    ->where('running_generations', 0)));
    }

    /**
     * The red fox holds 21,850,000 n$ while it runs.
     */
    public function test_an_active_hold_is_reserved(): void
    {
        $redFox = $this->givenTheRedFoxGeneration();

        $this->actingAs(User::query()->findOrFail($redFox->user_id))
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('credits.available_nanodollars', 1_000_000_000 - self::RED_FOX_HOLD)
                ->where('credits.reserved_nanodollars', self::RED_FOX_HOLD)
                ->where('credits.running_generations', 1));
    }

    public function test_another_users_entries_are_not_counted(): void
    {
        $user = User::factory()->withCredits(10_000_000)->create();
        $this->givenTheRedFoxGeneration();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('credits.available_nanodollars', 10_000_000)
                ->where('credits.reserved_nanodollars', 0));
    }
}
