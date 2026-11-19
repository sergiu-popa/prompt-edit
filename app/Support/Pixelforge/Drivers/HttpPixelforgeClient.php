<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Drivers;

use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\DataTransferObjects\CreatedGenerationData;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use App\Support\Pixelforge\Exceptions\AmbiguousPixelforgeOutcomeException;
use App\Support\Pixelforge\Exceptions\InvalidGenerationSnapshotException;
use App\Support\Pixelforge\Exceptions\PixelforgeApiException;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * The real Pixelforge API over HTTP. It never retries and never logs: retries
 * belong to the caller, with the same key and the same bytes. A redirect is
 * never followed, so the key and the body only ever reach the configured URL.
 */
final readonly class HttpPixelforgeClient implements PixelforgeClient
{
    /**
     * The `pixelforge.driver` value that selects this client.
     */
    public const string DRIVER = 'http';

    public const string REPLAYED_HEADER = 'Idempotent-Replayed';

    public function __construct(
        #[Config('pixelforge.base_url')]
        private string $baseUrl,
        #[Config('pixelforge.api_key')]
        private ?string $apiKey,
        #[Config('pixelforge.connect_timeout_seconds')]
        private int $connectTimeoutSeconds,
        #[Config('pixelforge.request_timeout_seconds')]
        private int $requestTimeoutSeconds,
    ) {}

    public function createGeneration(CreateGenerationPayloadData $payload): CreatedGenerationData
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw PixelforgeNotConfiguredException::missingApiKey();
        }

        $response = Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->withHeaders(['Idempotency-Key' => $payload->idempotencyKey()])
            ->connectTimeout($this->connectTimeoutSeconds)
            ->timeout($this->requestTimeoutSeconds)
            ->withoutRedirecting()
            ->withBody($payload->toJson(), 'application/json')
            ->post('generations');

        if (! $response->successful()) {
            throw PixelforgeApiException::tryFromResponse(
                $response->status(),
                $response->body(),
                $response->hasHeader('Retry-After') ? $response->header('Retry-After') : null,
            ) ?? AmbiguousPixelforgeOutcomeException::undocumentedResponse($response->status());
        }

        return new CreatedGenerationData(
            $this->generationFrom($response),
            strcasecmp($response->header(self::REPLAYED_HEADER), 'true') === 0,
        );
    }

    /**
     * @throws AmbiguousPixelforgeOutcomeException
     */
    private function generationFrom(Response $response): GenerationSnapshot
    {
        try {
            $generation = json_decode($response->body(), associative: true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($generation)) {
                throw AmbiguousPixelforgeOutcomeException::unreadableGeneration($response->status());
            }

            return GenerationSnapshot::from($generation);
        } catch (JsonException|InvalidGenerationSnapshotException $exception) {
            throw AmbiguousPixelforgeOutcomeException::unreadableGeneration($response->status(), $exception);
        }
    }
}
