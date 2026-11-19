<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Drivers;

use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\DataTransferObjects\CreatedGenerationData;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use App\Support\Pixelforge\Exceptions\ContentPolicyViolationException;
use App\Support\Pixelforge\Exceptions\IdempotencyKeyReusedException;
use App\Support\Pixelforge\Exceptions\OverloadedException;
use App\Support\Pixelforge\Exceptions\PixelforgeApiException;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use App\Support\Pixelforge\Fake\AdvanceFakeGenerationJob;
use App\Support\Pixelforge\Fake\FakeChaos;
use App\Support\Pixelforge\Fake\FakeGenerationOutcome;
use App\Support\Pixelforge\Fake\FakeKeywords;
use App\Support\Pixelforge\Fake\FakePixelforgeGeneration;
use App\Support\Pixelforge\Fake\FakeUsageGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Str;
use LogicException;
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
     * The `Retry-After` the docs give a `[overloaded]` refusal.
     */
    private const int OVERLOADED_RETRY_AFTER_SECONDS = 2;

    /**
     * @param  array<string, string>  $rates  the pinned USD-per-1M rate strings
     */
    public function __construct(
        private FakeUsageGenerator $usageGenerator,
        private FakeChaos $chaos,
        #[Config('pixelforge.webhook_secret'), SensitiveParameter]
        private ?string $webhookSecret,
        #[Config('pricing.rates')]
        private array $rates,
        #[Config('pixelforge.fake.queue_seconds')]
        private int $queueSeconds,
        #[Config('pixelforge.fake.processing_seconds')]
        private int $processingSeconds,
        #[Config('pixelforge.fake.slow_processing_seconds')]
        private int $slowProcessingSeconds,
        #[Config('pixelforge.fake.image_ttl_seconds')]
        private int $imageTtlSeconds,
        #[Config('pixelforge.fake.expire_ttl_seconds')]
        private int $expireTtlSeconds,
    ) {}

    /**
     * A new generation starts its lifecycle, delayed by the queue time. A key
     * it has seen with the same body replays the generation as it is now,
     * and starts nothing. The same key with another body is refused,
     * as Pixelforge refuses it.
     *
     * @throws PixelforgeNotConfiguredException when there is no secret to sign webhooks with
     * @throws ContentPolicyViolationException for `[blocked]`
     * @throws OverloadedException for `[overloaded]` or a chaos 503
     * @throws IdempotencyKeyReusedException
     */
    public function createGeneration(CreateGenerationPayloadData $payload): CreatedGenerationData
    {
        if ($this->webhookSecret === null || $this->webhookSecret === '') {
            throw PixelforgeNotConfiguredException::missingWebhookSecret();
        }

        $keywords = FakeKeywords::fromPrompt($payload->prompt);

        // A rejected request stores no key, so its retry is rejected the same way.
        $rejection = $keywords->rejection();

        if ($rejection !== null) {
            throw $this->refusal($rejection);
        }

        if ($this->chaos->overloadsCreate()) {
            throw $this->refusal(PixelforgeErrorCode::Overloaded);
        }

        $id = 'gen_' . Str::ulid();
        $requestHash = hash('sha256', $payload->toJson());

        $generation = FakePixelforgeGeneration::query()->firstOrCreate(['idempotency_key' => $payload->idempotencyKey()], [
            'id' => $id,
            'request_hash' => $requestHash,
            'snapshot' => $this->creationSnapshot($id, $payload),
            'usage' => $this->usageGenerator->generate($payload),
            'outcome' => $keywords->outcome() ?? ($this->chaos->failsGeneration() ? FakeGenerationOutcome::InternalError : FakeGenerationOutcome::Succeeded),
            'processing_seconds' => $keywords->slow ? $this->slowProcessingSeconds : $this->processingSeconds,
            'image_ttl_seconds' => $keywords->expire ? $this->expireTtlSeconds : $this->imageTtlSeconds,
            'duplicates_webhooks' => $keywords->duplicate || $this->chaos->duplicatesWebhooks(),
        ]);

        if ($generation->wasRecentlyCreated) {
            // Taken first: the `sync` queue runs the whole lifecycle inside the dispatch.
            $created = GenerationSnapshot::from($generation->snapshot);
            AdvanceFakeGenerationJob::dispatch($generation->id)->delay($this->queueSeconds);

            return new CreatedGenerationData($created, false);
        }

        if (! hash_equals($generation->request_hash, $requestHash)) {
            throw new IdempotencyKeyReusedException(
                'idempotency_error',
                'This idempotency key was already used with different parameters.',
            );
        }

        return new CreatedGenerationData(GenerationSnapshot::from($generation->snapshot), true);
    }

    /**
     * The docs-shaped error for a refused create.
     */
    private function refusal(PixelforgeErrorCode $code): PixelforgeApiException
    {
        return match ($code) {
            PixelforgeErrorCode::ContentPolicyViolation => new ContentPolicyViolationException(
                'invalid_request_error',
                'The prompt was rejected by the content policy.',
                param: 'prompt',
            ),
            PixelforgeErrorCode::Overloaded => new OverloadedException(
                'api_error',
                'Pixelforge is temporarily overloaded. Please retry with the same idempotency key.',
                retryAfterSeconds: self::OVERLOADED_RETRY_AFTER_SECONDS,
            ),
            default => throw new LogicException(sprintf('The fake never refuses a create with %s.', $code->value)),
        };
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
