<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Enums;

/**
 * Why a webhook's `Pixelforge-Signature` was refused. The values are the
 * reasons logged for each rejection.
 */
enum WebhookSignatureFailure: string
{
    case Missing = 'missing';
    case Malformed = 'malformed';
    case Mismatch = 'mismatch';
    case Stale = 'stale';
}
