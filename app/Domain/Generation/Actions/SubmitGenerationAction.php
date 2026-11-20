<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Billing\Exceptions\InvalidRateException;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Generation\DataTransferObjects\LocalGenerationFailureData;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Enums\SubmitAttemptOutcome;
use App\Domain\Generation\Exceptions\ConcurrentGenerationTransitionException;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\ValueObjects\SubmitBookkeeping;
use App\Domain\Generation\ValueObjects\SubmitRetryPolicy;
use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\Contracts\ReportsPixelforgeContext;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use App\Support\Pixelforge\Exceptions\PixelforgeApiException;
use App\Support\Pixelforge\Exceptions\RateBudgetExhaustedException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * One submit attempt for a local generation (D-32). The row is re-read and the
 * body rebuilt from its stored columns, so every attempt sends the same bytes
 * under the same key, and Pixelforge replays instead of creating a second
 * generation. The create is sent outside any transaction or row lock, so a
 * webhook landing meanwhile is never blocked, and the answer goes through the
 * transition action, so it never undoes one that landed first (D-06).
 *
 * Each run records its start on the generation before it sends, and its outcome
 * after, outside the transition action (D-45). A definitive rejection (`400`,
 * `422` or `409`) fails the generation free (D-31). Any other failure goes to
 * the retry policy (D-47): wait and try again, fail free where Pixelforge
 * definitely created nothing, or stop at the window's end (D-46).
 */
