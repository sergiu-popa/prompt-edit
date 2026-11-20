<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Generation\Actions\SubmitGenerationAction;
use App\Support\Pixelforge\Contracts\ReportsPixelforgeContext;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one local generation to Pixelforge. It is pushed only after the
 * outermost transaction commits, so a worker never reads an uncommitted row,
 * and a rollback pushes nothing. It carries only the ULID, so
 * every attempt re-reads the row and rebuilds the same body, and a retry is
 * replayed under the same key instead of creating a second generation. The
 * tries, backoff and timeout are interim until PE-013; the timeout stays
 * above the HTTP client's, so a hung request never overlaps its own retry.
 */
#[Tries(5)]
#[Backoff(5, 15, 30, 60)]
#[Timeout(60)]
final class SubmitGenerationJob implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        public readonly string $generationId,
    ) {}

    /**
     * Released after each wait the retry policy decides, measured by the action
     * from the first attempt.
     */
    public function handle(SubmitGenerationAction $submitGeneration): void
    {
        $retryInSeconds = $submitGeneration->execute($this->generationId);

        if ($retryInSeconds !== null) {
            $this->release($retryInSeconds);
        }
    }

    /**
     * Out of attempts, the generation is never failed here: Pixelforge may
     * still have it, and may bill it. It stays non-terminal with its hold
     * until PE-013 bounds the retries and PE-014 re-dispatches this job. The
     * queue passes no exception when the job is failed by hand.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Gave up submitting a generation; it stays non-terminal with its hold.', [
            'generation_id' => $this->generationId,
            'exception' => $exception === null ? null : $exception::class,
            ...($exception instanceof ReportsPixelforgeContext ? $exception->context() : []),
        ]);
    }
}
