<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Generation\Actions\StopSubmittingGenerationAction;
use App\Domain\Generation\Actions\SubmitGenerationAction;
use App\Support\Pixelforge\Contracts\ReportsPixelforgeContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one local generation to Pixelforge. It is pushed only after the
 * outermost transaction commits, so a worker never reads an uncommitted row,
 * and a rollback pushes nothing. It carries only the ULID and its timeout, so
 * every attempt re-reads the row and rebuilds the same body, and a retry is
 * replayed under the same key instead of creating a second generation.
 *
 * It is bounded by time, not by tries (D-46): `retryUntil` overrides the
 * worker's `--tries=3`, because every wait is a `release()`, which counts as
 * an attempt. The action measures the window from the first attempt and stops
 * the job itself; the grace only lets it do so before the worker would. The
 * backoff applies only to an unexpected exception. The timeout sits between the
 * HTTP client's and the queue's `retry_after`, so a hung request never overlaps
 * its own retry (AC 19).
 */
#[Backoff(5, 15, 30, 60)]
final class SubmitGenerationJob implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * Past the window, measured from dispatch here, before the worker fails the job.
     */
    public const int RETRY_UNTIL_GRACE_SECONDS = 600;

    public int $timeout;

    public function __construct(
        public readonly string $generationId,
    ) {
        $this->timeout = config()->integer('pixelforge.submit.job_timeout_seconds');
    }

    /**
     * Released after each wait the retry policy decides.
     */
    public function handle(SubmitGenerationAction $submitGeneration): void
    {
        $retryInSeconds = $submitGeneration->execute($this->generationId);

        if ($retryInSeconds !== null) {
            $this->release($retryInSeconds);
        }
    }

    /**
     * Computed once, at dispatch.
     */
    public function retryUntil(): CarbonImmutable
    {
        return CarbonImmutable::now()->addSeconds(
            config()->integer('pixelforge.submit.window_seconds') + self::RETRY_UNTIL_GRACE_SECONDS,
        );
    }

    /**
     * The queue gave up (past `retryUntil`, or failed by hand), so the generation is
     * stopped as at the end of its window: failed free, or handed to the reconciler
     * when Pixelforge may have it. The queue passes no exception when the job is
     * failed by hand.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('The queue gave up submitting a generation; stopping it as at the end of its window.', [
            'generation_id' => $this->generationId,
            'exception' => $exception === null ? null : $exception::class,
            ...($exception instanceof ReportsPixelforgeContext ? $exception->context() : []),
        ]);

        app(StopSubmittingGenerationAction::class)->execute($this->generationId);
    }
}