final class SubmitGenerationAction
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly TransitionGenerationAction $transitionGeneration,
        private readonly SubmitRetryPolicy $retryPolicy,
        private readonly StopSubmittingGenerationAction $stopSubmitting,
    ) {}

    /**
     * A generation Pixelforge has already answered for (it has a provider id)
     * or that is terminal is left alone, with no request (AC 20). One whose
     * window has ended is stopped before anything is sent.
     *
     * @return ?int the seconds until the next attempt, or null when the job is done
     *
     * @throws ConcurrentGenerationTransitionException
     * @throws InvalidRateException
     * @throws NanodollarOverflowException
     */
    public function execute(string $generationId): ?int
    {
        $generation = Generation::query()->whereKey($generationId)->firstOrFail();

        if ($generation->provider_id !== null || $generation->status->isTerminal()) {
            return null;
        }

        if ($this->retryPolicy->hasWindowEnded(SubmitBookkeeping::fromGeneration($generation), CarbonImmutable::now())) {
            $this->stopSubmitting->execute($generation->id);

            return null;
        }

        $bookkeeping = $this->startAttempt($generation);

        try {
            $created = $this->pixelforge->createGeneration(self::payloadFor($generation));
        } catch (PixelforgeApiException $exception) {
            $reason = self::definitiveRejectionReason($exception);

            if ($reason === null) {
                return $this->afterFailure($generation, $bookkeeping, $exception);
            }

            $this->failLocally($generation, $reason, $exception);
            $this->finishAttempt($generation, $bookkeeping, SubmitAttemptOutcome::Rejected);

            return null;
        } catch (ReportsPixelforgeContext $exception) {
            return $this->afterFailure($generation, $bookkeeping, $exception);
        }

        $outcome = $this->transitionGeneration->execute($generation, $created->snapshot);
        $this->finishAttempt($generation, $bookkeeping, SubmitAttemptOutcome::Accepted);

        Log::info('Applied Pixelforge\'s answer to a generation create.', [
            'generation_id' => $generation->id,
            'provider_id' => $created->snapshot->id,
            'replayed' => $created->replayed,
            'outcome' => $outcome->value,
        ]);

        return null;
    }

    /**
     * Recorded before the budget is taken or the request is sent, so a run that dies
     * mid-request leaves an attempt with no outcome, which the next run reads as
     * ambiguous (AC 12, 23).
     */
    private function startAttempt(Generation $generation): SubmitBookkeeping
    {
        $bookkeeping = SubmitBookkeeping::fromGeneration($generation)->startAttempt(CarbonImmutable::now());
        $generation->recordSubmitBookkeeping($bookkeeping);

        return $bookkeeping;
    }

    /**
     * Recorded only once the answer is applied, so a transition that throws leaves
     * no outcome: the safe side.
     */
    private function finishAttempt(Generation $generation, SubmitBookkeeping $bookkeeping, SubmitAttemptOutcome $outcome): SubmitBookkeeping
    {
        $finished = $bookkeeping->finishAttempt($outcome, CarbonImmutable::now());
        $generation->recordSubmitBookkeeping($finished);

        return $finished;
    }

    /**
     * The failure's outcome is recorded first, so the decision reads it.
     *
     * @return ?int the seconds until the next attempt, or null when the job is done
     *
     * @throws ConcurrentGenerationTransitionException
     * @throws InvalidRateException
     * @throws NanodollarOverflowException
     */
    private function afterFailure(Generation $generation, SubmitBookkeeping $bookkeeping, ReportsPixelforgeContext $failure): ?int
    {
        $finished = $this->finishAttempt($generation, $bookkeeping, SubmitAttemptOutcome::fromFailure($failure));
        $decision = $this->retryPolicy->decide($finished, self::retryAfterSecondsOf($failure), CarbonImmutable::now());

        if ($decision->retryInSeconds !== null) {
            Log::warning('Pixelforge did not take a generation create; it will be retried with the same key.', [
                'generation_id' => $generation->id,
                ...$failure->context(),
                'retry_in_seconds' => $decision->retryInSeconds,
            ]);

            return $decision->retryInSeconds;
        }

        if ($decision->failureReason !== null) {
            $this->failLocally($generation, $decision->failureReason, $failure);

            return null;
        }

        $this->stopSubmitting->execute($generation->id);

        return null;
    }

    /**
     * Pixelforge's error envelope is stored with the reason when there is one.
     *
     * @throws ConcurrentGenerationTransitionException
     */
    private function failLocally(Generation $generation, LocalFailureReason $reason, ReportsPixelforgeContext $failure): void
    {
        $envelope = $failure instanceof PixelforgeApiException ? $failure : null;

        $outcome = $this->transitionGeneration->execute($generation, new LocalGenerationFailureData(
            $reason,
            $envelope?->errorCode()->value,
            $envelope?->getMessage(),
            $envelope?->param,
        ));

        $context = [
            'generation_id' => $generation->id,
            'error_code' => $failure->context()['error_code'],
            'outcome' => $outcome->value,
        ];

        match ($reason) {
            LocalFailureReason::ContentPolicyViolation => Log::info('Failed a generation at no charge: Pixelforge rejected its prompt.', $context),
            LocalFailureReason::ValidationFailed => Log::error('Failed a generation at no charge: Pixelforge refused a param our validation accepted.', [
                ...$context,
                'param' => $envelope?->param,
            ]),
            LocalFailureReason::IdempotencyKeyReused => Log::error('Failed a generation at no charge: Pixelforge holds its idempotency key with other params.', $context),
            LocalFailureReason::ProviderOverloaded,
            LocalFailureReason::ProviderBusy,
            LocalFailureReason::ServiceUnavailable => Log::warning('Failed a generation at no charge: Pixelforge never accepted it.', [
                ...$context,
                'reason' => $reason->value,
            ]),
        };
    }

    /**
     * Pixelforge's `Retry-After`, or how long until our own budget frees up.
     */
    private static function retryAfterSecondsOf(ReportsPixelforgeContext $failure): ?int
    {
        return match (true) {
            $failure instanceof PixelforgeApiException, $failure instanceof RateBudgetExhaustedException => $failure->retryAfterSeconds,
            default => null,
        };
    }

    /**
     * The local reason when Pixelforge definitely created nothing, else null.
     */
    private static function definitiveRejectionReason(PixelforgeApiException $exception): ?LocalFailureReason
    {
        return match ($exception->errorCode()) {
            PixelforgeErrorCode::ContentPolicyViolation => LocalFailureReason::ContentPolicyViolation,
            PixelforgeErrorCode::ValidationFailed => LocalFailureReason::ValidationFailed,
            PixelforgeErrorCode::IdempotencyKeyReused => LocalFailureReason::IdempotencyKeyReused,
            default => null,
        };
    }

    private static function payloadFor(Generation $generation): CreateGenerationPayloadData
    {
        return new CreateGenerationPayloadData(
            $generation->id,
            $generation->prompt,
            $generation->size,
            $generation->quality,
            $generation->reference_images,
        );
    }
}
