<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Generation\Actions\SubmitGenerationAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Sends one local generation to Pixelforge. It carries only the ULID, so
 * every attempt re-reads the row and rebuilds the same body, and a retry is
 * replayed under the same key instead of creating a second generation. The
 * tries, backoff and timeout are interim until PE-013; the timeout stays
 * above the HTTP client's, so a hung request never overlaps its own retry.
 */
#[Tries(5)]
#[Backoff(5, 15, 30, 60)]
#[Timeout(60)]
final class SubmitGenerationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $generationId,
    ) {}

    public function handle(SubmitGenerationAction $submitGeneration): void
    {
        $submitGeneration->execute($this->generationId);
    }
}
