<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use App\Support\Pixelforge\Enums\PixelforgeErrorCode;

/**
 * `429 rate_limit_exceeded`: more than 60 requests a minute on the account. Any
 * other 429, whose body is not one of the three documented 429 envelopes, is read
 * as one too (AC 4): a 429 never creates anything, whatever its body says.
 */
final class RateLimitExceededException extends PixelforgeApiException
{
    /**
     * A 429 without a documented 429 envelope: an unknown code, another code, or no
     * envelope at all.
     */
    public static function undocumented(?int $retryAfterSeconds): self
    {
        return new self('rate_limit_error', 'Pixelforge answered 429 without a documented error envelope.', retryAfterSeconds: $retryAfterSeconds);
    }

    public function errorCode(): PixelforgeErrorCode
    {
        return PixelforgeErrorCode::RateLimitExceeded;
    }
}
