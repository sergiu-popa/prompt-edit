<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\Actions;

use App\Domain\Billing\Actions\CalculateUserChargeAction;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Billing\ValueObjects\Nanodollars;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalculateUserChargeActionTest extends TestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function charges(): array
    {
        return [
            'docs worked maximum' => [373_875_000, 467_343_750],
            'docs succeeded example' => [305_510_000, 381_887_500],
            'succeeded usage at a 20.00 output rate' => [176_510_000, 220_637_500],
            'zero' => [0, 0],
            '1.25 rounds up' => [1, 2],
            '2.5 rounds up' => [2, 3],
            '3.75 rounds up' => [3, 4],
            '5 is exact' => [4, 5],
            '6.25 rounds up' => [5, 7],
            '10 is exact' => [8, 10],
            'largest cost whose charge fits' => [7_378_697_629_483_820_645, PHP_INT_MAX],
        ];
    }

    #[DataProvider('charges')]
    public function test_charge_is_cost_plus_25_percent_rounded_up_to_the_next_nanodollar(int $providerCost, int $expectedCharge): void
    {
        $charge = (new CalculateUserChargeAction)->execute(new Nanodollars($providerCost));

        $this->assertSame($expectedCharge, $charge->amount);
    }

    public function test_the_over_charge_is_below_one_nanodollar(): void
    {
        $action = new CalculateUserChargeAction;

        foreach (range(0, 99) as $providerCost) {
            $overChargeInHundredths = $action->execute(new Nanodollars($providerCost))->amount * 100 - $providerCost * 125;

            $this->assertGreaterThanOrEqual(0, $overChargeInHundredths);
            $this->assertLessThanOrEqual(75, $overChargeInHundredths);
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function overflowingCosts(): array
    {
        return [
            'one above the largest cost whose charge fits' => [7_378_697_629_483_820_646],
            'PHP_INT_MAX' => [PHP_INT_MAX],
        ];
    }

    #[DataProvider('overflowingCosts')]
    public function test_a_charge_outside_the_64_bit_range_throws(int $providerCost): void
    {
        $this->expectException(NanodollarOverflowException::class);

        (new CalculateUserChargeAction)->execute(new Nanodollars($providerCost));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function negativeCosts(): array
    {
        return [
            'minus one' => [-1],
            'PHP_INT_MIN' => [PHP_INT_MIN],
        ];
    }

    #[DataProvider('negativeCosts')]
    public function test_a_negative_provider_cost_throws(int $providerCost): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CalculateUserChargeAction)->execute(new Nanodollars($providerCost));
    }
}
