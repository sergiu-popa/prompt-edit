<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\Actions;

use App\Domain\Billing\Actions\CalculateProviderCostAction;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Billing\ValueObjects\TokenRates;
use App\Domain\Billing\ValueObjects\TokenUsage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalculateProviderCostActionTest extends TestCase
{
    private const array PUBLISHED_RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /**
     * @return array<string, array{array{int, int, int, int}, array<string, string>, int, string}>
     */
    public static function costs(): array
    {
        return [
            'docs worked maximum' => [[115, 1_530, 6_450, 8_000], [], 373_875_000, '0.373875000'],
            'docs succeeded example' => [[12, 765, 6_450, 3_184], [], 305_510_000, '0.305510000'],
            'same usage at a different output rate' => [[12, 765, 6_450, 3_184], ['output_tokens' => '20.00'], 176_510_000, '0.176510000'],
            'zero usage at published rates' => [[0, 0, 0, 0], [], 0, '0.000000000'],
            'zero usage at other rates' => [[0, 0, 0, 0], ['output_tokens' => '99.999'], 0, '0.000000000'],
            'zero rate contributes nothing' => [[12, 765, 6_450, 3_184], ['output_tokens' => '0'], 47_510_000, '0.047510000'],
            'PHP_INT_MAX tokens at a zero rate' => [[0, 0, PHP_INT_MAX, 0], ['output_tokens' => '0'], 0, '0.000000000'],
            'one token at the smallest rate' => [[1, 0, 0, 0], ['input_text_tokens' => '0.001'], 1, '0.000000001'],
        ];
    }

    /**
     * @param  array{int, int, int, int}  $counts
     * @param  array<string, string>  $rateOverrides
     */
    #[DataProvider('costs')]
    public function test_cost_is_the_sum_of_tokens_times_the_generations_own_rates(
        array $counts,
        array $rateOverrides,
        int $expectedNanodollars,
        string $expectedUsd,
    ): void {
        $cost = (new CalculateProviderCostAction)->execute(
            new TokenUsage(...$counts),
            TokenRates::from([...self::PUBLISHED_RATES, ...$rateOverrides]),
        );

        $this->assertSame($expectedNanodollars, $cost->amount);
        $this->assertSame($expectedUsd, $cost->toUsdString());
    }

    /**
     * @return array<string, array{array{int, int, int, int}, array<string, string>}>
     */
    public static function overflowingCosts(): array
    {
        return [
            'tokens times rate' => [[0, 0, PHP_INT_MAX, 0], ['output_tokens' => '0.002']],
            'sum of token types' => [[PHP_INT_MAX, 0, 0, 1], ['input_text_tokens' => '0.001', 'reasoning_tokens' => '0.001']],
        ];
    }

    /**
     * @param  array{int, int, int, int}  $counts
     * @param  array<string, string>  $rateOverrides
     */
    #[DataProvider('overflowingCosts')]
    public function test_a_cost_outside_the_64_bit_range_throws(array $counts, array $rateOverrides): void
    {
        $this->expectException(NanodollarOverflowException::class);

        (new CalculateProviderCostAction)->execute(
            new TokenUsage(...$counts),
            TokenRates::from([...self::PUBLISHED_RATES, ...$rateOverrides]),
        );
    }
}
