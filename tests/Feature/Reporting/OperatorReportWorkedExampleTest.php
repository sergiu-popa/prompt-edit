<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\DataTransferObjects\OperatorReportData;
use App\Domain\Reporting\DataTransferObjects\ReportFigures;
use App\Domain\Reporting\DataTransferObjects\ReportUserFigures;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTheOperatorReportWorkedExample;
use Tests\TestCase;

/**
 * The story's worked example (AC 11, 13 and 14), exact to the nanodollar.
 */
final class OperatorReportWorkedExampleTest extends TestCase
{
    use BuildsTheOperatorReportWorkedExample;
    use RefreshDatabase;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create(['email' => 'a@example.com']);
        $this->userB = User::factory()->create(['email' => 'b@example.com']);

        $this->givenTheWorkedExample($this->userA, $this->userB);
    }

    public function test_each_day_shows_its_count_spend_provider_cost_and_profit(): void
    {
        $report = $this->report('2026-11-16', '2026-11-17');

        $this->assertSame(['2026-11-17', '2026-11-16'], array_keys($report->days));
        $this->assertFigures([2, 93_243_750, 74_595_000, 18_648_750], $report->days['2026-11-16']);
        $this->assertFigures([1, 0, 17_480_000, -17_480_000], $report->days['2026-11-17']);
    }

    public function test_each_user_shows_their_totals_by_spend_descending(): void
    {
        $report = $this->report('2026-11-16', '2026-11-17');

        $this->assertSame([$this->userB->id, $this->userA->id], array_map(fn (ReportUserFigures $user): int => $user->userId, $report->users));
        $this->assertSame(['b@example.com', 'a@example.com'], array_map(fn (ReportUserFigures $user): string => $user->email, $report->users));
        $this->assertFigures([1, 74_337_500, 59_470_000, 14_867_500], $report->users[0]->figures);
        $this->assertFigures([2, 18_906_250, 32_605_000, -13_698_750], $report->users[1]->figures);
    }

    public function test_the_totals_equal_the_sum_of_the_days_and_the_sum_of_the_users(): void
    {
        $report = $this->report('2026-11-16', '2026-11-17');

        $this->assertFigures([3, 93_243_750, 92_075_000, 1_168_750], $report->totals());
        $this->assertFigures([3, 93_243_750, 92_075_000, 1_168_750], self::sum(array_values($report->days)));
        $this->assertFigures([3, 93_243_750, 92_075_000, 1_168_750], self::sum(array_map(fn (ReportUserFigures $user): ReportFigures => $user->figures, $report->users)));
        $this->assertSame(['succeeded' => 3, 'spend_nanodollars' => 93_243_750, 'provider_cost_nanodollars' => 92_075_000, 'profit_nanodollars' => 1_168_750], $report->toArray()['totals']);
    }

    public function test_each_users_totals_are_the_sum_of_their_daily_contributions(): void
    {
        $range = $this->report('2026-11-16', '2026-11-17');
        $sixteenth = $this->report('2026-11-16', '2026-11-16');
        $seventeenth = $this->report('2026-11-17', '2026-11-17');

        foreach ($range->users as $user) {
            $this->assertEquals(
                $user->figures,
                self::sum([...self::figuresOf($sixteenth, $user->userId), ...self::figuresOf($seventeenth, $user->userId)]),
            );
        }

        $this->assertSame(['b@example.com', 'a@example.com'], array_map(fn (ReportUserFigures $user): string => $user->email, $sixteenth->users));
        $this->assertSame(['a@example.com'], array_map(fn (ReportUserFigures $user): string => $user->email, $seventeenth->users));
    }

    /**
     * @return list<ReportFigures>
     */
    private static function figuresOf(OperatorReportData $report, int $userId): array
    {
        return array_values(array_map(
            fn (ReportUserFigures $user): ReportFigures => $user->figures,
            array_filter($report->users, fn (ReportUserFigures $user): bool => $user->userId === $userId),
        ));
    }

    /**
     * @param  list<ReportFigures>  $figures
     */
    private static function sum(array $figures): ReportFigures
    {
        return array_reduce($figures, fn (ReportFigures $sum, ReportFigures $next): ReportFigures => $sum->plus($next), ReportFigures::zero());
    }

    /**
     * @param  array{int, int, int, int}  $expected  succeeded, spend, provider cost and profit
     */
    private function assertFigures(array $expected, ReportFigures $figures): void
    {
        $this->assertSame($expected, [$figures->succeeded, $figures->spend->amount, $figures->providerCost->amount, $figures->profit()->amount]);
    }
}
