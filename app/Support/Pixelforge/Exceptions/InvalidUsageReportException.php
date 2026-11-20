<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use InvalidArgumentException;
use Throwable;

/**
 * A Pixelforge usage report that cannot be read exactly (D-73). Nothing is guessed: every
 * message names the field path at fault, and none repeats the body.
 */
final class InvalidUsageReportException extends InvalidArgumentException
{
    public static function missingField(string $path): self
    {
        return new self(sprintf('The usage report is missing %s.', $path));
    }

    public static function invalidField(string $path, string $expected, mixed $value, ?Throwable $previous = null): self
    {
        return new self(sprintf(
            'The usage report\'s %s must be %s, got %s.',
            $path,
            $expected,
            is_scalar($value) ? var_export($value, true) : get_debug_type($value),
        ), previous: $previous);
    }

    /**
     * The range itself is unreadable: a malformed date, a start after the end, or too long.
     */
    public static function invalidRange(Throwable $previous): self
    {
        return new self(sprintf('The usage report\'s range is invalid: %s', $previous->getMessage()), previous: $previous);
    }

    public static function unexpectedDate(string $path, string $date): self
    {
        return new self(sprintf('The usage report\'s %s is %s, which is outside its range.', $path, $date));
    }

    public static function repeatedDate(string $path, string $date): self
    {
        return new self(sprintf('The usage report\'s %s repeats %s.', $path, $date));
    }

    public static function missingDate(string $date): self
    {
        return new self(sprintf('The usage report has no row for %s.', $date));
    }
}
