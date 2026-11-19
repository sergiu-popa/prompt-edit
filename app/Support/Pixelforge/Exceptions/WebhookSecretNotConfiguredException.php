<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use RuntimeException;

/**
 * No webhook secret is configured, so nothing can be signed or verified. The
 * HMAC is never computed with an empty key.
 */
final class WebhookSecretNotConfiguredException extends RuntimeException
{
    public static function missing(): self
    {
        return new self('No Pixelforge webhook secret is configured (PIXELFORGE_WEBHOOK_SECRET).');
    }
}
