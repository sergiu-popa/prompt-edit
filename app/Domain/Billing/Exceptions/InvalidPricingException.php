<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use InvalidArgumentException;

final class InvalidPricingException extends InvalidArgumentException
{
    public static function missing(string $path): self
    {
        return new self(sprintf('Pricing snapshot is missing %s.', $path));
    }

    public static function notAnArray(string $path, string $type): self
    {
        return new self(sprintf('Pricing snapshot %s must be an array, got %s.', $path, $type));
    }

    public static function notAnInteger(string $path, string $value): self
    {
        return new self(sprintf('Pricing snapshot %s must be an integer, got %s.', $path, $value));
    }

    public static function outOfRange(string $path, int $value, string $expectation): self
    {
        return new self(sprintf('Pricing snapshot %s must be %s, got %d.', $path, $expectation, $value));
    }
}
