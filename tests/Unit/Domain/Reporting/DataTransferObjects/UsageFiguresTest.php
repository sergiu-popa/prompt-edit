<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Reporting\DataTransferObjects;

use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Domain\Billing\ValueObjects\TokenUsage;
use App\Domain\Reporting\DataTransferObjects\UsageFigures;
use App\Support\Pixelforge\DataTransferObjects\UsageRowData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One side of a reconciliation line (D-72): a match is five exactly equal integers, and a
 * generation count never takes part.
 */
final class UsageFiguresTest extends TestCase
{
    public function test_figures_that_differ_only_in_their_count_match(): void
    {
        $this->assertTrue(self::g1g2()->matches(new UsageFigures(5, new Nanodollars(74_595_000), 14, 0, 1_380, 1_546)));
    }

    /**
     * @return array<string, array{UsageFigures}>
     */
    public static function differentFigures(): array
    {
        return [
            'one nanodollar more' => [new UsageFigures(2, new Nanodollars(74_595_001), 14, 0, 1_380, 1_546)],
            'one nanodollar less' => [new UsageFigures(2, new Nanodollars(74_594_999), 14, 0, 1_380, 1_546)],
            'one more input text token' => [new UsageFigures(2, new Nanodollars(74_595_000), 15, 0, 1_380, 1_546)],
            'one more input image token' => [new UsageFigures(2, new Nanodollars(74_595_000), 14, 1, 1_380, 1_546)],
            'one more output token' => [new UsageFigures(2, new Nanodollars(74_595_000), 14, 0, 1_381, 1_546)],
            'one more reasoning token' => [new UsageFigures(2, new Nanodollars(74_595_000), 14, 0, 1_380, 1_547)],
        ];
    }

    #[DataProvider('differentFigures')]
    public function test_any_other_difference_is_a_mismatch(UsageFigures $other): void
    {
        $this->assertFalse(self::g1g2()->matches($other));
        $this->assertFalse($other->matches(self::g1g2()));
    }

    public function test_minus_signs_each_of_the_five_deltas(): void
    {
        $pixelforge = new UsageFigures(3, new Nanodollars(32_605_000), 11, 0, 560, 812);
        $ours = new UsageFigures(1, new Nanodollars(17_480_000), 6, 2, 280, 900);

        $this->assertSame(
            ['provider_cost_nanodollars' => 15_125_000, 'input_text_tokens' => 5, 'input_image_tokens' => -2, 'output_tokens' => 280, 'reasoning_tokens' => -88],
            $pixelforge->minus($ours),
        );
        $this->assertSame(
            ['provider_cost_nanodollars' => -15_125_000, 'input_text_tokens' => -5, 'input_image_tokens' => 2, 'output_tokens' => -280, 'reasoning_tokens' => 88],
            $ours->minus($pixelforge),
        );
    }

    public function test_plus_sums_every_figure_the_count_too(): void
    {
        $g1 = new UsageFigures(1, new Nanodollars(15_125_000), 5, 0, 280, 312);
        $g2 = new UsageFigures(1, new Nanodollars(59_470_000), 9, 0, 1_100, 1_234);

        $this->assertEquals(self::g1g2(), $g1->plus($g2));
        $this->assertEquals($g1, UsageFigures::zero()->plus($g1));
    }

    public function test_zero_is_zero_everywhere(): void
    {
        $this->assertSame(
            ['generations' => 0, 'provider_cost_nanodollars' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0],
            UsageFigures::zero()->toArray(),
        );
    }

    public function test_pixelforges_row_becomes_figures(): void
    {
        $row = new UsageRowData(2, new TokenUsage(14, 0, 1_380, 1_546), new Nanodollars(74_595_000));

        $this->assertEquals(self::g1g2(), UsageFigures::fromPixelforge($row));
    }

    public function test_to_array_is_all_ints(): void
    {
        $this->assertSame(
            ['generations' => 2, 'provider_cost_nanodollars' => 74_595_000, 'input_text_tokens' => 14, 'input_image_tokens' => 0, 'output_tokens' => 1_380, 'reasoning_tokens' => 1_546],
            self::g1g2()->toArray(),
        );
    }

    /**
     * The PE-016 worked example's 2026-11-16: G1 and G2.
     */
    private static function g1g2(): UsageFigures
    {
        return new UsageFigures(2, new Nanodollars(74_595_000), 14, 0, 1_380, 1_546);
    }
}
