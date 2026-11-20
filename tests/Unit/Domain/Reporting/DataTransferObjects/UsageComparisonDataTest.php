<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Reporting\DataTransferObjects;

use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Domain\Reporting\DataTransferObjects\UsageComparisonData;
use App\Domain\Reporting\DataTransferObjects\UsageFigures;
use App\Domain\Reporting\Enums\UsageAvailability;
use App\Support\Pixelforge\DataTransferObjects\UsageReportData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The day-by-day comparison (AC 5–10) with the PE-016 worked example's figures, clock at
 * 2026-11-18T12:00:00Z, range 2026-11-15 to 2026-11-18: G1 + G2 on 11-16, G4 on 11-17.
 */
final class UsageComparisonDataTest extends TestCase
{
    private const string TODAY = '2026-11-18';

    public function test_every_day_and_the_total_match(): void
    {
        $comparison = self::compared(self::pixelforgeRows());

        foreach ($comparison->days as $date => $line) {
            $this->assertTrue($line->matches(), $date);
        }

        $this->assertTrue($comparison->total->matches());
        $this->assertFalse($comparison->hasMismatch());
        $this->assertSame(UsageAvailability::Available, $comparison->availability);
        $this->assertSame(['2026-11-18', '2026-11-17', '2026-11-16', '2026-11-15'], array_keys($comparison->days));
        $this->assertSame(92_075_000, $comparison->total->recorded->providerCost->amount);
        $this->assertSame(92_075_000, $comparison->total->pixelforge?->providerCost->amount);
    }

    /**
     * AC 7: one nanodollar on 11-17 mismatches that day and the total, by exactly +1.
     */
    public function test_one_nanodollar_more_mismatches_the_day_and_the_total(): void
    {
        $rows = self::pixelforgeRows();
        $rows['2026-11-17']['cost_usd'] = '0.017480001';

        $comparison = self::compared($rows);
        $array = $comparison->toArray();

        $this->assertFalse($comparison->days['2026-11-17']->matches());
        $this->assertTrue($comparison->days['2026-11-16']->matches());
        $this->assertFalse($comparison->total->matches());
        $this->assertTrue($comparison->hasMismatch());
        $this->assertSame(
            ['provider_cost_nanodollars' => 1, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0],
            $array['days'][1]['delta'],
        );
        $this->assertSame(1, $array['total']['delta']['provider_cost_nanodollars']);
    }

    /**
     * AC 8: one more `[succeed]` generation with the production key, outside production.
     */
    public function test_a_generation_made_elsewhere_shows_its_deltas(): void
    {
        $rows = self::pixelforgeRows();
        $rows['2026-11-17'] = self::row('2026-11-17', '0.032605000', 11, 0, 560, 812, 2);

        $array = self::compared($rows)->toArray();

        $this->assertFalse($array['days'][1]['matches']);
        $this->assertSame(
            ['provider_cost_nanodollars' => 15_125_000, 'input_text_tokens' => 5, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 312],
            $array['days'][1]['delta'],
        );
        $this->assertTrue($array['has_mismatch']);
    }

    /**
     * @return array<string, array{string, int, int, int, int}>
     */
    public static function tokenOnlyDifferences(): array
    {
        return [
            'one input text token' => ['input_text_tokens', 7, 0, 280, 500],
            'one input image token' => ['input_image_tokens', 6, 1, 280, 500],
            'one output token' => ['output_tokens', 6, 0, 281, 500],
            'one reasoning token' => ['reasoning_tokens', 6, 0, 280, 501],
        ];
    }

    #[DataProvider('tokenOnlyDifferences')]
    public function test_a_token_only_difference_is_a_mismatch(string $field, int $text, int $image, int $output, int $reasoning): void
    {
        $rows = self::pixelforgeRows();
        $rows['2026-11-17'] = self::row('2026-11-17', '0.017480000', $text, $image, $output, $reasoning, 1);

        $array = self::compared($rows)->toArray();

        $this->assertFalse($array['days'][1]['matches']);
        $this->assertSame(1, $array['days'][1]['delta'][$field]);
        $this->assertSame(0, $array['days'][1]['delta']['provider_cost_nanodollars']);
    }

