<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Actions;

use App\Domain\Generation\Actions\StopSubmittingGenerationAction;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Enums\SubmitAttemptOutcome;
use App\Domain\Generation\Enums\SubmitRetryNote;
use App\Domain\Generation\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\TestCase;

/**
 * The submit job's end: a free failure by the last outcome, or a hand-off to the
 * reconciler when the generation may exist at Pixelforge.
 */
final class StopSubmittingGenerationActionTest extends TestCase
{
    use CapturesLogMessages;
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use RefreshDatabase;

    /**
     * The factory row's hold: its max estimate.
     */
    private const int HOLD = 123_387_500;

    private const string NOW = '2026-11-20T10:30:00Z';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::NOW));
    }

    /**
     * @return array<string, array{SubmitAttemptOutcome, LocalFailureReason}>
     */
    public static function windowEndReasons(): array
    {
        return [
            'the spent budget' => [SubmitAttemptOutcome::BudgetExhausted, LocalFailureReason::ProviderBusy],
            'a rate limit' => [SubmitAttemptOutcome::RateLimited, LocalFailureReason::ProviderBusy],
            'a concurrency limit' => [SubmitAttemptOutcome::ConcurrencyLimited, LocalFailureReason::ProviderBusy],
            'a daily limit' => [SubmitAttemptOutcome::DailyLimited, LocalFailureReason::ProviderBusy],
            'an overload' => [SubmitAttemptOutcome::Overloaded, LocalFailureReason::ProviderOverloaded],
            'a refused key' => [SubmitAttemptOutcome::InvalidApiKey, LocalFailureReason::ServiceUnavailable],
            'no key' => [SubmitAttemptOutcome::NotConfigured, LocalFailureReason::ServiceUnavailable],
        ];
    }

    #[DataProvider('windowEndReasons')]
    public function test_the_window_end_fails_it_free_by_its_last_outcome(SubmitAttemptOutcome $lastOutcome, LocalFailureReason $reason): void
    {
        $generation = Generation::factory()->submitAttempted($lastOutcome)->create();

        $this->stop($generation);

        $this->assertFailedFree($generation, $reason);
        $this->assertSame($reason, $lastOutcome->windowEndReason());
    }

    public function test_a_generation_never_attempted_was_never_sent(): void
    {
        $generation = Generation::factory()->create();

        $this->stop($generation);

        $this->assertFailedFree($generation, LocalFailureReason::ServiceUnavailable);
    }

    public function test_a_latched_generation_is_handed_over_pending_with_its_hold(): void
    {
        $generation = Generation::factory()->submitAttempted(SubmitAttemptOutcome::Overloaded)->submitLatched()->create();

        $this->stop($generation);

        $row = $this->reloadedGeneration($generation);
        $this->assertSame(GenerationStatus::Pending, $row->status);
        $this->assertNull($row->local_failure_reason);
        $this->assertNull($row->provider_cost_nanodollars);
        $this->assertSameInstant(self::NOW, $row->submit_handed_over_at);
        $this->assertSameInstant('2026-11-20T10:29:00Z', $row->submit_ambiguous_at);
        $this->assertSame(SubmitRetryNote::Retrying, $row->submit_retry_note);
        $this->assertSame([['hold', -self::HOLD]], $this->ledgerEntriesFor($generation));
        $this->assertHandOffLogged($generation);
    }

    /**
     * The run died after it may have sent its request (AC 12).
     */
    public function test_an_attempt_with_no_outcome_is_handed_over_latched_at_its_start(): void
    {
        $generation = Generation::factory()->submitAttempted(null)->create();

        $this->stop($generation);

        $row = $this->reloadedGeneration($generation);
        $this->assertSame(GenerationStatus::Pending, $row->status);
        $this->assertSameInstant(self::NOW, $row->submit_handed_over_at);
        $this->assertSameInstant('2026-11-20T10:29:00Z', $row->submit_ambiguous_at);
        $this->assertSame([['hold', -self::HOLD]], $this->ledgerEntriesFor($generation));
    }

    /**
     * A row Pixelforge answered for but that is still pending is not failed: the safe side.
     */
    public function test_a_pending_row_whose_last_attempt_was_accepted_is_handed_over(): void
    {
        $generation = Generation::factory()->submitAttempted(SubmitAttemptOutcome::Accepted)->create();

        $this->stop($generation);

        $this->assertSame(GenerationStatus::Pending, $this->reloadedGeneration($generation)->status);
        $this->assertSameInstant(self::NOW, $this->reloadedGeneration($generation)->submit_handed_over_at);
    }

    /**
     * AC 15: a deadline racing the job's own end writes no second release.
     */
    public function test_a_second_stop_after_a_free_failure_writes_nothing(): void
    {
        $generation = Generation::factory()->submitAttempted(SubmitAttemptOutcome::Overloaded)->create();
        $this->stop($generation);
        $before = [$this->rawGenerationRow($generation), $this->ledgerEntriesFor($generation)];

        $this->stop($generation);

        $this->assertSame($before, [$this->rawGenerationRow($generation), $this->ledgerEntriesFor($generation)]);
    }

    public function test_a_second_stop_after_a_hand_off_writes_nothing(): void
    {
        $generation = Generation::factory()->submitAttempted(SubmitAttemptOutcome::Ambiguous)->create();
        $this->stop($generation);
        $before = [$this->rawGenerationRow($generation), $this->ledgerEntriesFor($generation)];
        $this->travel(5)->minutes();

        $this->stop($generation);

        $this->assertSame($before, [$this->rawGenerationRow($generation), $this->ledgerEntriesFor($generation)]);
    }

    /**
     * @return array<string, array{GenerationStatus}>
     */
    public static function advancedStatuses(): array
    {
        return [
            'queued' => [GenerationStatus::Queued],
            'processing' => [GenerationStatus::Processing],
            'succeeded' => [GenerationStatus::Succeeded],
            'failed' => [GenerationStatus::Failed],
        ];
    }

    #[DataProvider('advancedStatuses')]
    public function test_a_row_that_is_no_longer_pending_is_left_alone(GenerationStatus $status): void
    {
        $generation = $this->givenAGenerationIn($status);
        $before = [$this->rawGenerationRow($generation), $this->ledgerEntriesFor($generation)];

        $this->stop($generation);

        $this->assertSame($before, [$this->rawGenerationRow($generation), $this->ledgerEntriesFor($generation)]);
    }

    private function stop(Generation $generation): void
    {
        $this->app->make(StopSubmittingGenerationAction::class)->execute($generation->id);
    }

    private function assertFailedFree(Generation $generation, LocalFailureReason $reason): void
    {
        $row = $this->reloadedGeneration($generation);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame($reason, $row->local_failure_reason);
        $this->assertNull($row->error_code);
        $this->assertNull($row->error_message);
        $this->assertNull($row->provider_id);
        $this->assertSame(0, $row->provider_cost_nanodollars);
        $this->assertSame(0, $row->user_charge_nanodollars);
        $this->assertNull($row->submit_handed_over_at);
        $this->assertSame([['hold', -self::HOLD], ['release', self::HOLD]], $this->ledgerEntriesFor($generation));
        $this->assertSame(0, $this->ledgerNetFor($generation));
    }

    private function assertHandOffLogged(Generation $generation): void
    {
        $handOffs = array_values(array_filter(
            $this->loggedMessages(),
            fn (MessageLogged $logged): bool => $logged->level === 'warning' && ($logged->context['generation_id'] ?? null) === $generation->id,
        ));

        $this->assertCount(1, $handOffs);
        $this->assertSame([
            'generation_id' => $generation->id,
            'first_attempted_at' => '2026-11-20T10:29:00Z',
            'last_attempted_at' => '2026-11-20T10:29:00Z',
            'last_outcome' => 'overloaded',
        ], $handOffs[0]->context);
    }
}
