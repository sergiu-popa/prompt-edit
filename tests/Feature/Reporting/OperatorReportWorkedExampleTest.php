<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Billing\Actions\CalculateProviderCostAction;
use App\Domain\Billing\Actions\CalculateUserChargeAction;
use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Domain\Billing\ValueObjects\TokenRates;
use App\Domain\Billing\ValueObjects\TokenUsage;
use App\Domain\Generation\Models\Generation;
use App\Domain\Reporting\Actions\ShowOperatorReportAction;
use App\Domain\Reporting\DataTransferObjects\OperatorReportData;
use App\Domain\Reporting\DataTransferObjects\ReportFigures;
use App\Domain\Reporting\DataTransferObjects\ReportUserFigures;
use App\Domain\Reporting\ValueObjects\ReportRange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The story's worked example (AC 11, 13 and 14), exact to the nanodollar: G1 and G2
 * succeeded, G3 failed and G4 is D-09's provider-cost-only row, at 5.00 / 10.00 / 40.00 /
 * 12.50 per 1M tokens.
 */
final class OperatorReportWorkedExampleTest extends TestCase
{
    use RefreshDatabase;

    private const array RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create(['email' => 'a@example.com']);
        $this->userB = User::factory()->create(['email' => 'b@example.com']);

        $this->givenASucceededGeneration($this->userA, '2026-11-16T10:00:00Z', [5, 0, 280, 312], 15_125_000, 18_906_250);
        $this->givenASucceededGeneration($this->userB, '2026-11-16T23:59:58Z', [9, 0, 1_100, 1_234], 59_470_000, 74_337_500);
        Generation::factory()->failed()->create(['user_id' => $this->userA->id, 'completed_at' => CarbonImmutable::parse('2026-11-16T12:00:00Z')]);
        Generation::factory()->providerCostOnly()->create([
            'user_id' => $this->userA->id,
            'created_at' => CarbonImmutable::parse('2026-11-16T23:59:50Z'),
            'completed_at' => CarbonImmutable::parse('2026-11-17T00:00:12Z'),
            ...$this->usage([6, 0, 280, 500], 17_480_000),
        ]);
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
     * Each cost and charge is first checked through the billing actions, so the example
     * stands on the app's own pricing.
     *
     * @param  array{int, int, int, int}  $tokens  input text, input image, output and reasoning
     */
    private function givenASucceededGeneration(User $user, string $completedAt, array $tokens, int $providerCost, int $userCharge): void
    {
        Generation::factory()->succeeded()->create([
            'user_id' => $user->id,
            'completed_at' => CarbonImmutable::parse($completedAt),
            ...$this->usage($tokens, $providerCost),
            'user_charge_nanodollars' => $userCharge,
        ]);

        $this->assertSame($userCharge, $this->app->make(CalculateUserChargeAction::class)->execute($this->providerCostOf($tokens))->amount);
    }

    /**
     * @param  array{int, int, int, int}  $tokens
     * @return array<string, mixed>
     */
    private function usage(array $tokens, int $providerCost): array
    {
        $this->assertSame($providerCost, $this->providerCostOf($tokens)->amount);

        return [
            'input_text_tokens' => $tokens[0],
            'input_image_tokens' => $tokens[1],
            'output_tokens' => $tokens[2],
            'reasoning_tokens' => $tokens[3],
            'rates' => self::RATES,
            'provider_cost_nanodollars' => $providerCost,
        ];
    }

    /**
     * @param  array{int, int, int, int}  $tokens
     */
    private function providerCostOf(array $tokens): Nanodollars
    {
        return $this->app->make(CalculateProviderCostAction::class)->execute(
            TokenUsage::from([
                'input_text_tokens' => $tokens[0],
                'input_image_tokens' => $tokens[1],
                'output_tokens' => $tokens[2],
                'reasoning_tokens' => $tokens[3],
            ]),
            TokenRates::from(self::RATES),
        );
    }

    private function report(string $startDate, string $endDate): OperatorReportData
    {
        return $this->app->make(ShowOperatorReportAction::class)->execute(ReportRange::fromDates($startDate, $endDate));
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
