<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use App\Support\Pixelforge\Contracts\ReportsPixelforgeContext;
use RuntimeException;

/**
 * Our own account-wide request budget is spent for this minute (D-44), so nothing
 * was sent. Pixelforge has created nothing for this call.
 */
final class RateBudgetExhaustedException extends RuntimeException implements ReportsPixelforgeContext
{
    /**
     * @param  int  $retryAfterSeconds  until the budget's window ends, at least 1
     */
    private function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(sprintf('The Pixelforge request budget is spent; it frees up in %d s.', $retryAfterSeconds));
    }

    public static function freesUpIn(int $seconds): self
    {
        return new self(max(1, $seconds));
    }

    /**
     * Merged into the log entry whenever the exception is reported.
     *
     * @return array{error_code: string, retry_after_seconds: int}
     */
    public function context(): array
    {
        return ['error_code' => 'rate_budget_exhausted', 'retry_after_seconds' => $this->retryAfterSeconds];
    }
}
