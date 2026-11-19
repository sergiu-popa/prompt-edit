<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use App\Support\Pixelforge\Enums\PixelforgeErrorCode;

/**
 * `429 rate_limit_exceeded`: more than 60 requests a minute on the account.
 */
final class RateLimitExceededException extends PixelforgeApiException
{
    public function errorCode(): PixelforgeErrorCode
    {
        return PixelforgeErrorCode::RateLimitExceeded;
    }
}
