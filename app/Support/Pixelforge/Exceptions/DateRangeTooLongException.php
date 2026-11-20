<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use App\Support\Pixelforge\Enums\PixelforgeErrorCode;

/**
 * `422 date_range_too_long`: a `/v1/usage` range covers more than 92 days.
 */
final class DateRangeTooLongException extends PixelforgeApiException
{
    public function errorCode(): PixelforgeErrorCode
    {
        return PixelforgeErrorCode::DateRangeTooLong;
    }
}
