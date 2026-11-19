<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use App\Support\Pixelforge\Enums\PixelforgeErrorCode;

/**
 * `429 daily_limit_exceeded`: more than 500 generations this UTC day.
 */
final class DailyLimitExceededException extends PixelforgeApiException
{
    public function errorCode(): PixelforgeErrorCode
    {
        return PixelforgeErrorCode::DailyLimitExceeded;
    }
}
