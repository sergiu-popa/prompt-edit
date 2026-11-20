<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\ValueObjects;

use App\Domain\Generation\DataTransferObjects\SubmitDecision;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Enums\SubmitAttemptOutcome;
use App\Domain\Generation\ValueObjects\SubmitBookkeeping;
use App\Domain\Generation\ValueObjects\SubmitRetryPolicy;
use Carbon\CarbonImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The retry decision for each outcome, from the generation's attempt history, with
 * production's bounds: a 30 min window and a 2 min overload deadline.
 */
final class SubmitRetryPolicyTest extends TestCase
{
    private const int WINDOW = 1_800;

    private const int OVERLOADED_DEADLINE = 120;

    private const string FIRST_ATTEMPT = '2026-11-20T10:00:00Z';

    private SubmitRetryPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new SubmitRetryPolicy(self::WINDOW, self::OVERLOADED_DEADLINE);
    }

    /**
     * @return array<string, array{SubmitAttemptOutcome, ?int, int}>
     */
    public static function waitsAfterRetryAfter(): array
    {
        return [
            'a rate limit with Retry-After 12' => [SubmitAttemptOutcome::RateLimited, 12, 12],
            'a rate limit without Retry-After' => [SubmitAttemptOutcome::RateLimited, null, 60],
            'a rate limit with Retry-After 0' => [SubmitAttemptOutcome::RateLimited, 0, 1],
            'the spent budget, freeing up in 37 s' => [SubmitAttemptOutcome::BudgetExhausted, 37, 37],
            'the spent budget without a wait' => [SubmitAttemptOutcome::BudgetExhausted, null, 60],
            'no key, whatever was sent' => [SubmitAttemptOutcome::NotConfigured, null, 60],
            'a concurrency limit, as a rate limit until PE-013b' => [SubmitAttemptOutcome::ConcurrencyLimited, 5, 5],
            'a daily limit, as a rate limit until PE-013b' => [SubmitAttemptOutcome::DailyLimited, null, 60],
            'a refused key, as a rate limit until PE-013b' => [SubmitAttemptOutcome::InvalidApiKey, null, 60],
        ];
    }

    #[DataProvider('waitsAfterRetryAfter')]
    public function test_it_waits_for_retry_after_or_the_outcomes_default(SubmitAttemptOutcome $outcome, ?int $retryAfter, int $wait): void
    {
        $now = self::at(self::FIRST_ATTEMPT)->addSeconds(30);

        $this->assertRetriesIn($wait, $this->policy->decide(self::lastOutcome($outcome, $now), $retryAfter, $now));
    }

    /**
     * Doubling from the first 503, with the docs' `Retry-After: 2` as the floor.
     *
     * @return array<string, array{int, int}>
     */
    public static function overloadBackoff(): array
    {
        return [
            'the first 503' => [0, 2],
            '2 s after it' => [2, 2],
            '4 s after it' => [4, 4],
            '8 s after it' => [8, 8],
            '16 s after it' => [16, 16],
            '32 s after it, capped' => [32, 30],
            '110 s after it, cut to the deadline' => [110, 10],
            '119 s after it, never below Retry-After' => [119, 2],
        ];
    }

    #[DataProvider('overloadBackoff')]
    public function test_an_overload_backs_off_by_doubling_until_the_deadline(int $sinceFirstOverload, int $wait): void
    {
        $firstOverloadedAt = self::at(self::FIRST_ATTEMPT);
        $now = $firstOverloadedAt->addSeconds($sinceFirstOverload);

        $this->assertRetriesIn($wait, $this->policy->decide(self::overloaded($firstOverloadedAt, $now), 2, $now));
    }

    public function test_a_longer_retry_after_wins_over_the_backoff(): void
    {
        $firstOverloadedAt = self::at(self::FIRST_ATTEMPT);
        $now = $firstOverloadedAt->addSeconds(118);

        $this->assertRetriesIn(5, $this->policy->decide(self::overloaded($firstOverloadedAt, $now), 5, $now));
    }

    public function test_an_overload_without_retry_after_waits_the_documented_2_s(): void
    {
        $now = self::at(self::FIRST_ATTEMPT);

        $this->assertRetriesIn(2, $this->policy->decide(self::overloaded($now, $now), null, $now));
    }

    /**
     * AC 11: still overloaded 2 min after the first 503.
     */
    public function test_an_overload_at_its_deadline_fails_free(): void
    {
        $firstOverloadedAt = self::at(self::FIRST_ATTEMPT);
        $now = $firstOverloadedAt->addSeconds(self::OVERLOADED_DEADLINE);

        $decision = $this->policy->decide(self::overloaded($firstOverloadedAt, $now), 2, $now);

        $this->assertSame(LocalFailureReason::ProviderOverloaded, $decision->failureReason);
        $this->assertNull($decision->retryInSeconds);
        $this->assertFalse($decision->isStop());
    }

    /**
     * AC 13: it may exist at Pixelforge, so it keeps retrying, with no deadline term.
     */
    public function test_a_latched_overload_past_its_deadline_keeps_retrying(): void
    {
        $firstOverloadedAt = self::at(self::FIRST_ATTEMPT);
        $now = $firstOverloadedAt->addSeconds(300);

        $decision = $this->policy->decide(self::overloaded($firstOverloadedAt, $now, ambiguousAt: $firstOverloadedAt), 2, $now);

        $this->assertRetriesIn(30, $decision);
    }

    public function test_a_latched_overload_just_before_its_deadline_is_not_cut_to_it(): void
    {
        $firstOverloadedAt = self::at(self::FIRST_ATTEMPT);
        $now = $firstOverloadedAt->addSeconds(110);

        $this->assertRetriesIn(30, $this->policy->decide(self::overloaded($firstOverloadedAt, $now, ambiguousAt: $firstOverloadedAt), 2, $now));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function ambiguousBackoff(): array
    {
        return [
            'at the latch' => [0, 5],
            '3 s after it' => [3, 5],
            '15 s after it' => [15, 15],
            '30 s after it' => [30, 30],
            '90 s after it, capped' => [90, 60],
        ];
    }

    #[DataProvider('ambiguousBackoff')]
    public function test_an_ambiguous_outcome_waits_as_long_as_it_has_been_ambiguous(int $sinceLatch, int $wait): void
    {
        $latchedAt = self::at(self::FIRST_ATTEMPT);
        $now = $latchedAt->addSeconds($sinceLatch);
        $bookkeeping = new SubmitBookkeeping($latchedAt, $now, SubmitAttemptOutcome::Ambiguous, $latchedAt);

        $this->assertRetriesIn($wait, $this->policy->decide($bookkeeping, null, $now));
    }

    /**
     * @return array<string, array{int, int, bool}>
     */
    public static function windowEnds(): array
    {
        return [
            'the next attempt a second before the window ends' => [1_790, 9, false],
            'the next attempt exactly at the window\'s end' => [1_790, 10, true],
            'the next attempt after it' => [1_790, 12, true],
        ];
    }

    #[DataProvider('windowEnds')]
    public function test_a_retry_that_would_start_past_the_window_is_a_stop(int $sinceFirstAttempt, int $retryAfter, bool $stops): void
    {
        $now = self::at(self::FIRST_ATTEMPT)->addSeconds($sinceFirstAttempt);

        $decision = $this->policy->decide(self::lastOutcome(SubmitAttemptOutcome::RateLimited, $now), $retryAfter, $now);

        $this->assertSame($stops, $decision->isStop());
        $this->assertSame($stops ? null : $retryAfter, $decision->retryInSeconds);
    }

    public function test_a_latched_generation_also_stops_at_the_windows_end(): void
    {
        $first = self::at(self::FIRST_ATTEMPT);
        $now = $first->addSeconds(1_780);
        $bookkeeping = new SubmitBookkeeping($first, $now, SubmitAttemptOutcome::Ambiguous, $first);

        $this->assertTrue($this->policy->decide($bookkeeping, null, $now)->isStop());
    }

    /**
     * @return array<string, array{?int, bool}>
     */
    public static function windowChecks(): array
    {
        return [
            'never attempted' => [null, false],
            'a second before the window ends' => [self::WINDOW - 1, false],
            'at the window\'s end' => [self::WINDOW, true],
            'after it' => [self::WINDOW + 60, true],
        ];
    }

    #[DataProvider('windowChecks')]
    public function test_the_window_is_measured_from_the_first_attempt(?int $sinceFirstAttempt, bool $ended): void
    {
        $first = self::at(self::FIRST_ATTEMPT);
        $bookkeeping = $sinceFirstAttempt === null ? new SubmitBookkeeping : new SubmitBookkeeping($first, $first, SubmitAttemptOutcome::RateLimited);

        $this->assertSame($ended, $this->policy->hasWindowEnded($bookkeeping, $first->addSeconds($sinceFirstAttempt ?? 0)));
    }

    /**
     * @return array<string, array{?SubmitAttemptOutcome}>
     */
    public static function unretriedOutcomes(): array
    {
        return [
            'accepted' => [SubmitAttemptOutcome::Accepted],
            'rejected' => [SubmitAttemptOutcome::Rejected],
            'no outcome yet' => [null],
        ];
    }

    #[DataProvider('unretriedOutcomes')]
    public function test_an_outcome_that_is_not_retried_has_no_decision(?SubmitAttemptOutcome $outcome): void
    {
        $now = self::at(self::FIRST_ATTEMPT);

        $this->expectException(LogicException::class);

        $this->policy->decide(new SubmitBookkeeping($now, $now, $outcome), null, $now);
    }

    private function assertRetriesIn(int $seconds, SubmitDecision $decision): void
    {
        $this->assertSame($seconds, $decision->retryInSeconds);
        $this->assertNull($decision->failureReason);
        $this->assertFalse($decision->isStop());
    }

    /**
     * One attempt at the first attempt's time, and the last one now, with this outcome.
     */
    private static function lastOutcome(SubmitAttemptOutcome $outcome, CarbonImmutable $now): SubmitBookkeeping
    {
        return new SubmitBookkeeping(self::at(self::FIRST_ATTEMPT), $now, $outcome);
    }

    private static function overloaded(CarbonImmutable $firstOverloadedAt, CarbonImmutable $now, ?CarbonImmutable $ambiguousAt = null): SubmitBookkeeping
    {
        return new SubmitBookkeeping(
            self::at(self::FIRST_ATTEMPT),
            $now,
            SubmitAttemptOutcome::Overloaded,
            ambiguousAt: $ambiguousAt,
            firstOverloadedAt: $firstOverloadedAt,
        );
    }

    private static function at(string $instant): CarbonImmutable
    {
        return CarbonImmutable::parse($instant);
    }
}
