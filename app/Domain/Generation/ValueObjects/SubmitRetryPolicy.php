<?php

declare(strict_types=1);

namespace App\Domain\Generation\ValueObjects;

use App\Domain\Generation\DataTransferObjects\SubmitDecision;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Enums\SubmitAttemptOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use LogicException;

/**
 * What follows an attempt Pixelforge did not accept, from the generation's attempt
 * history (D-47). `Retry-After` is a floor. Once any attempt was ambiguous, nothing
 * here fails the generation, since it may exist at Pixelforge and be billed (AC 13).
 * The job is bounded by time from its first attempt, never by a count (AC 17).
 *
 * The waits restate Pixelforge's documented `Retry-After` values and the story's
 * examples, so they are constants; the bounds are config.
 */
final readonly class SubmitRetryPolicy
{
    /**
     * No wait is shorter, so a `Retry-After: 0` never spins the worker.
     */
    public const int MIN_WAIT_SECONDS = 1;

    /**
     * A rate limit without a usable `Retry-After` waits out a whole minute (AC 4).
     */
    public const int RATE_LIMITED_WAIT_SECONDS = 60;

    /**
     * The `Retry-After` the docs give `503 overloaded`.
     */
    public const int OVERLOADED_WAIT_SECONDS = 2;

    /**
     * A 503 backs off by doubling from the first 503, up to this.
     */
    public const int OVERLOADED_MAX_WAIT_SECONDS = 30;

    /**
     * An ambiguous outcome waits as long as it has been ambiguous, within these.
     */
    public const int AMBIGUOUS_MIN_WAIT_SECONDS = 5;

    public const int AMBIGUOUS_MAX_WAIT_SECONDS = 60;

    /**
     * Nothing can be sent until a key (or the fake's secret) is configured.
     */
    public const int NOT_CONFIGURED_WAIT_SECONDS = 60;

    public function __construct(
        #[Config('pixelforge.submit.window_seconds')]
        private int $windowSeconds,
        #[Config('pixelforge.submit.overloaded_deadline_seconds')]
        private int $overloadedDeadlineSeconds,
    ) {}

    /**
     * The decision after the attempt whose outcome the bookkeeping records last. A
     * retry whose next attempt would start at or after the window's end is a stop.
     *
     * @param  ?int  $retryAfterSeconds  the failure's `Retry-After`, or the budget's wait
     *
     * @throws LogicException when the last attempt has no retried outcome
     */
    public function decide(SubmitBookkeeping $bookkeeping, ?int $retryAfterSeconds, CarbonImmutable $now): SubmitDecision
    {
        $decision = $this->decideIgnoringTheWindow($bookkeeping, $retryAfterSeconds, $now);

        if ($decision->retryInSeconds === null) {
            return $decision;
        }

        $retryInSeconds = max(self::MIN_WAIT_SECONDS, $decision->retryInSeconds);

        return $this->hasWindowEnded($bookkeeping, $now->addSeconds($retryInSeconds))
            ? SubmitDecision::stop()
            : SubmitDecision::retryIn($retryInSeconds);
    }

    /**
     * Whether an attempt starting now would be past the window, measured from the
     * first attempt. A generation never attempted has a window still to come.
     */
    public function hasWindowEnded(SubmitBookkeeping $bookkeeping, CarbonImmutable $now): bool
    {
        return $bookkeeping->firstAttemptedAt !== null
            && $now->greaterThanOrEqualTo($bookkeeping->firstAttemptedAt->addSeconds($this->windowSeconds));
    }

    /**
     * @throws LogicException
     */
    private function decideIgnoringTheWindow(SubmitBookkeeping $bookkeeping, ?int $retryAfterSeconds, CarbonImmutable $now): SubmitDecision
    {
        $outcome = $bookkeeping->lastOutcome;

        return match ($outcome) {
            SubmitAttemptOutcome::Overloaded => $this->afterOverload($bookkeeping, $retryAfterSeconds, $now),
            SubmitAttemptOutcome::Ambiguous => SubmitDecision::retryIn(self::ambiguousWait($bookkeeping, $now)),
            SubmitAttemptOutcome::NotConfigured => SubmitDecision::retryIn(self::NOT_CONFIGURED_WAIT_SECONDS),
            SubmitAttemptOutcome::BudgetExhausted,
            SubmitAttemptOutcome::RateLimited,
            SubmitAttemptOutcome::ConcurrencyLimited,
            SubmitAttemptOutcome::DailyLimited,
            SubmitAttemptOutcome::InvalidApiKey => SubmitDecision::retryIn($retryAfterSeconds ?? self::RATE_LIMITED_WAIT_SECONDS),
            SubmitAttemptOutcome::Accepted, SubmitAttemptOutcome::Rejected, null => throw new LogicException(sprintf(
                'A submit attempt that ended %s is not retried.',
                $outcome->value ?? 'without an outcome',
            )),
        };
    }

    /**
     * Doubling from the first 503 (2, 2, 4, 8, 16, 30 …), never below `Retry-After`
     * and, unless latched, never past the deadline, where it fails free (AC 11).
     */
    private function afterOverload(SubmitBookkeeping $bookkeeping, ?int $retryAfterSeconds, CarbonImmutable $now): SubmitDecision
    {
        $firstOverloadedAt = $bookkeeping->firstOverloadedAt ?? $now;
        $deadline = $firstOverloadedAt->addSeconds($this->overloadedDeadlineSeconds);
        $latched = $bookkeeping->isAmbiguous();

        if (! $latched && $now->greaterThanOrEqualTo($deadline)) {
            return SubmitDecision::failFree(LocalFailureReason::ProviderOverloaded);
        }

        $backoff = min(self::OVERLOADED_MAX_WAIT_SECONDS, self::secondsBetween($firstOverloadedAt, $now));

        if (! $latched) {
            $backoff = min($backoff, self::secondsBetween($now, $deadline));
        }

        return SubmitDecision::retryIn(max($retryAfterSeconds ?? self::OVERLOADED_WAIT_SECONDS, $backoff));
    }

    private static function ambiguousWait(SubmitBookkeeping $bookkeeping, CarbonImmutable $now): int
    {
        $sinceLatch = self::secondsBetween($bookkeeping->ambiguousAt ?? $now, $now);

        return min(self::AMBIGUOUS_MAX_WAIT_SECONDS, max(self::AMBIGUOUS_MIN_WAIT_SECONDS, $sinceLatch));
    }

    /**
     * Whole seconds from one instant to a later one, 0 when it is not later.
     */
    private static function secondsBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return max(0, $to->getTimestamp() - $from->getTimestamp());
    }
}
