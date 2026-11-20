<?php

declare(strict_types=1);

namespace App\Domain\Generation\ValueObjects;

use App\Domain\Generation\Enums\SubmitAttemptOutcome;
use App\Domain\Generation\Enums\SubmitRetryNote;
use App\Domain\Generation\Models\Generation;
use Carbon\CarbonImmutable;

/**
 * A generation's record of its submit attempts, over its nine `submit_*` columns
 * (D-45). It does no I/O: each change returns a new record, and the caller writes
 * toAttributes() in one UPDATE of those columns alone.
 *
 * Each run records its start before it takes the budget or sends, with no outcome,
 * then its outcome. So a run found with a start and no outcome died after it may
 * have sent its request, and is ambiguous. The ambiguity latch is set once and
 * never cleared (AC 13).
 */
final readonly class SubmitBookkeeping
{
    public function __construct(
        public ?CarbonImmutable $firstAttemptedAt = null,
        public ?CarbonImmutable $lastAttemptedAt = null,
        public ?SubmitAttemptOutcome $lastOutcome = null,
        public ?CarbonImmutable $ambiguousAt = null,
        public ?CarbonImmutable $handedOverAt = null,
        public ?CarbonImmutable $firstOverloadedAt = null,
        public ?CarbonImmutable $firstConcurrencyLimitedAt = null,
        public int $invalidApiKeyCount = 0,
        public ?SubmitRetryNote $retryNote = null,
    ) {}

    public static function fromGeneration(Generation $generation): self
    {
        return new self(
            $generation->submit_first_attempted_at,
            $generation->submit_last_attempted_at,
            $generation->submit_last_outcome,
            $generation->submit_ambiguous_at,
            $generation->submit_handed_over_at,
            $generation->submit_first_overloaded_at,
            $generation->submit_first_concurrency_limited_at,
            $generation->submit_invalid_api_key_count,
            $generation->submit_retry_note,
        );
    }

    /**
     * A run begins: an earlier run left without an outcome latches the record at
     * its own start, the first attempt is set once, and this run has no outcome yet.
     */
    public function startAttempt(CarbonImmutable $now): self
    {
        return $this->with(
            firstAttemptedAt: $this->firstAttemptedAt ?? $now,
            lastAttemptedAt: $now,
            lastOutcome: null,
            ambiguousAt: $this->ambiguousAt ?? ($this->hasAttemptWithoutOutcome() ? $this->lastAttemptedAt : null),
        );
    }

    /**
     * The run's outcome, the first time of each kind of wait it anchors, the latch
     * on an ambiguous outcome, the count of refused keys, and the note to show.
     */
    public function finishAttempt(SubmitAttemptOutcome $outcome, CarbonImmutable $now): self
    {
        return $this->with(
            lastOutcome: $outcome,
            ambiguousAt: $this->ambiguousAt ?? ($outcome === SubmitAttemptOutcome::Ambiguous ? $now : null),
            firstOverloadedAt: $this->firstOverloadedAt ?? ($outcome === SubmitAttemptOutcome::Overloaded ? $now : null),
            firstConcurrencyLimitedAt: $this->firstConcurrencyLimitedAt ?? ($outcome === SubmitAttemptOutcome::ConcurrencyLimited ? $now : null),
            invalidApiKeyCount: $this->invalidApiKeyCount + ($outcome === SubmitAttemptOutcome::InvalidApiKey ? 1 : 0),
            retryNote: $outcome->retryNote() ?? $this->retryNote,
        );
    }

    /**
     * The submit job stops for good while the generation may exist at Pixelforge:
     * PE-014's reconciler takes it over, and History keeps saying it is retrying.
     */
    public function handOver(CarbonImmutable $now): self
    {
        return $this->with(
            ambiguousAt: $this->ambiguousAt ?? $this->lastAttemptedAt ?? $now,
            handedOverAt: $now,
            retryNote: SubmitRetryNote::Retrying,
        );
    }

    /**
     * Whether Pixelforge may have created the generation: the latch is set, or a
     * run died without recording its outcome.
     */
    public function isAmbiguous(): bool
    {
        return $this->ambiguousAt !== null || $this->hasAttemptWithoutOutcome();
    }

    public function hasAttemptWithoutOutcome(): bool
    {
        return $this->lastAttemptedAt !== null && $this->lastOutcome === null;
    }

    /**
     * @return array{
     *     submit_first_attempted_at: ?CarbonImmutable,
     *     submit_last_attempted_at: ?CarbonImmutable,
     *     submit_last_outcome: ?SubmitAttemptOutcome,
     *     submit_ambiguous_at: ?CarbonImmutable,
     *     submit_handed_over_at: ?CarbonImmutable,
     *     submit_first_overloaded_at: ?CarbonImmutable,
     *     submit_first_concurrency_limited_at: ?CarbonImmutable,
     *     submit_invalid_api_key_count: int,
     *     submit_retry_note: ?SubmitRetryNote,
     * }
     */
    public function toAttributes(): array
    {
        return [
            'submit_first_attempted_at' => $this->firstAttemptedAt,
            'submit_last_attempted_at' => $this->lastAttemptedAt,
            'submit_last_outcome' => $this->lastOutcome,
            'submit_ambiguous_at' => $this->ambiguousAt,
            'submit_handed_over_at' => $this->handedOverAt,
            'submit_first_overloaded_at' => $this->firstOverloadedAt,
            'submit_first_concurrency_limited_at' => $this->firstConcurrencyLimitedAt,
            'submit_invalid_api_key_count' => $this->invalidApiKeyCount,
            'submit_retry_note' => $this->retryNote,
        ];
    }

    /**
     * A copy with the given fields changed; a null keeps the field. Only the outcome
     * ever goes back to null (at a run's start), so it alone takes `false` for "kept".
     */
    private function with(
        ?CarbonImmutable $firstAttemptedAt = null,
        ?CarbonImmutable $lastAttemptedAt = null,
        SubmitAttemptOutcome|false|null $lastOutcome = false,
        ?CarbonImmutable $ambiguousAt = null,
        ?CarbonImmutable $handedOverAt = null,
        ?CarbonImmutable $firstOverloadedAt = null,
        ?CarbonImmutable $firstConcurrencyLimitedAt = null,
        ?int $invalidApiKeyCount = null,
        ?SubmitRetryNote $retryNote = null,
    ): self {
        return new self(
            $firstAttemptedAt ?? $this->firstAttemptedAt,
            $lastAttemptedAt ?? $this->lastAttemptedAt,
            $lastOutcome === false ? $this->lastOutcome : $lastOutcome,
            $ambiguousAt ?? $this->ambiguousAt,
            $handedOverAt ?? $this->handedOverAt,
            $firstOverloadedAt ?? $this->firstOverloadedAt,
            $firstConcurrencyLimitedAt ?? $this->firstConcurrencyLimitedAt,
            $invalidApiKeyCount ?? $this->invalidApiKeyCount,
            $retryNote ?? $this->retryNote,
        );
    }
}
