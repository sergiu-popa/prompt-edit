<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use App\Domain\Billing\Exceptions\InvalidTokenUsageException;
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

    public static function nulByte(string $path): self
    {
        return new self(sprintf('The generation object\'s %s contains a NUL byte, which cannot be stored.', $path));
    }

    public static function unknownStatus(mixed $status): self
    {
        return self::invalidField('status', 'a Pixelforge status', $status);
    }

    public static function incompleteSuccess(string $path): self
    {
        return new self(sprintf('A succeeded generation object must carry %s.', $path));
    }

    /**
     * PE-001's rules decide which counts can be priced; its reason is kept as the cause.
     */
    public static function invalidUsage(InvalidTokenUsageException $previous): self
    {
        return new self(sprintf('The generation object\'s usage is invalid: %s', $previous->getMessage()), previous: $previous);
    }

    public static function unpricedRate(string $path): self
    {
        return new self(sprintf('The generation object\'s %s is not a token type that can be priced.', $path));
    }
}
