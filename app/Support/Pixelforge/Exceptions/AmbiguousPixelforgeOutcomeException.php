<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Throwable;

/**
 * Pixelforge's answer does not say whether it created the generation, so it
 * may have been accepted and billed. It is never a definitive rejection: the
 * caller retries with the same idempotency key, and a replay is free. No
 * message carries the response body; the previous exception keeps its detail.
 */
final class AmbiguousPixelforgeOutcomeException extends RuntimeException
{
    /**
     * @param  ?int  $status  the HTTP status, or null when no response arrived
     */
    private function __construct(string $message, public readonly ?int $status, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Any status without a documented error envelope, such as a proxy's 502 page.
     */
    public static function undocumentedResponse(int $status): self
    {
        return new self(sprintf('Pixelforge answered HTTP %d without a documented error envelope.', $status), $status);
    }

    /**
     * A 2xx whose body is not a generation object that can be read exactly.
     */
    public static function unreadableGeneration(int $status, ?Throwable $previous = null): self
    {
        return new self(sprintf('Pixelforge answered HTTP %d without a readable generation object.', $status), $status, $previous);
    }

    /**
     * No response arrived: a connect or read timeout, or a refused or reset
     * connection. The request may still have reached Pixelforge.
     */
    public static function transportFailed(ConnectionException $previous): self
    {
        return new self('No response arrived from Pixelforge (connection failed or timed out).', null, $previous);
    }
}
