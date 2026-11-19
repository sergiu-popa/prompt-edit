<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Contracts;

use App\Support\Pixelforge\DataTransferObjects\CreatedGenerationData;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\Exceptions\AmbiguousPixelforgeOutcomeException;
use App\Support\Pixelforge\Exceptions\PixelforgeApiException;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;

/**
 * The Pixelforge API as the domains see it: typed data in, typed data or a
 * typed error out, never `Http::` or string codes. The configured driver is
 * bound in AppServiceProvider. Implementations never retry; the caller does,
 * with the same payload.
 */
interface PixelforgeClient
{
    /**
     * `POST /v1/generations`, keyed by the payload's idempotency key.
     *
     * @throws PixelforgeApiException for a documented error envelope
     * @throws AmbiguousPixelforgeOutcomeException when no complete response arrived, or the answer does not say whether a generation was created
     * @throws PixelforgeNotConfiguredException when nothing can be sent
     */
    public function createGeneration(CreateGenerationPayloadData $payload): CreatedGenerationData;
}