    /**
     * Generation counts are shown but never compared (requirements §6).
     */
    public function test_a_count_only_difference_still_matches(): void
    {
        $rows = self::pixelforgeRows();
        $rows['2026-11-16']['generations'] = 5;

        $comparison = self::compared($rows);
        $array = $comparison->toArray();

        $this->assertTrue($comparison->days['2026-11-16']->matches());
        $this->assertFalse($comparison->hasMismatch());
        $this->assertSame(5, $array['days'][2]['pixelforge']['generations']);
        $this->assertSame(2, $array['days'][2]['recorded']['generations']);
        $this->assertArrayNotHasKey('generations', $array['days'][2]['delta']);
    }

    /**
     * AC 10: 0 = 0 is a Match.
     */
    public function test_zero_days_match(): void
    {
        $array = self::compared(self::pixelforgeRows())->toArray();

        foreach ([$array['days'][0], $array['days'][3]] as $day) {
            $this->assertTrue($day['matches']);
            $this->assertSame(0, $day['recorded']['provider_cost_nanodollars']);
            $this->assertSame(0, $day['pixelforge']['provider_cost_nanodollars']);
            $this->assertSame([0, 0, 0, 0, 0], array_values($day['delta']));
        }
    }

    /**
     * AC 9: only now's UTC date is in progress.
     */
    public function test_only_today_is_in_progress(): void
    {
        $array = self::compared(self::pixelforgeRows())->toArray();

        $this->assertSame([true, false, false, false], array_column($array['days'], 'in_progress'));
        $this->assertSame(['2026-11-18', '2026-11-17', '2026-11-16', '2026-11-15'], array_column($array['days'], 'date'));
    }

    public function test_a_range_without_today_has_nothing_in_progress(): void
    {
        $comparison = UsageComparisonData::compared('2026-11-19', self::recorded(), self::usage(self::pixelforgeRows()));

        $this->assertSame([false, false, false, false], array_column($comparison->toArray()['days'], 'in_progress'));
    }

    /**
     * The total is Pixelforge's own, so a total inconsistent with its rows mismatches.
     */
    public function test_the_total_is_compared_with_pixelforges_own_total(): void
    {
        $comparison = self::compared(self::pixelforgeRows(), [...self::pixelforgeTotal(), 'output_tokens' => 1_661]);

        foreach ($comparison->days as $line) {
            $this->assertTrue($line->matches());
        }

        $this->assertFalse($comparison->total->matches());
        $this->assertTrue($comparison->hasMismatch());
        $this->assertSame(1, $comparison->toArray()['total']['delta']['output_tokens']);
    }

    #[DataProvider('unavailabilities')]
    public function test_without_pixelforge_our_figures_stay_and_the_rest_is_null(UsageAvailability $availability): void
    {
        $comparison = UsageComparisonData::withoutPixelforge(self::TODAY, self::recorded(), $availability);
        $array = $comparison->toArray();

        $this->assertSame($availability->value, $array['availability']);
        $this->assertFalse($array['has_mismatch']);
        $this->assertSame(74_595_000, $array['days'][2]['recorded']['provider_cost_nanodollars']);
        $this->assertSame(92_075_000, $array['total']['recorded']['provider_cost_nanodollars']);

        foreach ([...$array['days'], $array['total']] as $line) {
            $this->assertNull($line['pixelforge']);
            $this->assertNull($line['delta']);
            $this->assertNull($line['matches']);
        }

        $this->assertTrue($array['days'][0]['in_progress']);
    }

    /**
     * @return array<string, array{UsageAvailability}>
     */
    public static function unavailabilities(): array
    {
        return [
            'unavailable' => [UsageAvailability::Unavailable],
            'range too long' => [UsageAvailability::RangeTooLong],
        ];
    }

