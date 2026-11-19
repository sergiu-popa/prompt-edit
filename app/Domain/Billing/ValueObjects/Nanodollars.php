<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

use App\Domain\Billing\Exceptions\InvalidUsdAmountException;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use Brick\Math\BigDecimal;
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
    private const int USD_SCALE = 9;

    /**
     * Optional minus, ASCII digits, then at most 9 decimals; `\z` rejects a trailing newline.
     */
    private const string USD_PATTERN = '/^-?[0-9]+(?:\.[0-9]{1,9})?\z/';

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
     * Parses a USD decimal string such as "0.373875000" or "1.5" exactly.
     *
     * @throws InvalidUsdAmountException
     */
    public static function fromUsdString(string $usd): self
    {
        if (preg_match(self::USD_PATTERN, $usd) !== 1) {
            throw InvalidUsdAmountException::malformed($usd);
        }

        $nanodollars = BigDecimal::of($usd)->withPointMovedRight(self::USD_SCALE)->toBigInteger();

        try {
            return self::fromBigInteger($nanodollars);
        } catch (NanodollarOverflowException $exception) {
            throw InvalidUsdAmountException::outOfRange($usd, $exception);
        }
    }

    /**
     * Renders the amount as a USD string with exactly 9 decimals.
     */
    public function toUsdString(): string
    {
        return BigDecimal::ofUnscaledValue($this->amount, self::USD_SCALE)->toString();
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
