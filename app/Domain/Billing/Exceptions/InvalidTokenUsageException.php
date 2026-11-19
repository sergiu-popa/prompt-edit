<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use InvalidArgumentException;

final class InvalidTokenUsageException extends InvalidArgumentException
{
    public static function missingKey(string $key): self
    {
        return new self(sprintf('Usage is missing the %s count.', $key));
    }

    public static function unexpectedKey(string $key): self
    {
        return new self(sprintf('Usage contains %s, a token type that cannot be priced.', $key));
    }

    public static function notAnInteger(string $key, string $value): self
    {
        return new self(sprintf('Usage %s must be an integer, got %s.', $key, $value));
    }

    public static function negativeCount(string $key, int $count): self
    {
        return new self(sprintf('Usage %s must not be negative, got %d.', $key, $count));
    }

    public static function totalMismatch(int $total, string $sum): self
    {
        return new self(sprintf('Usage total_tokens %d does not equal the sum of the counts, %s.', $total, $sum));
    }
}
