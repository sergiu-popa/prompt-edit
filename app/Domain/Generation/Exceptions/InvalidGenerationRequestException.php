<?php

declare(strict_types=1);

namespace App\Domain\Generation\Exceptions;

use InvalidArgumentException;

/**
 * A request the create action cannot store exactly as given, so it is
 * refused before anything is written. PE-011's validation rejects it first
 * with a field error; this is the domain's last line.
 */
final class InvalidGenerationRequestException extends InvalidArgumentException
{
    public static function nulByteInPrompt(): self
    {
        return new self('The prompt contains a NUL byte, which cannot be stored.');
    }

    /**
     * @param  int  $position  one-based, in the order given
     */
    public static function nulByteInReferenceImage(int $position): self
    {
        return new self(sprintf('Reference image %d contains a NUL byte, which cannot be stored.', $position));
    }
}
