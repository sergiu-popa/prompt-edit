<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use App\Support\Pixelforge\Enums\PixelforgeErrorCode;

/**
 * `503 overloaded`: nothing was created; retry with the same idempotency key.
 */
final class OverloadedException extends PixelforgeApiException
{
    public function errorCode(): PixelforgeErrorCode
    {
        return PixelforgeErrorCode::Overloaded;
    }
}
