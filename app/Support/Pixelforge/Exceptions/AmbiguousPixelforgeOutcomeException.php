<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use App\Support\Pixelforge\Contracts\ReportsPixelforgeContext;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

/**
 * Pixelforge's answer does not say whether it created the generation, so it
 * may have been accepted and billed. It is never a definitive rejection: the
 * caller retries with the same idempotency key, and a replay is free. For a read,
 * it means no usable answer arrived. No message carries the response body; the
 * previous exception keeps its detail.
 */
final class AmbiguousPixelforgeOutcomeException extends RuntimeException implements ReportsPixelforgeContext
{
    /**
     * @param  ?int  $status  the HTTP status, or null when no status line arrived
     */
    private function __construct(string $message, public readonly ?int $status, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Merged into the log entry whenever the exception is reported.
     *
     * @return array{error_code: string, status: ?int}
     */
    public function context(): array
    {
        return ['error_code' => 'ambiguous', 'status' => $this->status];
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
     * A 2xx whose body is not a usage report for the range asked that can be read exactly.
     */
    public static function unreadableUsage(int $status, ?Throwable $previous = null): self
    {
        return new self(sprintf('Pixelforge answered HTTP %d without a readable usage report.', $status), $status, $previous);
    }

    /**
     * No complete response arrived: a connect or read timeout, a refused or
     * reset connection, or a status line whose body was cut off. The request
     * may still have reached Pixelforge. The status is kept when one arrived.
     */
    public static function transportFailed(HttpClientException $previous): self
    {
        return new self(
            'No complete response arrived from Pixelforge (connection failed, timed out or was cut off).',
            $previous instanceof RequestException ? $previous->response->status() : null,
            $previous,
        );
    }
}
