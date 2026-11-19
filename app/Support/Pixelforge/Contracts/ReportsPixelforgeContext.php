<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Contracts;

use Throwable;

/**
 * A Pixelforge failure the client contract can throw: a documented error,
 * an ambiguous outcome or a client that is not configured. Its context names
 * the failure without the key, the prompt or a response body, so it is safe
 * to merge into any log entry.
 */
interface ReportsPixelforgeContext extends Throwable
{
    /**
     * Merged into the log entry whenever the exception is reported.
     *
     * @return array<string, int|string|null> always with an `error_code`
     */
    public function context(): array;
}
