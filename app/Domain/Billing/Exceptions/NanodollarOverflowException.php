<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use Brick\Math\BigInteger;
use OverflowException;
use Throwable;

final class NanodollarOverflowException extends OverflowException
{
    public static function forResult(BigInteger $result, ?Throwable $previous = null): self
    {
        return new self(sprintf(
            'Nanodollar amount %s is outside the signed 64-bit range.',
            $result->toString(),
        ), previous: $previous);
    }
}
