<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Billing\ValueObjects\Nanodollars;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * What the user pays for a generation: Pixelforge cost + 25%, rounded up to
 * the next whole nanodollar (D-04).
 */
final class CalculateUserChargeAction
{
    private const int MARKUP_NUMERATOR = 125;

    private const int MARKUP_DENOMINATOR = 100;

    /**
     * @throws NanodollarOverflowException
     */
    public function execute(Nanodollars $providerCost): Nanodollars
    {
        if ($providerCost->amount < 0) {
            throw new InvalidArgumentException(sprintf(
                'Provider cost must not be negative, got %d n$.',
                $providerCost->amount,
            ));
        }

        return Nanodollars::fromBigInteger(
            BigInteger::of($providerCost->amount)
                ->multipliedBy(self::MARKUP_NUMERATOR)
                ->dividedBy(self::MARKUP_DENOMINATOR, RoundingMode::Ceiling),
        );
    }
}
