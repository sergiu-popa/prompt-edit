<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use App\Support\Pixelforge\Enums\WebhookSignatureFailure;
use RuntimeException;

/**
 * A webhook whose signature does not prove it came from Pixelforge, now. The
 * message names only the reason, never the header, the body or the secret.
 */
final class InvalidWebhookSignatureException extends RuntimeException
{
    private function __construct(public readonly WebhookSignatureFailure $reason)
    {
        parent::__construct(sprintf('The Pixelforge webhook signature is %s.', $reason->value));
    }

    public static function because(WebhookSignatureFailure $reason): self
    {
        return new self($reason);
    }
}
