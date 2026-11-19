<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Support\Pixelforge\DataTransferObjects\CreatedGenerationData;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\Drivers\FakePixelforgeClient;
use App\Support\Pixelforge\Fake\FakePixelforgeGeneration;
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
     * Called by Laravel's test lifecycle for every test that uses the trait.
     */
    protected function setUpInteractsWithFakePixelforge(): void
    {
        config([
            'pixelforge.driver' => FakePixelforgeClient::DRIVER,
            'pixelforge.webhook_secret' => self::FAKE_WEBHOOK_SECRET,
        ]);
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
