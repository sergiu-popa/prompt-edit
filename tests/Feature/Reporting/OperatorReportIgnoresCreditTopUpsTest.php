<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Billing\Actions\GrantOpeningCreditsAction;
use App\Domain\Billing\Actions\GrantStarterCreditsAction;
use App\Domain\Billing\Actions\PurchaseCreditsAction;
use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Domain\Reporting\DataTransferObjects\ReportUserFigures;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTheOperatorReportWorkedExample;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\TestCase;

/**
 * AC 19: grants and purchases are credit, not spend. Every top-up below is written inside
 * the report's range, so a report that summed ledger entries by date would count them.
 */
final class OperatorReportIgnoresCreditTopUpsTest extends TestCase
{
    use BuildsTheOperatorReportWorkedExample;
    use InteractsWithCreditLedger;
    use RefreshDatabase;

    public function test_grants_purchases_and_a_topped_up_new_user_leave_the_report_unchanged(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17T12:00:00Z'));
        $userA = User::factory()->withoutCredits()->create(['email' => 'a@example.com']);
        $userB = User::factory()->withoutCredits()->create(['email' => 'b@example.com']);
        $this->givenTheWorkedExample($userA, $userB);

        $before = $this->report('2026-11-16', '2026-11-17')->toArray();

        $this->assertSame(2, $this->app->make(GrantOpeningCreditsAction::class)->execute());
        $this->assertSame([['grant', 1_018_906_250]], $this->topUpsFor($userA));
        $this->assertSame([['grant', 1_074_337_500]], $this->topUpsFor($userB));

        $purchaseCredits = $this->app->make(PurchaseCreditsAction::class);
        $purchaseCredits->execute($userA, new Nanodollars(5_000_000_000), (string) Str::uuid());
        $purchaseCredits->execute($userB, new Nanodollars(1_000_000_000), (string) Str::uuid());

        $userC = User::factory()->withoutCredits()->create(['email' => 'c@example.com']);
        $this->app->make(GrantStarterCreditsAction::class)->execute($userC);
        $purchaseCredits->execute($userC, new Nanodollars(10_000_000_000), (string) Str::uuid());
        $this->assertSame([['grant', 1_000_000_000], ['purchase', 10_000_000_000]], $this->topUpsFor($userC));

        $after = $this->report('2026-11-16', '2026-11-17');

        $this->assertSame($before, $after->toArray());
        $this->assertSame(['succeeded' => 3, 'spend_nanodollars' => 93_243_750, 'provider_cost_nanodollars' => 92_075_000, 'profit_nanodollars' => 1_168_750], $before['totals']);
        $this->assertNotContains($userC->id, array_map(fn (ReportUserFigures $user): int => $user->userId, $after->users));
    }
}
