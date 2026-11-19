<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Support\Pixelforge\DataTransferObjects\CreatedGenerationData;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\Drivers\FakePixelforgeClient;
use App\Support\Pixelforge\Fake\FakePixelforgeGeneration;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
     */
    protected function createThroughFake(CreateGenerationPayloadData $payload): CreatedGenerationData
    {
        return $this->app->make(FakePixelforgeClient::class)->createGeneration($payload);
    }

    protected function fakeGenerationRow(string $providerId): FakePixelforgeGeneration
    {
        return FakePixelforgeGeneration::query()->findOrFail($providerId);
    }
}