    public function test_to_array_is_all_ints_and_bools(): void
    {
        $array = self::compared(self::pixelforgeRows())->toArray();

        $this->assertSame('available', $array['availability']);
        $this->assertSame(
            [
                'date' => '2026-11-16',
                'in_progress' => false,
                'recorded' => ['generations' => 2, 'provider_cost_nanodollars' => 74_595_000, 'input_text_tokens' => 14, 'input_image_tokens' => 0, 'output_tokens' => 1_380, 'reasoning_tokens' => 1_546],
                'pixelforge' => ['generations' => 2, 'provider_cost_nanodollars' => 74_595_000, 'input_text_tokens' => 14, 'input_image_tokens' => 0, 'output_tokens' => 1_380, 'reasoning_tokens' => 1_546],
                'delta' => ['provider_cost_nanodollars' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0],
                'matches' => true,
            ],
            $array['days'][2],
        );
        $this->assertSame(
            ['generations' => 3, 'provider_cost_nanodollars' => 92_075_000, 'input_text_tokens' => 20, 'input_image_tokens' => 0, 'output_tokens' => 1_660, 'reasoning_tokens' => 2_046],
            $array['total']['recorded'],
        );
        $this->assertTrue($array['total']['matches']);
    }

    /**
     * @param  array<string, array<string, int|string>>  $rows
     * @param  ?array<string, int|string>  $total
     */
    private static function compared(array $rows, ?array $total = null): UsageComparisonData
    {
        return UsageComparisonData::compared(self::TODAY, self::recorded(), self::usage($rows, $total));
    }

    /**
     * What we recorded, newest first: G1 + G2 on 11-16 and G4 on 11-17.
     *
     * @return array<string, UsageFigures>
     */
    private static function recorded(): array
    {
        return [
            '2026-11-18' => UsageFigures::zero(),
            '2026-11-17' => new UsageFigures(1, new Nanodollars(17_480_000), 6, 0, 280, 500),
            '2026-11-16' => new UsageFigures(2, new Nanodollars(74_595_000), 14, 0, 1_380, 1_546),
            '2026-11-15' => UsageFigures::zero(),
        ];
    }

    /**
     * What Pixelforge says for the same days, ascending as it sends them.
     *
     * @return array<string, array<string, int|string>>
     */
    private static function pixelforgeRows(): array
    {
        return [
            '2026-11-15' => self::row('2026-11-15', '0.000000000', 0, 0, 0, 0, 0),
            '2026-11-16' => self::row('2026-11-16', '0.074595000', 14, 0, 1_380, 1_546, 2),
            '2026-11-17' => self::row('2026-11-17', '0.017480000', 6, 0, 280, 500, 1),
            '2026-11-18' => self::row('2026-11-18', '0.000000000', 0, 0, 0, 0, 0),
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private static function pixelforgeTotal(): array
    {
        return ['generations' => 3, 'input_text_tokens' => 20, 'input_image_tokens' => 0, 'output_tokens' => 1_660, 'reasoning_tokens' => 2_046, 'cost_usd' => '0.092075000'];
    }

    /**
     * The total, unless given, is Pixelforge's sum of the rows, as it reports it.
     *
     * @param  array<string, array<string, int|string>>  $rows
     * @param  ?array<string, int|string>  $total
     */
    private static function usage(array $rows, ?array $total = null): UsageReportData
    {
        if ($total === null) {
            $cost = Nanodollars::zero();
            $total = ['generations' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0];

            foreach ($rows as $row) {
                foreach (array_keys($total) as $field) {
                    $total[$field] += (int) $row[$field];
                }

                $cost = $cost->plus(Nanodollars::fromUsdString((string) $row['cost_usd']));
            }

            $total['cost_usd'] = $cost->toUsdString();
        }

        return UsageReportData::from([
            'object' => 'usage',
            'start_date' => '2026-11-15',
            'end_date' => '2026-11-18',
            'data' => array_values($rows),
            'total' => $total,
        ]);
    }

    /**
     * @return array<string, int|string>
     */
    private static function row(string $date, string $costUsd, int $text, int $image, int $output, int $reasoning, int $generations): array
    {
        return [
            'date' => $date,
            'generations' => $generations,
            'input_text_tokens' => $text,
            'input_image_tokens' => $image,
            'output_tokens' => $output,
            'reasoning_tokens' => $reasoning,
            'cost_usd' => $costUsd,
        ];
    }
}
