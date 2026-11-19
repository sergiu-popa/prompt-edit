<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Drivers;

use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\DataTransferObjects\CreatedGenerationData;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use App\Support\Pixelforge\Fake\FakeGenerationOutcome;
use App\Support\Pixelforge\Fake\FakePixelforgeGeneration;
use App\Support\Pixelforge\Fake\FakeUsageGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * An in-app Pixelforge for local dev and E2E (D-12): deterministic, free and
 * reachable without a public URL. Its state lives in
 * `fake_pixelforge_generations`, so the web, worker and E2E processes share
 * it. Every snapshot it returns goes through the real parser.
 */
final readonly class FakePixelforgeClient implements PixelforgeClient
{
    /**
     * The `pixelforge.driver` value that selects this client.
     */
    public const string DRIVER = 'fake';

    /**
     * @param  array<string, string>  $rates  the pinned USD-per-1M rate strings
     */
    public function __construct(
        private FakeUsageGenerator $usageGenerator,
        #[Config('pixelforge.webhook_secret'), SensitiveParameter]
        private ?string $webhookSecret,
        #[Config('pricing.rates')]
        private array $rates,
        #[Config('pixelforge.fake.processing_seconds')]
        private int $processingSeconds,
        #[Config('pixelforge.fake.image_ttl_seconds')]
        private int $imageTtlSeconds,
    ) {}

    /**
     * @throws PixelforgeNotConfiguredException when there is no secret to sign webhooks with
     */
    public function createGeneration(CreateGenerationPayloadData $payload): CreatedGenerationData
    {
        if ($this->webhookSecret === null || $this->webhookSecret === '') {
            throw PixelforgeNotConfiguredException::missingWebhookSecret();
        }

        $id = 'gen_' . Str::ulid();

        $generation = FakePixelforgeGeneration::query()->create([
            'id' => $id,
            'idempotency_key' => $payload->idempotencyKey(),
            'request_hash' => hash('sha256', $payload->toJson()),
            'snapshot' => $this->creationSnapshot($id, $payload),
            'usage' => $this->usageGenerator->generate($payload),
            'outcome' => FakeGenerationOutcome::Succeeded,
            'processing_seconds' => $this->processingSeconds,
            'image_ttl_seconds' => $this->imageTtlSeconds,
            'duplicates_webhooks' => false,
        ]);

        return new CreatedGenerationData(GenerationSnapshot::from($generation->snapshot), false);
    }

    /**
     * The generation object right after create, in the docs' field order.
     *
     * @return array<string, mixed>
     */
    private function creationSnapshot(string $id, CreateGenerationPayloadData $payload): array
    {
        return [
            'id' => $id,
            'object' => 'generation',
            'status' => 'queued',
            'prompt' => $payload->prompt,
            'size' => $payload->size->value,
            'quality' => $payload->quality->value,
            'reference_images' => $payload->referenceImages,
            'metadata' => ['generation_id' => $payload->generationId],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => $this->rates,
            'error' => null,
            'created_at' => CarbonImmutable::now()->toIso8601ZuluString(),
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
