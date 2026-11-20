<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Domain\Generation\Actions\CreateGenerationAction;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Jobs\SubmitGenerationJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;

/**
 * Creating a generation queues its submit job, carrying only the ULID, and
 * only once the row and its hold have committed. These tests call
 * CreateGenerationAction directly, because createGenerationFor() holds the
 * job back. `Queue::fake()` records a job even inside a transaction that
 * rolls back, so the dispatch is observed on the real `database` queue.
 */
final class SubmitGenerationDispatchTest extends TestCase
{
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;

    /**
     * Bounded by time, not tries (D-46): no `maxTries`, so the worker's `--tries=3`
     * never applies, and `retryUntil` is the window plus the grace from dispatch.
     */
    public function test_creating_a_generation_queues_one_time_bounded_submit_job_carrying_its_ulid(): void
    {
        config(['queue.default' => 'database']);
        $this->travelTo(CarbonImmutable::parse('2026-11-20T10:00:00Z'));

        $generation = $this->createThroughTheAction();

        $jobs = DB::table('jobs')->get();
        $this->assertCount(1, $jobs);
        $payload = self::payloadOf($jobs[0]);
        $this->assertSame(SubmitGenerationJob::class, $payload['displayName']);
        $this->assertNull($payload['maxTries']);
        $this->assertSame('5,15,30,60', $payload['backoff']);
        $this->assertSame(config()->integer('pixelforge.submit.job_timeout_seconds'), $payload['timeout']);
        $this->assertSame(CarbonImmutable::parse('2026-11-20T10:40:00Z')->getTimestamp(), $payload['retryUntil']);
        $this->assertSame($generation->id, self::commandOf($payload)->generationId);
    }

    /**
     * RefreshDatabase's own transaction is level 1, and the action's is level 2.
     */
    public function test_the_job_is_pushed_only_after_the_actions_transaction_commits(): void
    {
        config(['queue.default' => 'database']);
        $levels = [];
        Event::listen(JobQueueing::class, function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });

        $this->createThroughTheAction();

        $this->assertSame([1], $levels);
    }

    public function test_the_queued_payload_carries_neither_the_key_nor_the_prompt(): void
    {
        config(['queue.default' => 'database']);

        $this->createThroughTheAction();

        $raw = DB::table('jobs')->value('payload');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString(self::PIXELFORGE_API_KEY, $raw);
        $this->assertStringNotContainsString(self::LIGHTHOUSE_PROMPT, $raw);
        $this->assertStringNotContainsString('example.com', $raw);
    }

    public function test_a_caller_transaction_that_rolls_back_queues_nothing(): void
    {
        config(['queue.default' => 'database']);

        try {
            DB::transaction(function (): void {
                $this->createThroughTheAction();

                throw new RuntimeException('The caller failed after creating.');
            });
        } catch (RuntimeException) {
            // The rollback is the point.
        }

        $this->assertSame(0, Generation::query()->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_hold_that_fails_inside_the_action_queues_nothing(): void
    {
        config(['queue.default' => 'database']);
        CreditLedgerEntry::created(function (): void {
            throw new RuntimeException('The hold could not be written.');
        });

        try {
            $this->createThroughTheAction();
            $this->fail('Expected the hold to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The hold could not be written.', $exception->getMessage());
        }

        $this->assertSame(0, Generation::query()->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_on_the_sync_queue_the_generation_is_submitted_once_it_commits(): void
    {
        Http::fake(fn (Request $request): PromiseInterface => self::pixelforgeAccepted(
            self::generationObjectFor(self::generationNamedBy($request), GenerationStatus::Queued),
        ));

        $generation = $this->createThroughTheAction();

        $this->assertCount(1, $this->recordedCreateRequests());
        $this->assertSame(GenerationStatus::Queued, $this->reloadedGeneration($generation)->status);
    }

    /**
     * AC 19: a hung request must give up before the job does, and the job before the
     * queue hands it to another worker, so one job never runs twice at once. Every
     * value is read from config, as the worker reads it.
     */
    public function test_the_timeouts_nest_below_the_queue_retry_window(): void
    {
        config(['queue.default' => 'database']);
        $this->createThroughTheAction();

        $payload = self::payloadOf(DB::table('jobs')->first());
        $connectTimeout = config()->integer('pixelforge.connect_timeout_seconds');
        $requestTimeout = config()->integer('pixelforge.request_timeout_seconds');
        $jobTimeout = config()->integer('pixelforge.submit.job_timeout_seconds');
        $retryAfter = config()->integer('queue.connections.database.retry_after');

        $this->assertSame($jobTimeout, $payload['timeout']);
        $this->assertLessThanOrEqual($requestTimeout, $connectTimeout);
        $this->assertLessThan($jobTimeout, $requestTimeout);
        $this->assertLessThan($retryAfter, $jobTimeout);
    }

    private function createThroughTheAction(): Generation
    {
        return $this->app->make(CreateGenerationAction::class)->execute(User::factory()->create(), self::lighthouseRequest());
    }

    /**
     * The row the create request names in its metadata.
     */
    private static function generationNamedBy(Request $request): Generation
    {
        $metadata = self::decodedRequestBody($request)['metadata'] ?? null;
        self::assertIsArray($metadata);

        return Generation::query()->whereKey($metadata['generation_id'] ?? null)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private static function payloadOf(?object $job): array
    {
        self::assertNotNull($job);
        self::assertIsString($job->payload ?? null);
        $payload = json_decode($job->payload, associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function commandOf(array $payload): SubmitGenerationJob
    {
        $data = $payload['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsString($data['command'] ?? null);
        $command = unserialize($data['command']);
        self::assertInstanceOf(SubmitGenerationJob::class, $command);

        return $command;
    }
}
