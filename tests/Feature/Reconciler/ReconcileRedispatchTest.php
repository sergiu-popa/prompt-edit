<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciler;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\SubmitAttemptOutcome;
use App\Domain\Generation\Models\Generation;
use App\Jobs\SubmitGenerationJob;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\Concerns\RunsTheReconciler;
use Tests\TestCase;

/**
 * The unsubmitted step: generations Pixelforge never confirmed, whose submit job was
 * handed over, stopped or died, are re-dispatched with their own ULID (AC 12–14).
 * The pushed job is held back and run by hand, as the worker would run it.
 */
final class ReconcileRedispatchTest extends TestCase
{
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;
    use RunsTheReconciler;

    private const string START = '2026-11-20T12:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::START));
        Queue::fake([SubmitGenerationJob::class]);
    }

    /**
     * AC 12: due at once, then at D-50's cadence, a minute later for a young row.
     */
    public function test_a_handed_over_row_is_redispatched_with_its_ulid_and_the_runs_instant_then_waits_its_turn(): void
    {
        $generation = $this->givenAHandedOverGeneration();

        $this->runReconciler();

        $jobs = $this->pushedSubmitJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame($generation->id, $jobs[0]->generationId);
        $this->assertSameInstant(self::START, $jobs[0]->redispatchedAt);
        $this->assertSameInstant('2026-11-20T12:01:00Z', $this->reloadedGeneration($generation)->reconcile_recheck_at);

        $this->travel(30)->seconds();
        $this->runReconciler();
        $this->assertCount(1, $this->pushedSubmitJobs());

        $this->travel(30)->seconds();
        $this->runReconciler();
        $jobs = $this->pushedSubmitJobs();
        $this->assertCount(2, $jobs);
        $this->assertSameInstant('2026-11-20T12:01:00Z', $jobs[1]->redispatchedAt);
    }

    /**
     * AC 12's example: a job whose last attempt was 4 min ago may still be retrying.
     */
    public function test_a_row_last_attempted_6_minutes_ago_is_redispatched_and_one_4_minutes_ago_is_not(): void
    {
        $stopped = $this->givenAGenerationLastAttempted(360);
        $this->givenAGenerationLastAttempted(240);

        $this->runReconciler();

        $this->assertSame([$stopped->id], array_map(fn (SubmitGenerationJob $job): string => $job->generationId, $this->pushedSubmitJobs()));
    }

    /**
     * AC 14: the create had landed, so Pixelforge replays it as it is now, applied
     * once with no second hold or charge.
     */
    public function test_a_replayed_succeeded_create_is_applied_once(): void
    {
        $generation = $this->givenAHandedOverGeneration();
        $this->runReconciler();
        Http::fake(['*' => self::pixelforgeAccepted(self::generationObjectFor($generation, GenerationStatus::Succeeded), replayed: 'true')]);

        $this->runSubmitJob($generation, $this->pushedSubmitJobs()[0]->redispatchedAt);

        $row = $this->reloadedGeneration($generation);
        $this->assertCount(1, $this->recordedCreateRequests());
        $this->assertSame([$generation->id], $this->recordedCreateRequests()[0]->header('Idempotency-Key'));
        $this->assertSame(GenerationStatus::Succeeded, $row->status);
        $this->assertSame('gen_' . strtoupper($generation->id), $row->provider_id);
        $this->assertSame([['hold', -123_387_500], ['release', 123_387_500], ['charge', -109_312_500]], $this->ledgerEntriesFor($generation));
        $this->assertSame(1, Generation::query()->count());
    }

    public function test_a_create_that_had_not_landed_is_created_now(): void
    {
        $generation = $this->givenAHandedOverGeneration();
        $this->runReconciler();
        Http::fake(['*' => self::pixelforgeAccepted(self::generationObjectFor($generation, GenerationStatus::Queued))]);

        $this->runSubmitJob($generation, $this->pushedSubmitJobs()[0]->redispatchedAt);

        $row = $this->reloadedGeneration($generation);
        $this->assertSame(GenerationStatus::Queued, $row->status);
        $this->assertSame('gen_' . strtoupper($generation->id), $row->provider_id);
        $this->assertSame([['hold', -123_387_500]], $this->ledgerEntriesFor($generation));
    }

    /**
     * AC 16: polls come first; a full batch of them leaves no room this run.
     */
    public function test_a_batch_full_of_polls_leaves_the_unsubmitted_row_for_a_later_run(): void
    {
        for ($row = 0; $row < 20; $row++) {
            Generation::factory()->processing()->create(['last_provider_update_at' => CarbonImmutable::now()->subMinutes(10)]);
        }

        $waiting = $this->givenAHandedOverGeneration();
        Http::fake(fn (Request $request): PromiseInterface => self::pixelforgeRetrieved(self::generationObjectFor(
            Generation::query()->where('provider_id', basename((string) parse_url($request->url(), PHP_URL_PATH)))->firstOrFail(),
            GenerationStatus::Processing,
        )));

        $this->runReconciler();

        $this->assertCount(20, $this->recordedRetrieveRequests());
        $this->assertSame([], $this->pushedSubmitJobs());
        $this->assertNull($this->reloadedGeneration($waiting)->reconcile_recheck_at);

        $this->travel(1)->seconds();
        $this->runReconciler();

        $this->assertSame([$waiting->id], array_map(fn (SubmitGenerationJob $job): string => $job->generationId, $this->pushedSubmitJobs()));
    }

    private function givenAHandedOverGeneration(): Generation
    {
        return Generation::factory()->submitAttempted(SubmitAttemptOutcome::Ambiguous)->submitLatched()->create([
            'submit_handed_over_at' => CarbonImmutable::now()->subSeconds(5),
        ]);
    }

    private function givenAGenerationLastAttempted(int $secondsAgo): Generation
    {
        $attemptedAt = CarbonImmutable::now()->subSeconds($secondsAgo);

        return Generation::factory()->create([
            'created_at' => $attemptedAt->subMinute(),
            'submit_first_attempted_at' => $attemptedAt,
            'submit_last_attempted_at' => $attemptedAt,
            'submit_last_outcome' => SubmitAttemptOutcome::Overloaded,
        ]);
    }

    /**
     * @return list<SubmitGenerationJob>
     */
    private function pushedSubmitJobs(): array
    {
        $queue = Queue::getFacadeRoot();
        $this->assertInstanceOf(QueueFake::class, $queue);

        return array_values(array_filter(
            $queue->pushed(SubmitGenerationJob::class)->all(),
            fn (mixed $job): bool => $job instanceof SubmitGenerationJob,
        ));
    }
}
