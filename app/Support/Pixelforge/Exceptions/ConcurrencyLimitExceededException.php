<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use App\Support\Pixelforge\Enums\PixelforgeErrorCode;

/**
 * `429 concurrency_limit_exceeded`: more than 3 generations queued or processing.
 */
final class ConcurrencyLimitExceededException extends PixelforgeApiException
{
    public function errorCode(): PixelforgeErrorCode
    {
        return PixelforgeErrorCode::ConcurrencyLimitExceeded;
    }
}
