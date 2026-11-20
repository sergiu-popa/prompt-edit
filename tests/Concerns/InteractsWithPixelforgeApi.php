<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Generation\Models\Generation;
use App\Jobs\SubmitGenerationJob;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use Closure;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Pixelforge API as tests see it: a sentinel key that is no real key, the
 * docs' base URL and answers shaped as the docs show them. Every request is
 * faked; Tests\TestCase refuses any other.
 *
 * @phpstan-require-extends TestCase
 */
trait InteractsWithPixelforgeApi
{
    /**
     * Long enough that a trace's 15-character string preview never shows it whole.
     */
    protected const string PIXELFORGE_API_KEY = 'pf_test_sentinel_4c7e1a9b3d5f2e8a6c0b4d7f9e1a3c5b';

    protected const string PIXELFORGE_BASE_URL = 'https://pixelforge.promptedit.dev/v1';

    /**
     * The docs' Lighthouse prompt, a space and four emoji: 50 code points.
     */
    protected const string UNICODE_PROMPT = 'A lighthouse on a cliff at dawn, oil painting 🎨🎨🎨🎨';

    /**
     * Called by Laravel's test lifecycle for every test that uses the trait.
     */
    protected function setUpInteractsWithPixelforgeApi(): void
    {
        config([
            'pixelforge.base_url' => self::PIXELFORGE_BASE_URL,
            'pixelforge.api_key' => self::PIXELFORGE_API_KEY,
            'pixelforge.connect_timeout_seconds' => 5,
            'pixelforge.request_timeout_seconds' => 30,
        ]);
    }

    /**
     * A `202` carrying the generation object, with `Idempotent-Replayed` when given.
     *
     * @param  array<string, mixed>  $object
     */
    protected static function pixelforgeAccepted(array $object, ?string $replayed = null): PromiseInterface
    {
        return Http::response(
            json_encode($object, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            202,
            array_filter(['Content-Type' => 'application/json', 'Idempotent-Replayed' => $replayed], fn (?string $value): bool => $value !== null),
        );
    }

    /**
     * A `200` carrying the generation object, as a retrieve answers.
     *
     * @param  array<string, mixed>  $object
     */
    protected static function pixelforgeRetrieved(array $object): PromiseInterface
    {
        return Http::response(json_encode($object, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 200, ['Content-Type' => 'application/json']);
    }

    /**
     * The docs-shaped error envelope for the code, at the code's status, with
     * the docs' `type` for it. The error's fields replace the defaults.
     *
     * @param  array<string, mixed>  $error
     * @param  array<string, string>  $headers
     */
    protected static function pixelforgeErrorFor(PixelforgeErrorCode $code, array $error = [], array $headers = []): PromiseInterface
    {
        return Http::response(
            json_encode(['error' => [
                'type' => self::pixelforgeErrorType($code),
                'code' => $code->value,
                'message' => sprintf('Pixelforge says %s.', str_replace('_', ' ', $code->value)),
                ...$error,
            ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $code->httpStatus(),
            ['Content-Type' => 'application/json', ...$headers],
        );
    }

    /**
     * An answer whose status line arrived but whose body did not, as cURL
     * reports a timeout or a reset while the body is read.
     *
     * @param  class-string<ResponseTransferException>  $guzzleException
     */
    protected static function pixelforgeCutOff(string $guzzleException, int $status, string $truncatedBody): Closure
    {
        return function (Request $request) use ($guzzleException, $status, $truncatedBody): never {
            throw new $guzzleException(
                'cURL error 28: Operation timed out after 30001 milliseconds with 41 out of 120 bytes received',
                $request->toPsrRequest(),
                new Psr7Response($status, ['Content-Type' => 'application/json'], $truncatedBody),
            );
        };
    }

    /**
     * The `type` the docs give each code.
     */
    protected static function pixelforgeErrorType(PixelforgeErrorCode $code): string
    {
        return match ($code) {
            PixelforgeErrorCode::InvalidApiKey => 'authentication_error',
            PixelforgeErrorCode::ContentPolicyViolation, PixelforgeErrorCode::NotFound, PixelforgeErrorCode::ValidationFailed => 'invalid_request_error',
            PixelforgeErrorCode::IdempotencyKeyReused => 'idempotency_error',
            PixelforgeErrorCode::RateLimitExceeded, PixelforgeErrorCode::ConcurrencyLimitExceeded, PixelforgeErrorCode::DailyLimitExceeded => 'rate_limit_error',
            PixelforgeErrorCode::Overloaded => 'api_error',
        };
    }

    /**
     * One attempt of the submit job, resolved through the container as the
     * worker runs it, with its queue interactions faked so a test can assert
     * the release. Whatever the attempt throws is rethrown.
     */
    protected function runSubmitJob(Generation $generation): SubmitGenerationJob
    {
        $job = (new SubmitGenerationJob($generation->id))->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);

        return $job;
    }

    /**
     * The request's JSON body as decoded, so an assertion never depends on how
     * `/` or non-ASCII characters were escaped.
     *
     * @return array<string, mixed>
     */
    protected static function decodedRequestBody(Request $request): array
    {
        $body = json_decode($request->body(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * Every recorded `POST …/generations`, in the order it was sent.
     *
     * @return list<Request>
     */
    protected function recordedCreateRequests(): array
    {
        return array_values(Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/generations'))
            ->all());
    }

    /**
     * Every recorded `GET …/generations/{id}`, in the order it was sent.
     *
     * @return list<Request>
     */
    protected function recordedRetrieveRequests(): array
    {
        return array_values(Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), '/generations/'))
            ->all());
    }
}
