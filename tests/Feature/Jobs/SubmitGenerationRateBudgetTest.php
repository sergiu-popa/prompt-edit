<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Enums\SubmitAttemptOutcome;
use App\Domain\Generation\Models\Generation;
use App\Jobs\SubmitGenerationJob;
use App\Support\Pixelforge\PixelforgeRequestBudget;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;

/**
 * AC 1 and AC 2: every submit run takes from one account-wide budget, counted in the
 * database every process shares. A spent budget sends nothing, releases the job
 * until it frees up and never fails the generation before its window ends.
 */
final class SubmitGenerationRateBudgetTest extends TestCase
{
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-20T10:00:00Z'));
        Http::fake(fn (Request $request): PromiseInterface => self::pixelforgeAccepted(
            self::generationObjectFor(self::generationNamedBy($request), GenerationStatus::Queued),
        ));
    }

    /**
     * The second run builds its store afresh, as another worker process would, and
     * still sees the first run's unit.
     */
    public function test_two_job_runs_share_one_count(): void
    {
        config(['pixelforge.rate_limit_per_minute' => 1]);
        $first = $this->givenTheLighthouseGeneration();
        $second = $this->givenTheLighthouseGeneration();

        $this->runSubmitJob($first)->assertNotReleased();
        Cache::forgetDriver('database');
        $job = $this->runSubmitJob($second);

        $this->assertCount(1, $this->recordedCreateRequests());
        $this->assertSame(GenerationStatus::Queued, $this->reloadedGeneration($first)->status);
        $this->assertSame(GenerationStatus::Pending, $this->reloadedGeneration($second)->status);
        $this->assertReleasedWithin(1, 60, $job);
        $this->assertSame(1, DB::table('cache')->where('key', 'like', '%' . PixelforgeRequestBudget::BUDGET_KEY)->count());
    }

    /**
     * With the default 50 a minute, the 51st call in the window is not sent.
     */
    public function test_a_spent_budget_sends_nothing_and_waits_for_it_to_free_up(): void
    {
        $this->assertSame(50, config()->integer('pixelforge.rate_limit_per_minute'));
        $lighthouse = $this->givenTheLighthouseGeneration();
        $this->travel(20)->seconds();
        $this->takeUnits(50);
        $this->travel(15)->seconds();

        $job = $this->runSubmitJob($lighthouse);

        Http::assertNothingSent();
        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Pending, $row->status);
        $this->assertSame(SubmitAttemptOutcome::BudgetExhausted, $row->submit_last_outcome);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($lighthouse));
        $job->assertReleased(45);

        $this->travel(45)->seconds();
        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertNotReleased();

        $this->assertCount(1, $this->recordedCreateRequests());
        $this->assertSame(GenerationStatus::Queued, $this->reloadedGeneration($lighthouse)->status);
    }

    /**
     * Waiting for the local budget never fails a generation on its own; only the
     * window ends it, as `provider_busy` (AC 2, AC 17).
     */
    public function test_budget_waits_end_only_with_the_window(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        $runs = 0;

        while (true) {
            $this->takeUnits(50);
            $job = $this->runSubmitJob($this->reloadedGeneration($lighthouse));
            $runs++;

            if (! $job->job instanceof FakeJob || ! $job->job->isReleased()) {
                break;
            }

            $this->assertSame(GenerationStatus::Pending, $this->reloadedGeneration($lighthouse)->status, sprintf('Failed at run %d.', $runs));
            $this->travel((int) $job->job->releaseDelay)->seconds();
        }

        Http::assertNothingSent();
        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(LocalFailureReason::ProviderBusy, $row->local_failure_reason);
        $this->assertSame([['hold', -457_150_000], ['release', 457_150_000]], $this->ledgerEntriesFor($lighthouse));
        $this->assertGreaterThanOrEqual(29, $runs);
        $this->assertTrue(CarbonImmutable::now()->greaterThanOrEqualTo(CarbonImmutable::parse('2026-11-20T10:29:00Z')));
    }

    /**
     * Units taken by other calls in the current window, in the shared store.
     */
    private function takeUnits(int $units): void
    {
        (new RateLimiter(Cache::store('database')))->increment(PixelforgeRequestBudget::BUDGET_KEY, PixelforgeRequestBudget::WINDOW_SECONDS, $units);
    }

    private function assertReleasedWithin(int $min, int $max, SubmitGenerationJob $job): void
    {
        $this->assertInstanceOf(FakeJob::class, $job->job);
        $this->assertTrue($job->job->isReleased());
        $this->assertGreaterThanOrEqual($min, $job->job->releaseDelay);
        $this->assertLessThanOrEqual($max, $job->job->releaseDelay);
    }

    private static function generationNamedBy(Request $request): Generation
    {
        $metadata = self::decodedRequestBody($request)['metadata'] ?? null;
        self::assertIsArray($metadata);

        return Generation::query()->whereKey($metadata['generation_id'] ?? null)->firstOrFail();
    }
}
