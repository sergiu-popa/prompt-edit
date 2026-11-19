<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use App\Support\Pixelforge\Enums\PixelforgeErrorCode;

/**
 * `401 invalid_api_key`: the key is missing, malformed or revoked.
 */
final class InvalidApiKeyException extends PixelforgeApiException
{
    public function errorCode(): PixelforgeErrorCode
    {
        return PixelforgeErrorCode::InvalidApiKey;
    }
}
