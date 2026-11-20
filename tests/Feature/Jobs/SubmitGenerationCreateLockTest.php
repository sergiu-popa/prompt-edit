<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Billing\Exceptions\InvalidRateException;
use App\Domain\Generation\Actions\SubmitGenerationAction;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Support\Pixelforge\PixelforgeCreateLock;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Cache\LockProvider;
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
 * AC 15: never two create requests for one generation in flight. A run that starts
 * while another's create is in flight waits and records nothing (D-53).
 */
final class SubmitGenerationCreateLockTest extends TestCase
{
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;

    private const string READ_TIMEOUT = 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-20T10:00:00Z'));
    }

    /**
     * The second run starts inside the first's request, as a re-dispatch landing
     * while the first job's create hangs would.
     */
    public function test_a_run_during_another_runs_create_waits_and_the_two_creates_never_overlap(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        $inFlight = 0;
        $mostInFlight = 0;
        $creates = 0;
        $overlappingRun = null;
        $rowDuringTheCreate = null;
        Http::fake(function (Request $request) use ($lighthouse, &$inFlight, &$mostInFlight, &$creates, &$overlappingRun, &$rowDuringTheCreate): PromiseInterface {
            $inFlight++;
            $creates++;
            $mostInFlight = max($mostInFlight, $inFlight);

            try {
                if ($creates > 1) {
                    return self::pixelforgeAccepted(self::generationObjectFor($lighthouse, GenerationStatus::Queued));
                }

                $rowDuringTheCreate = $this->rawGenerationRow($lighthouse);
                $overlappingRun = $this->runSubmitJob($lighthouse);
                $this->assertSame($rowDuringTheCreate, $this->rawGenerationRow($lighthouse), 'The waiting run recorded something.');

                throw new ConnectException(self::READ_TIMEOUT, $request->toPsrRequest());
            } finally {
                $inFlight--;
            }
        });

        $first = $this->runSubmitJob($lighthouse);

        $this->assertInstanceOf(SubmitGenerationJob::class, $overlappingRun);
        $this->assertReleasedFor(SubmitGenerationAction::CREATE_IN_FLIGHT_WAIT_SECONDS, $overlappingRun);
        $this->assertCount(1, $this->recordedCreateRequests());
        $this->assertSame(GenerationStatus::Pending, $this->reloadedGeneration($lighthouse)->status);
        $this->assertInstanceOf(FakeJob::class, $first->job);
        $this->assertTrue($first->job->isReleased(), 'The ambiguous first run waits to retry.');

        $this->travel(SubmitGenerationAction::CREATE_IN_FLIGHT_WAIT_SECONDS)->seconds();
        $this->runSubmitJob($lighthouse);

        [$firstCreate, $secondCreate] = $this->recordedCreateRequests();
        $this->assertSame($firstCreate->body(), $secondCreate->body());
        $this->assertSame($firstCreate->header('Idempotency-Key'), $secondCreate->header('Idempotency-Key'));
        $this->assertSame(1, $mostInFlight);
        $this->assertSame(GenerationStatus::Queued, $this->reloadedGeneration($lighthouse)->status);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($lighthouse));
    }

    public function test_no_lock_row_remains_after_a_run(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => self::pixelforgeAccepted(self::generationObjectFor($lighthouse, GenerationStatus::Queued))]);

        $this->runSubmitJob($lighthouse);

        $this->assertSame(0, DB::table('cache_locks')->count());
    }

    public function test_no_lock_row_remains_after_a_run_that_throws(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => self::pixelforgeAccepted(self::generationObjectFor($lighthouse, GenerationStatus::Succeeded, ['rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.5005',
        ]]))]);

        try {
            $this->runSubmitJob($lighthouse);
            $this->fail('Expected the unpriceable answer to throw.');
        } catch (InvalidRateException) {
            $this->assertSame(0, DB::table('cache_locks')->count());
        }
    }

    /**
     * A run killed at its timeout never releases its lock; it holds the next run off
     * until it expires, past the job's timeout.
     */
    public function test_a_dead_runs_lock_holds_a_run_off_until_it_expires(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => self::pixelforgeAccepted(self::generationObjectFor($lighthouse, GenerationStatus::Queued))]);
        $store = Cache::store('database')->getStore();
        $this->assertInstanceOf(LockProvider::class, $store);
        $this->assertTrue($store->lock(PixelforgeCreateLock::KEY_PREFIX . $lighthouse->id, 90)->get());

        $this->travel(89)->seconds();
        $this->assertReleasedFor(SubmitGenerationAction::CREATE_IN_FLIGHT_WAIT_SECONDS, $this->runSubmitJob($lighthouse));
        $this->assertSame([], $this->recordedCreateRequests());
        $this->assertNull($this->reloadedGeneration($lighthouse)->submit_first_attempted_at);

        $this->travel(1)->seconds();
        $this->runSubmitJob($lighthouse);

        $this->assertCount(1, $this->recordedCreateRequests());
        $this->assertSame(GenerationStatus::Queued, $this->reloadedGeneration($lighthouse)->status);
    }

    private function assertReleasedFor(int $seconds, SubmitGenerationJob $job): void
    {
        $this->assertInstanceOf(FakeJob::class, $job->job);
        $this->assertTrue($job->job->isReleased());
        $this->assertSame($seconds, $job->job->releaseDelay);
    }
}
