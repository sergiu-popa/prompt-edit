<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use InvalidArgumentException;

/**
 * A Pixelforge generation object that cannot be read exactly. Nothing is guessed:
 * every message names the field path at fault.
 */
final class InvalidGenerationSnapshotException extends InvalidArgumentException
{
    public static function missingField(string $path): self
    {
        return new self(sprintf('The generation object is missing %s.', $path));
    }

    public static function invalidField(string $path, string $expected, mixed $value): self
    {
        return new self(sprintf(
            'The generation object\'s %s must be %s, got %s.',
            $path,
            $expected,
            is_scalar($value) ? var_export($value, true) : get_debug_type($value),
        ));
    }

    public static function unknownStatus(mixed $status): self
    {
        return self::invalidField('status', 'a Pixelforge status', $status);
    }
}
