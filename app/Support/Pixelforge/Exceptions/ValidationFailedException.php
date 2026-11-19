<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use App\Support\Pixelforge\Enums\PixelforgeErrorCode;

/**
 * `422 validation_failed`: a bad or unknown param, named by `param` and `errors`.
 */
final class ValidationFailedException extends PixelforgeApiException
{
    public function errorCode(): PixelforgeErrorCode
    {
        return PixelforgeErrorCode::ValidationFailed;
    }
}
