<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use Brick\Math\BigInteger;
use Brick\Math\Exception\IntegerOverflowException;

/**
 * An exact USD amount in integer nanodollars (1 n$ = 10⁻⁹ USD).
 *
 * All arithmetic runs through BigInteger, so a result can never wrap or
 * silently become a float: anything outside the signed 64-bit range throws.
 */
final readonly class Nanodollars
{
    public function __construct(public int $amount) {}

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * The single place a big number becomes a native int.
     *
     * @throws NanodollarOverflowException
     */
    public static function fromBigInteger(BigInteger $value): self
    {
        try {
            return new self($value->toInt());
        } catch (IntegerOverflowException $exception) {
            throw NanodollarOverflowException::forResult($value, $exception);
        }
    }

    /**
     * @throws NanodollarOverflowException
     */
    public function plus(self $other): self
    {
        return self::fromBigInteger($this->toBigInteger()->plus($other->amount));
    }

    /**
     * @throws NanodollarOverflowException
     */
    public function minus(self $other): self
    {
        return self::fromBigInteger($this->toBigInteger()->minus($other->amount));
    }

    /**
     * @throws NanodollarOverflowException
     */
    public function multipliedBy(int $factor): self
    {
        return self::fromBigInteger($this->toBigInteger()->multipliedBy($factor));
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount;
    }

    /**
     * @return -1|0|1
     */
    public function compareTo(self $other): int
    {
        return $this->amount <=> $other->amount;
    }

    private function toBigInteger(): BigInteger
    {
        return BigInteger::of($this->amount);
    }
}
