<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\ValueObjects;

use App\Domain\Generation\Enums\SubmitAttemptOutcome;
use App\Domain\Generation\Enums\SubmitRetryNote;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\ValueObjects\SubmitBookkeeping;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsInstants;
use Tests\TestCase;

/**
 * A generation's record of its submit attempts, changed run by run with no I/O.
 */
final class SubmitBookkeepingTest extends TestCase
{
    use AssertsInstants;

    private const string FIRST = '2026-11-20T10:00:00Z';

    private const string SECOND = '2026-11-20T10:00:05Z';

    private const string THIRD = '2026-11-20T10:00:20Z';

    public function test_the_first_start_sets_the_first_and_last_attempt_with_no_outcome(): void
    {
        $bookkeeping = (new SubmitBookkeeping)->startAttempt(self::at(self::FIRST));

        $this->assertSameInstant(self::FIRST, $bookkeeping->firstAttemptedAt);
        $this->assertSameInstant(self::FIRST, $bookkeeping->lastAttemptedAt);
        $this->assertNull($bookkeeping->lastOutcome);
        $this->assertNull($bookkeeping->ambiguousAt);
        $this->assertTrue($bookkeeping->hasAttemptWithoutOutcome());
    }

    public function test_a_later_start_moves_only_the_last_attempt_and_clears_the_outcome(): void
    {
        $bookkeeping = (new SubmitBookkeeping)
            ->startAttempt(self::at(self::FIRST))
            ->finishAttempt(SubmitAttemptOutcome::RateLimited, self::at(self::FIRST))
            ->startAttempt(self::at(self::SECOND));

        $this->assertSameInstant(self::FIRST, $bookkeeping->firstAttemptedAt);
        $this->assertSameInstant(self::SECOND, $bookkeeping->lastAttemptedAt);
        $this->assertNull($bookkeeping->lastOutcome);
        $this->assertNull($bookkeeping->ambiguousAt);
        $this->assertSame(SubmitRetryNote::Retrying, $bookkeeping->retryNote);
    }

    /**
     * The earlier run died after it may have sent its request (AC 12).
     */
    public function test_a_start_after_an_attempt_with_no_outcome_latches_at_that_attempts_start(): void
    {
        $bookkeeping = (new SubmitBookkeeping)
            ->startAttempt(self::at(self::FIRST))
            ->startAttempt(self::at(self::SECOND));

        $this->assertSameInstant(self::FIRST, $bookkeeping->ambiguousAt);
        $this->assertSameInstant(self::SECOND, $bookkeeping->lastAttemptedAt);
        $this->assertTrue($bookkeeping->isAmbiguous());
    }

    public function test_the_overload_and_concurrency_anchors_are_set_once(): void
    {
        $bookkeeping = (new SubmitBookkeeping)
            ->startAttempt(self::at(self::FIRST))
            ->finishAttempt(SubmitAttemptOutcome::Overloaded, self::at(self::FIRST))
            ->startAttempt(self::at(self::SECOND))
            ->finishAttempt(SubmitAttemptOutcome::ConcurrencyLimited, self::at(self::SECOND))
            ->startAttempt(self::at(self::THIRD))
            ->finishAttempt(SubmitAttemptOutcome::Overloaded, self::at(self::THIRD))
            ->finishAttempt(SubmitAttemptOutcome::ConcurrencyLimited, self::at(self::THIRD));

        $this->assertSameInstant(self::FIRST, $bookkeeping->firstOverloadedAt);
        $this->assertSameInstant(self::SECOND, $bookkeeping->firstConcurrencyLimitedAt);
    }

    public function test_an_ambiguous_outcome_latches_once_and_the_latch_never_clears(): void
    {
        $bookkeeping = (new SubmitBookkeeping)
            ->startAttempt(self::at(self::FIRST))
            ->finishAttempt(SubmitAttemptOutcome::Ambiguous, self::at(self::FIRST))
            ->startAttempt(self::at(self::SECOND))
            ->finishAttempt(SubmitAttemptOutcome::Ambiguous, self::at(self::SECOND))
            ->startAttempt(self::at(self::THIRD))
            ->finishAttempt(SubmitAttemptOutcome::Overloaded, self::at(self::THIRD));

        $this->assertSameInstant(self::FIRST, $bookkeeping->ambiguousAt);
        $this->assertTrue($bookkeeping->isAmbiguous());
    }

    public function test_each_refused_key_is_counted(): void
    {
        $bookkeeping = (new SubmitBookkeeping)
            ->finishAttempt(SubmitAttemptOutcome::InvalidApiKey, self::at(self::FIRST))
            ->finishAttempt(SubmitAttemptOutcome::Overloaded, self::at(self::SECOND))
            ->finishAttempt(SubmitAttemptOutcome::InvalidApiKey, self::at(self::THIRD));

        $this->assertSame(2, $bookkeeping->invalidApiKeyCount);
    }

