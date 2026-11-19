<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use App\Support\Pixelforge\Enums\PixelforgeErrorCode;

/**
 * `409 idempotency_key_reused`: the key was used before with other params.
 */
final class IdempotencyKeyReusedException extends PixelforgeApiException
{
    public function errorCode(): PixelforgeErrorCode
    {
        return PixelforgeErrorCode::IdempotencyKeyReused;
    }
}
