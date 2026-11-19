<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use InvalidArgumentException;
use Throwable;

final class InvalidRateException extends InvalidArgumentException
{
    public static function missingKey(string $key): self
    {
        return new self(sprintf('Rates are missing the %s rate.', $key));
    }

    public static function unexpectedKey(string $key): self
    {
        return new self(sprintf('Rates contain %s, a token type that cannot be priced.', $key));
    }

    public static function notAString(string $key, string $type): self
    {
        return new self(sprintf('Rate %s must be a decimal string, got %s.', $key, $type));
    }

    public static function malformed(string $key, string $value): self
    {
        return new self(sprintf('Rate %s "%s" is not a plain unsigned decimal string.', $key, $value));
    }

    public static function notWholeNanodollars(string $key, string $value): self
    {
        return new self(sprintf('Rate %s "%s" is not a whole number of nanodollars per token.', $key, $value));
    }

    public static function outOfRange(string $key, string $value, Throwable $previous): self
    {
        return new self(sprintf(
            'Rate %s "%s" is outside the signed 64-bit nanodollar range.',
            $key,
            $value,
        ), previous: $previous);
    }
}
