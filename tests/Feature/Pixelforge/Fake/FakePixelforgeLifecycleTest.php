<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Fake;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Support\Pixelforge\Exceptions\WebhookSecretNotConfiguredException;
use App\Support\Pixelforge\Fake\AdvanceFakeGenerationJob;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\InteractsWithFakePixelforge;
use Tests\TestCase;

/**
 * Each accepted generation moves queued → processing → terminal on delayed
 * jobs, and every step reaches our endpoint as a webhook carrying the
 * generation as it is at that moment.
 */
final class FakePixelforgeLifecycleTest extends TestCase
{
    use InteractsWithFakePixelforge;
    use RefreshDatabase;

    private const string FROZEN_NOW = '2026-11-19T12:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));
    }

    public function test_a_create_holds_the_generation_queued_for_the_queue_delay(): void
    {
        $created = $this->createThroughFake(self::lighthousePayload());

        $jobs = $this->pushedFakeLifecycleJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame($created->snapshot->id, $jobs[0]->fakeGenerationId);
        $this->assertSame(1, self::delaySecondsOf($jobs[0]));
        $this->assertSame([], $this->fakeWebhookRequests());
    }

    public function test_after_the_queue_delay_it_starts_processing_and_says_so(): void
    {
        $created = $this->createThroughFake(self::lighthousePayload());

        $this->runNextFakeLifecycleJob();

        $row = $this->fakeGenerationRow($created->snapshot->id);
        $this->assertSame(GenerationStatus::Processing, $row->status());
        $this->assertSame('2026-11-19T12:00:01Z', $row->snapshot['started_at']);

        $requests = $this->fakeWebhookRequests();
        $this->assertCount(1, $requests);
        $event = self::decoded($requests[0]);
        $this->assertSame('generation.processing', $event['type']);
        $this->assertSame('processing', $event['data']['object']['status']);
        $this->assertSame('2026-11-19T12:00:01Z', $event['data']['object']['started_at']);
        $this->assertNull($event['data']['object']['completed_at']);

        $jobs = $this->pushedFakeLifecycleJobs();
        $this->assertCount(2, $jobs);
        $this->assertSame(3, self::delaySecondsOf($jobs[1]));
    }

    public function test_after_the_processing_delay_it_succeeds_and_says_so(): void
    {
        $created = $this->createThroughFake(self::lighthousePayload());

        $this->runFakeLifecycle();

        $row = $this->fakeGenerationRow($created->snapshot->id);
        $this->assertSame(GenerationStatus::Succeeded, $row->status());
        $this->assertSame('2026-11-19T12:00:04Z', $row->snapshot['completed_at']);

        $requests = $this->fakeWebhookRequests();
        $this->assertCount(2, $requests);
        $succeeded = self::decoded($requests[1]);
        $this->assertSame('generation.succeeded', $succeeded['type']);
        $this->assertSame($row->snapshot, $succeeded['data']['object']);
        $this->assertCount(2, $this->pushedFakeLifecycleJobs());
    }

    public function test_a_succeeded_lighthouse_reports_usage_within_its_estimate(): void
    {
        $created = $this->createThroughFake(self::lighthousePayload());

        $this->runFakeLifecycle();

        $usage = self::decoded($this->fakeWebhookRequests()[1])['data']['object']['usage'];
        $this->assertSame($this->fakeGenerationRow($created->snapshot->id)->usage, $usage);
        $this->assertSame(765, $usage['input_image_tokens']);
        $this->assertSame(6_450, $usage['output_tokens']);
        $this->assertGreaterThanOrEqual(10, $usage['input_text_tokens']);
        $this->assertLessThanOrEqual(14, $usage['input_text_tokens']);
        $this->assertGreaterThanOrEqual(1_000, $usage['reasoning_tokens']);
        $this->assertLessThanOrEqual(8_000, $usage['reasoning_tokens']);
        $this->assertSame(
            $usage['input_text_tokens'] + $usage['input_image_tokens'] + $usage['output_tokens'] + $usage['reasoning_tokens'],
            $usage['total_tokens'],
        );

        $provider = $usage['input_text_tokens'] * 5_000 + $usage['input_image_tokens'] * 10_000 + $usage['output_tokens'] * 40_000 + $usage['reasoning_tokens'] * 12_500;
        $charge = intdiv($provider * 125 + 99, 100);
        $this->assertGreaterThanOrEqual(278_200_000, $provider);
        $this->assertLessThanOrEqual(365_720_000, $provider);
        $this->assertGreaterThanOrEqual(347_750_000, $charge);
        $this->assertLessThanOrEqual(457_150_000, $charge);
    }

    public function test_a_succeeded_image_url_is_root_relative_loads_and_lives_a_day(): void
    {
        $created = $this->createThroughFake(self::lighthousePayload());

        $this->runFakeLifecycle();

        $snapshot = $this->fakeGenerationRow($created->snapshot->id)->snapshot;
        $this->assertIsString($snapshot['image_url']);
        $this->assertStringStartsWith('/fake-pixelforge/images/' . $created->snapshot->id . '.png?', $snapshot['image_url']);
        $this->assertSame('2026-11-20T12:00:04Z', $snapshot['image_url_expires_at']);

        $response = $this->get($snapshot['image_url']);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
    }

    public function test_a_replay_after_the_lifecycle_answers_the_generation_as_it_is_now(): void
    {
        $payload = self::lighthousePayload();
        $created = $this->createThroughFake($payload);
        $this->runFakeLifecycle();

        $replay = $this->createThroughFake($payload);

        $this->assertSame($created->snapshot->id, $replay->snapshot->id);
        $this->assertTrue($replay->replayed);
        $this->assertSame(GenerationStatus::Succeeded, $replay->snapshot->status);
        $this->assertNotNull($replay->snapshot->usage);
        $this->assertSame($this->fakeGenerationRow($created->snapshot->id)->usage['reasoning_tokens'], $replay->snapshot->usage->reasoningTokens);
        $this->assertCount(2, $this->fakeWebhookRequests());
        $this->assertCount(2, $this->pushedFakeLifecycleJobs());
    }

    /**
     * The state is saved before the send, so a refused webhook changes
     * nothing, and it is never sent again.
     */
    public function test_a_failed_delivery_never_stops_the_lifecycle_or_repeats_a_webhook(): void
    {
        $this->answerFakeWebhooksWith(fn (Request $request): PromiseInterface => Http::response('', $request->header('Pixelforge-Event-Type')[0] === 'generation.processing' ? 500 : 200));
        $created = $this->createThroughFake(self::lighthousePayload());

        $this->runFakeLifecycle();

        $this->assertSame(GenerationStatus::Succeeded, $this->fakeGenerationRow($created->snapshot->id)->status());
        $this->assertSame(
            ['generation.processing', 'generation.succeeded'],
            array_map(fn (Request $request): mixed => self::decoded($request)['type'], $this->fakeWebhookRequests()),
        );
    }

    /**
     * A secret removed while a generation is in flight stops that webhook,
     * not the lifecycle: the generation still ends once a secret is back.
     */
    public function test_a_webhook_that_cannot_be_signed_never_stalls_the_lifecycle(): void
    {
        $created = $this->createThroughFake(self::lighthousePayload());
        config(['pixelforge.webhook_secret' => '']);

        try {
            $this->runNextFakeLifecycleJob();
            $this->fail('Expected the processing webhook to be refused without a secret.');
        } catch (WebhookSecretNotConfiguredException) {
            //
        }

        $this->assertSame(GenerationStatus::Processing, $this->fakeGenerationRow($created->snapshot->id)->status());
        $this->assertSame([], $this->fakeWebhookRequests());
        $jobs = $this->pushedFakeLifecycleJobs();
        $this->assertCount(2, $jobs);
        $this->assertSame(3, self::delaySecondsOf($jobs[1]));

        config(['pixelforge.webhook_secret' => self::FAKE_WEBHOOK_SECRET]);
        $this->runFakeLifecycle();

        $this->assertSame(GenerationStatus::Succeeded, $this->fakeGenerationRow($created->snapshot->id)->status());
        $this->assertSame(
            ['generation.succeeded'],
            array_map(fn (Request $request): mixed => self::decoded($request)['type'], $this->fakeWebhookRequests()),
        );
    }

    public function test_a_fail_generation_starts_processing_then_fails_unbilled(): void
    {
        $created = $this->createThroughFake(self::lighthousePayload(prompt: 'A lighthouse [fail]'));

        $this->runFakeLifecycle();

        $events = array_map(fn (Request $request): array => self::decoded($request), $this->fakeWebhookRequests());
        $this->assertSame(['generation.processing', 'generation.failed'], array_column($events, 'type'));

        $failed = $events[1]['data']['object'];
        $this->assertSame('failed', $failed['status']);
        $this->assertSame(['code' => 'generation_failed', 'message' => 'The model could not produce an image for this prompt.'], $failed['error']);
        $this->assertSame('2026-11-19T12:00:04Z', $failed['completed_at']);
        $this->assertNull($failed['usage']);
        $this->assertNull($failed['image_url']);
        $this->assertNull($failed['image_url_expires_at']);
        $this->assertSame($this->fakeGenerationRow($created->snapshot->id)->snapshot, $failed);
    }

    public function test_a_slow_generation_stays_processing_for_the_slow_duration(): void
    {
        $created = $this->createThroughFake(self::lighthousePayload(prompt: 'A lighthouse [slow]'));

        $this->runNextFakeLifecycleJob();

        $jobs = $this->pushedFakeLifecycleJobs();
        $this->assertCount(2, $jobs);
        $this->assertSame(10, self::delaySecondsOf($jobs[1]));
        $this->travel(9)->seconds();
        $this->assertSame(GenerationStatus::Processing, $this->fakeGenerationRow($created->snapshot->id)->status());
        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW)->addSecond());

        $this->runFakeLifecycle();

        $row = $this->fakeGenerationRow($created->snapshot->id);
        $this->assertSame(GenerationStatus::Succeeded, $row->status());
        $this->assertSame('2026-11-19T12:00:11Z', $row->snapshot['completed_at']);
    }

    public function test_a_duplicate_generation_delivers_every_webhook_twice(): void
    {
        $this->createThroughFake(self::lighthousePayload(prompt: 'A lighthouse [duplicate]'));

        $this->runFakeLifecycle();

        $requests = $this->fakeWebhookRequests();
        $this->assertCount(4, $requests);
        $this->assertSame(
            ['generation.processing', 'generation.processing', 'generation.succeeded', 'generation.succeeded'],
            array_map(fn (Request $request): mixed => self::decoded($request)['type'], $requests),
        );

        foreach ([[0, 1], [2, 3]] as [$first, $second]) {
            $this->assertSame($requests[$first]->body(), $requests[$second]->body());
            $this->assertSame($requests[$first]->header('Pixelforge-Event-Id'), $requests[$second]->header('Pixelforge-Event-Id'));
        }

        $this->assertNotSame($requests[0]->header('Pixelforge-Event-Id'), $requests[2]->header('Pixelforge-Event-Id'));
    }

    /**
     * `[expire]` gives this one generation a short TTL; one finished seconds
     * apart keeps the normal day.
     */
    public function test_an_expire_generation_image_dies_within_seconds(): void
    {
        $expiring = $this->createThroughFake(self::lighthousePayload(prompt: 'A lighthouse [expire]'));
        $normal = $this->createThroughFake(self::lighthousePayload());

        $this->runFakeLifecycle();

        $expiringSnapshot = $this->fakeGenerationRow($expiring->snapshot->id)->snapshot;
        $normalSnapshot = $this->fakeGenerationRow($normal->snapshot->id)->snapshot;
        $this->assertIsString($expiringSnapshot['completed_at']);
        $this->assertSame(
            CarbonImmutable::parse($expiringSnapshot['completed_at'])->addSeconds(5)->toIso8601ZuluString(),
            $expiringSnapshot['image_url_expires_at'],
        );

        $this->travelTo(CarbonImmutable::parse($expiringSnapshot['completed_at'])->addSeconds(6));

        $this->assertIsString($expiringSnapshot['image_url']);
        $this->assertIsString($normalSnapshot['image_url']);
        $this->get($expiringSnapshot['image_url'])->assertForbidden();
        $this->get($normalSnapshot['image_url'])->assertOk();
    }

    public function test_fail_combines_with_slow_and_duplicate(): void
    {
        $created = $this->createThroughFake(self::lighthousePayload(prompt: 'A lighthouse [fail][slow][duplicate]'));

        $this->runFakeLifecycle();

        $this->assertSame(10, self::delaySecondsOf($this->pushedFakeLifecycleJobs()[1]));
        $row = $this->fakeGenerationRow($created->snapshot->id);
        $this->assertSame(GenerationStatus::Failed, $row->status());
        $this->assertSame('2026-11-19T12:00:11Z', $row->snapshot['completed_at']);
        $this->assertSame(
            ['generation.processing', 'generation.processing', 'generation.failed', 'generation.failed'],
            array_map(fn (Request $request): mixed => self::decoded($request)['type'], $this->fakeWebhookRequests()),
        );
    }

    public function test_slow_duplicate_and_expire_combine_with_success(): void
    {
        $created = $this->createThroughFake(self::lighthousePayload(prompt: 'A lighthouse [slow][duplicate][expire]'));

        $this->runFakeLifecycle();

        $this->assertSame(10, self::delaySecondsOf($this->pushedFakeLifecycleJobs()[1]));
        $row = $this->fakeGenerationRow($created->snapshot->id);
        $this->assertSame(GenerationStatus::Succeeded, $row->status());
        $this->assertSame('2026-11-19T12:00:11Z', $row->snapshot['completed_at']);
        $this->assertSame('2026-11-19T12:00:16Z', $row->snapshot['image_url_expires_at']);
        $this->assertCount(4, $this->fakeWebhookRequests());
    }

    public function test_a_job_for_a_terminal_or_missing_generation_does_nothing(): void
    {
        $created = $this->createThroughFake(self::lighthousePayload());
        $this->runFakeLifecycle();
        $stored = $this->fakeGenerationRow($created->snapshot->id)->snapshot;

        $this->app->call([new AdvanceFakeGenerationJob($created->snapshot->id), 'handle']);
        $this->app->call([new AdvanceFakeGenerationJob('gen_01KAF000000000000000000000'), 'handle']);

        $this->assertSame($stored, $this->fakeGenerationRow($created->snapshot->id)->snapshot);
        $this->assertCount(2, $this->fakeWebhookRequests());
        $this->assertCount(2, $this->pushedFakeLifecycleJobs());
    }

    /**
     * @return array<string, mixed>
     */
    private static function decoded(Request $request): array
    {
        $body = json_decode($request->body(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }
}
