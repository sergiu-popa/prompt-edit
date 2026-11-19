<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use RuntimeException;

/**
 * The Pixelforge client cannot be used as configured, so nothing is sent.
 */
final class PixelforgeNotConfiguredException extends RuntimeException
{
    public static function missingApiKey(): self
    {
        return new self('No Pixelforge API key is configured (PIXELFORGE_API_KEY).');
    }
}
