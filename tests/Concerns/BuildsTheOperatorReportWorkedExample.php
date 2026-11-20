<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Billing\Actions\CalculateProviderCostAction;
use App\Domain\Billing\Actions\CalculateUserChargeAction;
use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Domain\Billing\ValueObjects\TokenRates;
use App\Domain\Billing\ValueObjects\TokenUsage;
use App\Domain\Generation\Models\Generation;
use App\Domain\Reporting\Actions\ShowOperatorReportAction;
use App\Domain\Reporting\DataTransferObjects\OperatorReportData;
use App\Domain\Reporting\ValueObjects\ReportRange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * PE-016's worked example (AC 11, 13 and 14), exact to the nanodollar: G1 and G2
 * succeeded, G3 failed and G4 is D-09's provider-cost-only row, at 5.00 / 10.00 / 40.00 /
 * 12.50 per 1M tokens.
 *
 * @phpstan-require-extends TestCase
 */
trait BuildsTheOperatorReportWorkedExample
{
    private const array WORKED_EXAMPLE_RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /**
     * G1 (A, 2026-11-16), G2 (B, the last seconds of 2026-11-16), G3 (A, failed) and G4
     * (A, created on 2026-11-16 and billed on 2026-11-17).
     */
    private function givenTheWorkedExample(User $userA, User $userB): void
    {
        $this->givenASucceededGeneration($userA, '2026-11-16T10:00:00Z', [5, 0, 280, 312], 15_125_000, 18_906_250);
        $this->givenASucceededGeneration($userB, '2026-11-16T23:59:58Z', [9, 0, 1_100, 1_234], 59_470_000, 74_337_500);
        Generation::factory()->failed()->create(['user_id' => $userA->id, 'completed_at' => CarbonImmutable::parse('2026-11-16T12:00:00Z')]);
        Generation::factory()->providerCostOnly()->create([
            'user_id' => $userA->id,
            'created_at' => CarbonImmutable::parse('2026-11-16T23:59:50Z'),
            'completed_at' => CarbonImmutable::parse('2026-11-17T00:00:12Z'),
            ...$this->usage([6, 0, 280, 500], 17_480_000),
        ]);
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
            'rates' => self::WORKED_EXAMPLE_RATES,
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
            TokenRates::from(self::WORKED_EXAMPLE_RATES),
        );
    }

    private function report(string $startDate, string $endDate): OperatorReportData
    {
        return $this->app->make(ShowOperatorReportAction::class)->execute(ReportRange::fromDates($startDate, $endDate));
    }
}
