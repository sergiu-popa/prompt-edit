<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Drivers;

use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\DataTransferObjects\CreatedGenerationData;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use App\Support\Pixelforge\DataTransferObjects\UsageReportData;
use App\Support\Pixelforge\Exceptions\AmbiguousPixelforgeOutcomeException;
use App\Support\Pixelforge\Exceptions\InvalidGenerationSnapshotException;
use App\Support\Pixelforge\Exceptions\InvalidUsageReportException;
use App\Support\Pixelforge\Exceptions\PixelforgeApiException;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use App\Support\Pixelforge\Exceptions\RateLimitExceededException;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use SensitiveParameter;

/**
 * The real Pixelforge API over HTTP. It never retries and never logs: retries
 * belong to the caller, with the same key and the same bytes. A redirect is
 * never followed, so the key and the body only ever reach the configured URL.
 * The key is never a method argument on the request path, and a trace of
 * the constructor redacts it. No `Http::` exception leaves it: one that
 * arrives without a complete response is ambiguous.
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
        #[Config('pixelforge.api_key'), SensitiveParameter]
        private ?string $apiKey,
        #[Config('pixelforge.connect_timeout_seconds')]
        private int $connectTimeoutSeconds,
        #[Config('pixelforge.request_timeout_seconds')]
        private int $requestTimeoutSeconds,
        #[Config('pixelforge.usage.timeout_seconds')]
        private int $usageTimeoutSeconds,
    ) {}

    public function createGeneration(CreateGenerationPayloadData $payload): CreatedGenerationData
    {
        if (! $this->isConfigured()) {
            throw PixelforgeNotConfiguredException::missingApiKey();
        }

        try {
            $response = $this->request()
                ->withHeaders(['Idempotency-Key' => $payload->idempotencyKey()])
                ->withBody($payload->toJson(), 'application/json')
                ->post('generations');
        } catch (ConnectionException|RequestException $exception) {
            throw AmbiguousPixelforgeOutcomeException::transportFailed($exception);
        }

        if (! $response->successful()) {
            throw $this->errorFrom($response);
        }

        return new CreatedGenerationData(
            $this->generationFrom($response),
            strcasecmp($response->header(self::REPLAYED_HEADER), 'true') === 0,
        );
    }

    /**
     * The id is one encoded path segment, so it can never reach another endpoint.
     * A documented 404 is a `NotFoundException`.
     */
    public function retrieveGeneration(string $providerId): GenerationSnapshot
    {
        if (! $this->isConfigured()) {
            throw PixelforgeNotConfiguredException::missingApiKey();
        }

        try {
            $response = $this->request()->get('generations/' . rawurlencode($providerId));
        } catch (ConnectionException|RequestException $exception) {
            throw AmbiguousPixelforgeOutcomeException::transportFailed($exception);
        }

        if (! $response->successful()) {
            throw $this->errorFrom($response);
        }

        return $this->generationFrom($response);
    }

    /**
     * A page waits on it, so it has its own, shorter request timeout (D-74). The answer must
     * be for the very range asked.
     */
    public function retrieveUsage(string $startDate, string $endDate): UsageReportData
    {
        if (! $this->isConfigured()) {
            throw PixelforgeNotConfiguredException::missingApiKey();
        }

        try {
            $response = $this->request()
                ->timeout($this->usageTimeoutSeconds)
                ->get('usage', ['start_date' => $startDate, 'end_date' => $endDate]);
        } catch (ConnectionException|RequestException $exception) {
            throw AmbiguousPixelforgeOutcomeException::transportFailed($exception);
        }

        if (! $response->successful()) {
            throw $this->errorFrom($response);
        }

        return $this->usageFrom($response, $startDate, $endDate);
    }

    /**
     * Configured once it has a key to send.
     *
     * @phpstan-assert-if-true non-empty-string $this->apiKey
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    /**
     * What every call shares: the base URL, the key, JSON, the timeouts and no
     * redirects. Callers check isConfigured() first.
     */
    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->connectTimeout($this->connectTimeoutSeconds)
            ->timeout($this->requestTimeoutSeconds)
            ->withoutRedirecting();
    }

    /**
     * The typed error of a documented envelope. Any other 429 is a rate limit, since
     * a 429 creates nothing; any other status is ambiguous (D-31, narrowed by D-47).
     */
    private function errorFrom(Response $response): PixelforgeApiException|AmbiguousPixelforgeOutcomeException
    {
        $retryAfterHeader = $response->hasHeader('Retry-After') ? $response->header('Retry-After') : null;

        return PixelforgeApiException::tryFromResponse($response->status(), $response->body(), $retryAfterHeader)
            ?? ($response->status() === 429
                ? RateLimitExceededException::undocumented(PixelforgeApiException::retryAfterSecondsFrom($retryAfterHeader))
                : AmbiguousPixelforgeOutcomeException::undocumentedResponse($response->status()));
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

    /**
     * @throws AmbiguousPixelforgeOutcomeException
     */
    private function usageFrom(Response $response, string $startDate, string $endDate): UsageReportData
    {
        try {
            $report = json_decode($response->body(), associative: true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($report)) {
                throw AmbiguousPixelforgeOutcomeException::unreadableUsage($response->status());
            }

            $usage = UsageReportData::from($report);

            if ($usage->startDate !== $startDate || $usage->endDate !== $endDate) {
                throw InvalidUsageReportException::invalidField('range', "{$startDate} to {$endDate}", "{$usage->startDate} to {$usage->endDate}");
            }

            return $usage;
        } catch (JsonException|InvalidUsageReportException $exception) {
            throw AmbiguousPixelforgeOutcomeException::unreadableUsage($response->status(), $exception);
        }
    }
}
