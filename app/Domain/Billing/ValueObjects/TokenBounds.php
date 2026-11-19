<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * The min, typical and max count of one token type in an estimate.
 *
 * Typical is the midpoint of min and max, rounded up to a whole token (D-16).
 */
final readonly class TokenBounds
{
    private function __construct(
        public int $min,
        public int $typical,
        public int $max,
    ) {}

    /**
     * A count known up front, such as output or reference-image tokens.
     *
     * @throws InvalidArgumentException when the count is negative
     */
    public static function exact(int $count): self
    {
        return self::between($count, $count);
    }

    /**
     * A count only known as a range, such as text or reasoning tokens.
     *
     * @throws InvalidArgumentException when a bound is negative or min exceeds max
     */
    public static function between(int $min, int $max): self
    {
        if ($min < 0) {
            throw new InvalidArgumentException(sprintf('Token bounds must not be negative, got a min of %d.', $min));
        }

        if ($min > $max) {
            throw new InvalidArgumentException(sprintf('Token bounds min %d exceeds max %d.', $min, $max));
        }

        $typical = BigInteger::of($min)->plus($max)->dividedBy(2, RoundingMode::Ceiling)->toInt();

        return new self($min, $typical, $max);
    }
}
