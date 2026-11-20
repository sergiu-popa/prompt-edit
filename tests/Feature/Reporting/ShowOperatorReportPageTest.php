<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Generation\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The page's props with the default range (AC 5, 7 and 12): the last 30 UTC days, every
 * one listed, and every figure an integer of n$.
 */
final class ShowOperatorReportPageTest extends TestCase
{
    use RefreshDatabase;

    private const array FIGURES = ['succeeded', 'spend_nanodollars', 'provider_cost_nanodollars', 'profit_nanodollars'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-18T12:00:00Z'));
    }

    public function test_the_default_range_is_the_last_30_utc_days_with_its_limits(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->get('/report')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reporting/Show')
                ->where('range', ['start_date' => '2026-10-20', 'end_date' => '2026-11-18'])
                ->where('limits', ['max_days' => 92, 'today' => '2026-11-18']));
    }

    public function test_every_day_is_listed_newest_first_with_zeros(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->get('/report')
            ->assertInertia(fn (Assert $page) => $page
                ->has('report.days', 30)
                ->where('report.days.0', ['date' => '2026-11-18', 'succeeded' => 0, 'spend_nanodollars' => 0, 'provider_cost_nanodollars' => 0, 'profit_nanodollars' => 0])
                ->where('report.days.29.date', '2026-10-20')
                ->where('report.users', [])
                ->where('report.totals', ['succeeded' => 0, 'spend_nanodollars' => 0, 'provider_cost_nanodollars' => 0, 'profit_nanodollars' => 0]));
    }

    /**
     * The closure-scoped `has()` fails on any key it doesn't touch, so a user row carries
     * exactly its id, its email and the four figures.
     */
    public function test_a_succeeded_generation_shows_on_its_day_and_in_its_users_row(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        Generation::factory()->succeeded()->create(['user_id' => $user->id, 'completed_at' => CarbonImmutable::parse('2026-11-17T09:00:00Z')]);

        $this->actingAs(User::factory()->operator()->create())
            ->get('/report')
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.days.1', ['date' => '2026-11-17', 'succeeded' => 1, 'spend_nanodollars' => 109_312_500, 'provider_cost_nanodollars' => 87_450_000, 'profit_nanodollars' => 21_862_500])
                ->has('report.users', 1)
                ->has('report.users.0', fn (Assert $row) => $row
                    ->where('user_id', $user->id)
                    ->where('email', 'ada@example.com')
                    ->where('succeeded', 1)
                    ->where('spend_nanodollars', 109_312_500)
                    ->where('provider_cost_nanodollars', 87_450_000)
                    ->where('profit_nanodollars', 21_862_500))
                ->where('report.totals.succeeded', 1));
    }

    /**
     * AC 12: integers on the wire, never floats or decimal strings.
     */
    public function test_every_figure_is_an_integer(): void
    {
        $user = User::factory()->create();
        Generation::factory()->succeeded()->count(2)->create(['user_id' => $user->id, 'completed_at' => CarbonImmutable::parse('2026-11-17T09:00:00Z')]);
        Generation::factory()->providerCostOnly()->create(['completed_at' => CarbonImmutable::parse('2026-11-16T09:00:00Z')]);

        $this->actingAs(User::factory()->operator()->create())
            ->get('/report')
            ->assertInertia(function (Assert $page): void {
                foreach (self::FIGURES as $figure) {
                    $page->whereType("report.totals.{$figure}", 'integer');

                    foreach ([0, 1, 2, 29] as $day) {
                        $page->whereType("report.days.{$day}.{$figure}", 'integer');
                    }

                    foreach ([0, 1] as $user) {
                        $page->whereType("report.users.{$user}.{$figure}", 'integer');
                    }
                }

                $page->whereType('limits.max_days', 'integer')
                    ->where('report.days.2.profit_nanodollars', -87_450_000);
            });
    }
}
