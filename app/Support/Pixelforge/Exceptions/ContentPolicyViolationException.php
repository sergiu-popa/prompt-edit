<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use App\Support\Pixelforge\Enums\PixelforgeErrorCode;

/**
 * `400 content_policy_violation`: the prompt was rejected and nothing was created.
 */
final class ContentPolicyViolationException extends PixelforgeApiException
{
    public function errorCode(): PixelforgeErrorCode
    {
        return PixelforgeErrorCode::ContentPolicyViolation;
    }
}
