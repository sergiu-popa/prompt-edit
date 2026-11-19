<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use InvalidArgumentException;
use Throwable;

final class InvalidUsdAmountException extends InvalidArgumentException
{
    public static function malformed(string $value): self
    {
        return new self(sprintf(
            'USD amount "%s" is not a plain decimal with at most 9 decimals.',
            $value,
        ));
    }

    public static function outOfRange(string $value, Throwable $previous): self
    {
        return new self(sprintf(
            'USD amount "%s" is outside the signed 64-bit nanodollar range.',
            $value,
        ), previous: $previous);
    }
}
