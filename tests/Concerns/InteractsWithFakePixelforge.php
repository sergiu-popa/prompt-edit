<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Support\Pixelforge\DataTransferObjects\CreatedGenerationData;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\Drivers\FakePixelforgeClient;
use App\Support\Pixelforge\Fake\AdvanceFakeGenerationJob;
use App\Support\Pixelforge\Fake\FakePixelforgeGeneration;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The in-app fake Pixelforge as tests drive it: selected, with a known test
 * secret that is no real `whsec_…`, and the docs' Lighthouse as its payload.
 *
 * @phpstan-require-extends TestCase
 */
trait InteractsWithFakePixelforge
{
    use InteractsWithGenerations;

    protected const string FAKE_WEBHOOK_SECRET = 'fake-test-secret-3b9d0c7e5a1f4e2d8c6b0a9e7d5c3b1f';

    /**
     * The default in-network webhook URL, where the fake's webhooks are captured.
     */
    protected const string FAKE_WEBHOOK_URL = 'http://app:8080/webhooks/pixelforge';

    /**
     * @var ?Closure(Request): PromiseInterface
     */
    private ?Closure $fakeWebhookResponder = null;

    private int $fakeLifecycleJobsRun = 0;

    /**
     * Called by Laravel's test lifecycle for every test that uses the trait.
     * Webhooks are answered 200 unless a test swaps the responder.
     */
    protected function setUpInteractsWithFakePixelforge(): void
    {
        config([
            'pixelforge.driver' => FakePixelforgeClient::DRIVER,
            'pixelforge.webhook_secret' => self::FAKE_WEBHOOK_SECRET,
        ]);

        Http::fake([
            self::FAKE_WEBHOOK_URL => fn (Request $request): PromiseInterface => $this->fakeWebhookResponder === null
                ? Http::response()
                : ($this->fakeWebhookResponder)($request),
        ]);
    }

    /**
     * Swaps how the endpoint answers the fake's webhooks: a 500, a
     * `Http::failedConnection()`, or one that travels in time. The responder
     * is swapped rather than faked again, because the first matching stub wins.
     *
     * @param  Closure(Request): PromiseInterface  $responder
     */
    protected function answerFakeWebhooksWith(Closure $responder): void
    {
        $this->fakeWebhookResponder = $responder;
    }

    /**
     * Every webhook the fake posted to the default URL, in the order it was sent.
     *
     * @return list<Request>
     */
    protected function fakeWebhookRequests(): array
    {
        return array_values(Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === self::FAKE_WEBHOOK_URL)
            ->all());
    }

    /**
     * The docs' Lighthouse as the submit job sends it, under a fresh ULID
     * unless one is given.
     */
    protected static function lighthousePayload(?string $generationId = null, ?string $prompt = null): CreateGenerationPayloadData
    {
        return new CreateGenerationPayloadData(
            $generationId ?? strtolower((string) Str::ulid()),
            $prompt ?? self::LIGHTHOUSE_PROMPT,
            ImageSize::Landscape,
            ImageQuality::High,
            ['https://example.com/style.png'],
        );
    }

    /**
     * One create through a freshly resolved fake, as the submit job calls it.
     * The fake's lifecycle job is held back, because the test's `sync` queue
     * would otherwise run the whole lifecycle inside the create;
     * runFakeLifecycle() then plays it out step by step.
     */
    protected function createThroughFake(CreateGenerationPayloadData $payload): CreatedGenerationData
    {
        if (! Queue::getFacadeRoot() instanceof QueueFake) {
            Queue::fake([AdvanceFakeGenerationJob::class]);
        }

        return $this->app->make(FakePixelforgeClient::class)->createGeneration($payload);
    }

    /**
     * Runs every held-back lifecycle job, including the ones they push, in
     * push order until none is left.
     */
    protected function runFakeLifecycle(): void
    {
        while ($this->runNextFakeLifecycleJob()) {
            //
        }
    }

    /**
     * Travels by the next held-back job's delay, as the worker waits, then
     * runs it through the container.
     *
     * @return bool false when no job was left
     */
    protected function runNextFakeLifecycleJob(): bool
    {
        $job = $this->pushedFakeLifecycleJobs()[$this->fakeLifecycleJobsRun] ?? null;

        if ($job === null) {
            return false;
        }

        $this->fakeLifecycleJobsRun++;
        $this->travel(self::delaySecondsOf($job))->seconds();
        $this->app->call([$job, 'handle']);

        return true;
    }

    /**
     * The held-back lifecycle jobs, in push order.
     *
     * @return list<AdvanceFakeGenerationJob>
     */
    protected function pushedFakeLifecycleJobs(): array
    {
        $queue = Queue::getFacadeRoot();
        $this->assertInstanceOf(QueueFake::class, $queue, 'createThroughFake() holds the lifecycle jobs back.');

        return array_values(array_filter(
            $queue->pushed(AdvanceFakeGenerationJob::class)->all(),
            fn (mixed $job): bool => $job instanceof AdvanceFakeGenerationJob,
        ));
    }

    protected static function delaySecondsOf(AdvanceFakeGenerationJob $job): int
    {
        self::assertIsInt($job->delay, 'The fake delays its jobs by whole seconds.');

        return $job->delay;
    }

    /**
     * Posts every captured webhook to our real endpoint, byte for byte and
     * with exactly its captured headers, in the order the fake sent them.
     *
     * @return list<TestResponse>
     */
    protected function replayFakeWebhooks(): array
    {
        return array_map(function (Request $request): TestResponse {
            $headers = array_map(fn (array $values): string => implode(', ', $values), $request->headers());

            return $this->call(
                'POST',
                (string) parse_url($request->url(), PHP_URL_PATH),
                server: $this->transformHeadersToServerVars($headers),
                content: $request->body(),
            );
        }, $this->fakeWebhookRequests());
    }

    protected function fakeGenerationRow(string $providerId): FakePixelforgeGeneration
    {
        return FakePixelforgeGeneration::query()->findOrFail($providerId);
    }
}
