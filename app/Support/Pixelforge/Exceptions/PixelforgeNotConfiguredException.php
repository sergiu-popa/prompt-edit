<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use App\Support\Pixelforge\Contracts\ReportsPixelforgeContext;
use RuntimeException;

/**
 * The Pixelforge client cannot be used as configured, so nothing is sent.
 */
final class PixelforgeNotConfiguredException extends RuntimeException implements ReportsPixelforgeContext
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

    /**
     * The fake signs every webhook it sends, and never with an empty key.
     */
    public static function missingWebhookSecret(): self
    {
        return new self('The fake Pixelforge driver needs a webhook secret to sign its webhooks (PIXELFORGE_WEBHOOK_SECRET).');
    }

    public static function fakeDriverInProduction(): self
    {
        return new self('PIXELFORGE_DRIVER=fake is not allowed in production.');
    }

    public static function unknownDriver(string $driver): self
    {
        return new self(sprintf('Unknown Pixelforge driver "%s" (PIXELFORGE_DRIVER).', $driver));
    }
}
