<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Enums\SubmitAttemptOutcome;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\ValueObjects\SubmitRetryPolicy;
use App\Jobs\SubmitGenerationJob;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;

/**
 * AC 13: a re-dispatched submit job reuses the row, its key and its bytes, and is
 * owed one create attempt even past PE-013's window (D-52). After that attempt,
 * PE-013's decision is unchanged.
 */
final class SubmitGenerationRedispatchTest extends TestCase
{
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;

    private const string START = '2026-11-20T10:00:00Z';

    private const string READ_TIMEOUT = 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received';

    /**
     * The submit window, 30 min from the first attempt.
     */
    private const int WINDOW_SECONDS = 1_800;

    private const int LIGHTHOUSE_HOLD = 457_150_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::START));
    }

    public function test_a_handed_over_row_past_its_window_sends_one_create_with_its_first_attempts_key_and_bytes(): void
    {
        $lighthouse = $this->givenTheHandedOverLighthouse();

        $this->runSubmitJob($lighthouse, CarbonImmutable::now());

        [$first, $redispatched] = $this->recordedCreateRequests();
        $this->assertCount(2, $this->recordedCreateRequests());
        $this->assertSame($first->header('Idempotency-Key'), $redispatched->header('Idempotency-Key'));
        $this->assertSame([$lighthouse->id], $redispatched->header('Idempotency-Key'));
        $this->assertSame($first->body(), $redispatched->body());
        $this->assertSame(['generation_id' => $lighthouse->id], self::decodedRequestBody($redispatched)['metadata']);
        $this->assertSame(1, Generation::query()->count());
    }

    /**
     * Not definitive again: the stop finds it handed over and leaves it so, back
     * with the reconciler.
     */
    public function test_a_timeout_on_the_owed_run_leaves_it_handed_over_with_its_hold(): void
    {
        $lighthouse = $this->givenTheHandedOverLighthouse();
        $handedOverAt = $this->reloadedGeneration($lighthouse)->submit_handed_over_at;

        $job = $this->runSubmitJob($lighthouse, CarbonImmutable::now());

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertFalse($this->wasReleased($job));
        $this->assertSame(GenerationStatus::Pending, $row->status);
        $this->assertTrue($row->submit_handed_over_at?->equalTo($handedOverAt ?? CarbonImmutable::now()));
        $this->assertSame(SubmitAttemptOutcome::Ambiguous, $row->submit_last_outcome);
        $this->assertSame([['hold', -self::LIGHTHOUSE_HOLD]], $this->ledgerEntriesFor($lighthouse));
    }

    public function test_a_202_on_the_owed_run_leaves_it_queued_with_its_provider_id(): void
    {
        $lighthouse = $this->givenTheHandedOverLighthouse(fn (Generation $generation): PromiseInterface => self::pixelforgeAccepted(self::generationObjectFor($generation, GenerationStatus::Queued)));

        $this->runSubmitJob($lighthouse, CarbonImmutable::now());

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Queued, $row->status);
        $this->assertSame('gen_' . strtoupper($lighthouse->id), $row->provider_id);
        $this->assertSame([['hold', -self::LIGHTHOUSE_HOLD]], $this->ledgerEntriesFor($lighthouse));
    }

    /**
     * Never latched, so a "nothing created" answer past the window fails it free by
     * that outcome: PE-013's own rule.
     */
    public function test_a_row_never_latched_whose_owed_run_is_overloaded_fails_free(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => self::pixelforgeErrorFor(PixelforgeErrorCode::Overloaded, headers: ['Retry-After' => '2'])]);
        $this->runSubmitJob($lighthouse);
        $this->travel(self::WINDOW_SECONDS + 360)->seconds();

        $this->runSubmitJob($lighthouse, CarbonImmutable::now());

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertCount(2, $this->recordedCreateRequests());
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(LocalFailureReason::ProviderOverloaded, $row->local_failure_reason);
        $this->assertSame([['hold', -self::LIGHTHOUSE_HOLD], ['release', self::LIGHTHOUSE_HOLD]], $this->ledgerEntriesFor($lighthouse));
    }

    /**
     * The owed run is one run: the same job, or a duplicate of it, run again sends
     * nothing.
     */
    public function test_the_same_redispatch_run_again_sends_nothing(): void
    {
        $lighthouse = $this->givenTheHandedOverLighthouse();
        $redispatchedAt = CarbonImmutable::now();
        $this->runSubmitJob($lighthouse, $redispatchedAt);

        $this->travel(5)->seconds();
        $job = $this->runSubmitJob($lighthouse, $redispatchedAt);

        $this->assertCount(2, $this->recordedCreateRequests());
        $this->assertFalse($this->wasReleased($job));
    }

    public function test_an_ordinary_job_past_its_window_still_stops_without_sending(): void
    {
        $lighthouse = $this->givenTheHandedOverLighthouse();

        $job = $this->runSubmitJob($lighthouse);

        $this->assertCount(1, $this->recordedCreateRequests());
        $this->assertFalse($this->wasReleased($job));
        $this->assertSame(GenerationStatus::Pending, $this->reloadedGeneration($lighthouse)->status);
    }

    /**
     * Its dispatch was lost before any attempt, so its whole window lies ahead.
     */
    public function test_a_never_attempted_row_gets_the_normal_retries(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        $this->travel(6)->minutes();
        Http::fake(['*' => self::pixelforgeErrorFor(PixelforgeErrorCode::Overloaded, headers: ['Retry-After' => '2'])]);
        $redispatchedAt = CarbonImmutable::now();

        $first = $this->runSubmitJob($lighthouse, $redispatchedAt);
        $this->travel(2)->seconds();
        $second = $this->runSubmitJob($lighthouse, $redispatchedAt);

        $this->assertCount(2, $this->recordedCreateRequests());
        $this->assertSame(SubmitRetryPolicy::OVERLOADED_WAIT_SECONDS, $this->releaseDelayOf($first));
        $this->assertGreaterThanOrEqual(SubmitRetryPolicy::OVERLOADED_WAIT_SECONDS, $this->releaseDelayOf($second));
        $this->assertSame(GenerationStatus::Pending, $this->reloadedGeneration($lighthouse)->status);
        $this->assertNull($this->reloadedGeneration($lighthouse)->submit_handed_over_at);
    }

    /**
     * The first attempt times out at the start, so the row is latched; past its
     * window the stop hands it over. Six minutes later the reconciler re-dispatches
     * it, answered by the given responder (a timeout by default).
     *
     * @param  ?callable(Generation): PromiseInterface  $redispatchAnswer
     */
    private function givenTheHandedOverLighthouse(?callable $redispatchAnswer = null): Generation
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        $sequence = Http::sequence()->pushFailedConnection(self::READ_TIMEOUT);
        $redispatchAnswer === null ? $sequence->pushFailedConnection(self::READ_TIMEOUT) : $sequence->pushResponse($redispatchAnswer($lighthouse));
        Http::fake(['*' => $sequence]);

        $this->runSubmitJob($lighthouse);
        $this->travel(self::WINDOW_SECONDS)->seconds();
        $this->runSubmitJob($lighthouse);
        $this->travel(6)->minutes();

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertNotNull($row->submit_ambiguous_at);
        $this->assertNotNull($row->submit_handed_over_at);
        $this->assertCount(1, $this->recordedCreateRequests());

        return $row;
    }

    private function wasReleased(SubmitGenerationJob $job): bool
    {
        return $job->job instanceof FakeJob && $job->job->isReleased();
    }

    private function releaseDelayOf(SubmitGenerationJob $job): int
    {
        $this->assertTrue($this->wasReleased($job));
        $this->assertInstanceOf(FakeJob::class, $job->job);

        return (int) $job->job->releaseDelay;
    }
}
