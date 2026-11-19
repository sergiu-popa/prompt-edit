<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use RuntimeException;

/**
 * The Pixelforge client cannot be used as configured, so nothing is sent.
 */
final class PixelforgeNotConfiguredException extends RuntimeException
{
    /**
     * Merged into the log entry whenever the exception is reported.
     *
     * @return array{error_code: string}
     */
    public function context(): array
    {
        return ['error_code' => 'not_configured'];
    }

    public static function missingApiKey(): self
    {
        return new self('No Pixelforge API key is configured (PIXELFORGE_API_KEY).');
    }

    public static function unknownDriver(string $driver): self
    {
        return new self(sprintf('Unknown Pixelforge driver "%s" (PIXELFORGE_DRIVER).', $driver));
    }
}