    /**
     * @return array<string, array{SubmitAttemptOutcome, ?SubmitRetryNote}>
     */
    public static function retryNotes(): array
    {
        return [
            'accepted' => [SubmitAttemptOutcome::Accepted, null],
            'rejected' => [SubmitAttemptOutcome::Rejected, null],
            'budget exhausted' => [SubmitAttemptOutcome::BudgetExhausted, SubmitRetryNote::Retrying],
            'rate limited' => [SubmitAttemptOutcome::RateLimited, SubmitRetryNote::Retrying],
            'concurrency limited' => [SubmitAttemptOutcome::ConcurrencyLimited, SubmitRetryNote::WaitingForSlot],
            'daily limited' => [SubmitAttemptOutcome::DailyLimited, SubmitRetryNote::Retrying],
            'overloaded' => [SubmitAttemptOutcome::Overloaded, SubmitRetryNote::Retrying],
            'ambiguous' => [SubmitAttemptOutcome::Ambiguous, SubmitRetryNote::Retrying],
            'invalid api key' => [SubmitAttemptOutcome::InvalidApiKey, SubmitRetryNote::Retrying],
            'not configured' => [SubmitAttemptOutcome::NotConfigured, SubmitRetryNote::Retrying],
        ];
    }

    #[DataProvider('retryNotes')]
    public function test_the_note_follows_the_outcome(SubmitAttemptOutcome $outcome, ?SubmitRetryNote $note): void
    {
        $this->assertSame($note, $outcome->retryNote());
        $this->assertSame($note, (new SubmitBookkeeping)->finishAttempt($outcome, self::at(self::FIRST))->retryNote);
    }

    public function test_the_note_moves_with_each_wait_and_survives_an_acceptance(): void
    {
        $waiting = (new SubmitBookkeeping)->finishAttempt(SubmitAttemptOutcome::ConcurrencyLimited, self::at(self::FIRST));
        $retrying = $waiting->finishAttempt(SubmitAttemptOutcome::Overloaded, self::at(self::SECOND));

        $this->assertSame(SubmitRetryNote::WaitingForSlot, $waiting->retryNote);
        $this->assertSame(SubmitRetryNote::Retrying, $retrying->retryNote);
        $this->assertSame(SubmitRetryNote::Retrying, $retrying->finishAttempt(SubmitAttemptOutcome::Accepted, self::at(self::THIRD))->retryNote);
    }

    public function test_a_hand_over_sets_its_time_and_the_retrying_note(): void
    {
        $bookkeeping = (new SubmitBookkeeping)
            ->startAttempt(self::at(self::FIRST))
            ->finishAttempt(SubmitAttemptOutcome::Ambiguous, self::at(self::FIRST))
            ->startAttempt(self::at(self::SECOND))
            ->finishAttempt(SubmitAttemptOutcome::ConcurrencyLimited, self::at(self::SECOND))
            ->handOver(self::at(self::THIRD));

        $this->assertSameInstant(self::THIRD, $bookkeeping->handedOverAt);
        $this->assertSameInstant(self::FIRST, $bookkeeping->ambiguousAt);
        $this->assertSame(SubmitRetryNote::Retrying, $bookkeeping->retryNote);
    }

    /**
     * A run that died with no outcome is ambiguous though nothing latched it yet.
     */
    public function test_a_hand_over_after_an_attempt_with_no_outcome_latches_at_its_start(): void
    {
        $bookkeeping = (new SubmitBookkeeping)
            ->startAttempt(self::at(self::FIRST))
            ->handOver(self::at(self::THIRD));

        $this->assertSameInstant(self::FIRST, $bookkeeping->ambiguousAt);
        $this->assertSameInstant(self::THIRD, $bookkeeping->handedOverAt);
    }

    /**
     * @return array<string, array{SubmitBookkeeping, bool}>
     */
    public static function ambiguity(): array
    {
        $at = self::at(self::FIRST);

        return [
            'never attempted' => [new SubmitBookkeeping, false],
            'an attempt with an outcome' => [new SubmitBookkeeping($at, $at, SubmitAttemptOutcome::Overloaded), false],
            'an attempt with no outcome' => [new SubmitBookkeeping($at, $at), true],
            'latched, with an outcome' => [new SubmitBookkeeping($at, $at, SubmitAttemptOutcome::Overloaded, $at), true],
            'latched, never attempted' => [new SubmitBookkeeping(ambiguousAt: $at), true],
        ];
    }

    #[DataProvider('ambiguity')]
    public function test_it_is_ambiguous_once_latched_or_with_an_attempt_that_has_no_outcome(SubmitBookkeeping $bookkeeping, bool $ambiguous): void
    {
        $this->assertSame($ambiguous, $bookkeeping->isAmbiguous());
    }

    public function test_it_reads_from_and_writes_to_the_generations_columns(): void
    {
        $attributes = [
            'submit_first_attempted_at' => self::at(self::FIRST),
            'submit_last_attempted_at' => self::at(self::THIRD),
            'submit_last_outcome' => SubmitAttemptOutcome::Overloaded,
            'submit_ambiguous_at' => self::at(self::SECOND),
            'submit_handed_over_at' => null,
            'submit_first_overloaded_at' => self::at(self::FIRST),
            'submit_first_concurrency_limited_at' => null,
            'submit_invalid_api_key_count' => 1,
            'submit_retry_note' => SubmitRetryNote::Retrying,
        ];

        $bookkeeping = SubmitBookkeeping::fromGeneration((new Generation)->forceFill($attributes));

        $this->assertEquals($attributes, $bookkeeping->toAttributes());
        $this->assertSame(array_keys($attributes), array_keys($bookkeeping->toAttributes()));
    }

    private static function at(string $instant): CarbonImmutable
    {
        return CarbonImmutable::parse($instant);
    }
}
