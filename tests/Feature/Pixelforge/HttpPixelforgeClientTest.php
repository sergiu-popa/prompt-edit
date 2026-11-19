<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\Drivers\HttpPixelforgeClient;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use App\Support\Pixelforge\Exceptions\AmbiguousPixelforgeOutcomeException;
use App\Support\Pixelforge\Exceptions\ConcurrencyLimitExceededException;
use App\Support\Pixelforge\Exceptions\ContentPolicyViolationException;
use App\Support\Pixelforge\Exceptions\DailyLimitExceededException;
use App\Support\Pixelforge\Exceptions\IdempotencyKeyReusedException;
use App\Support\Pixelforge\Exceptions\InvalidApiKeyException;
use App\Support\Pixelforge\Exceptions\InvalidGenerationSnapshotException;
use App\Support\Pixelforge\Exceptions\NotFoundException;
use App\Support\Pixelforge\Exceptions\OverloadedException;
use App\Support\Pixelforge\Exceptions\PixelforgeApiException;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use App\Support\Pixelforge\Exceptions\RateLimitExceededException;
use App\Support\Pixelforge\Exceptions\ValidationFailedException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;

/**
 * The HTTP driver against `Http::fake`: what it sends, and how every answer
 * becomes a created generation, a typed error or a single ambiguous error.
 */
final class HttpPixelforgeClientTest extends TestCase
{
    use InteractsWithGenerationSnapshots;
    use InteractsWithPixelforgeApi;

    private const string GENERATION_ID = '01JC2Z8Q4N6V0M3H7X5T9R2B1K';

    private const string LIGHTHOUSE_PROMPT = 'A lighthouse on a cliff at dawn, oil painting';

    private const string CREATE_URL = 'https://pixelforge.promptedit.dev/v1/generations';

    public function test_the_http_driver_resolves_the_http_client_afresh_each_time(): void
    {
        config(['pixelforge.driver' => 'http']);

        $first = $this->app->make(PixelforgeClient::class);
        $second = $this->app->make(PixelforgeClient::class);

        $this->assertInstanceOf(HttpPixelforgeClient::class, $first);
        $this->assertInstanceOf(HttpPixelforgeClient::class, $second);
        $this->assertNotSame($first, $second);
    }

    public function test_an_unknown_driver_fails_naming_it(): void
    {
        config(['pixelforge.driver' => 'htp']);

        try {
            $this->app->make(PixelforgeClient::class);
            $this->fail('Expected a not-configured error.');
        } catch (PixelforgeNotConfiguredException $exception) {
            $this->assertStringContainsString('"htp"', $exception->getMessage());
            $this->assertStringContainsString('PIXELFORGE_DRIVER', $exception->getMessage());
        }
    }

    public function test_a_create_is_posted_to_the_generations_endpoint(): void
    {
        Http::fake(['*' => self::pixelforgeAccepted(self::queuedObject())]);

        $this->client()->createGeneration(self::lighthousePayload());

        $requests = $this->recordedCreateRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]->method());
        $this->assertSame(self::CREATE_URL, $requests[0]->url());
    }

    public function test_a_configured_base_url_is_used_as_given(): void
    {
        config(['pixelforge.base_url' => 'https://pixelforge.test/v2']);
        Http::fake(['*' => self::pixelforgeAccepted(self::queuedObject())]);

        $this->client()->createGeneration(self::lighthousePayload());

        $this->assertSame('https://pixelforge.test/v2/generations', $this->recordedCreateRequests()[0]->url());
    }

    public function test_it_sends_exactly_the_documented_headers(): void
    {
        Http::fake(['*' => self::pixelforgeAccepted(self::queuedObject())]);

        $this->client()->createGeneration(self::lighthousePayload());

        $request = $this->recordedCreateRequests()[0];
        $this->assertSame(['Bearer ' . self::PIXELFORGE_API_KEY], $request->header('Authorization'));
        $this->assertSame([self::GENERATION_ID], $request->header('Idempotency-Key'));
        $this->assertSame(['application/json'], $request->header('Content-Type'));
        $this->assertSame(['application/json'], $request->header('Accept'));
        $this->assertSame(
            ['accept', 'authorization', 'content-type', 'idempotency-key'],
            self::applicationHeaderNames($request),
        );
    }

    public function test_it_sets_the_configured_timeouts(): void
    {
        $options = [];
        Http::fake(function (Request $request, array $requestOptions) use (&$options): PromiseInterface {
            $options = $requestOptions;

            return self::pixelforgeAccepted(self::queuedObject());
        });

        $this->client()->createGeneration(self::lighthousePayload());

        $this->assertSame(5, $options['connect_timeout'] ?? null);
        $this->assertSame(30, $options['timeout'] ?? null);
    }

    public function test_a_redirect_is_never_followed(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://elsewhere.example/generations'])]);

        $exception = $this->createExpectingAmbiguous(self::lighthousePayload());

        $this->assertSame(302, $exception->status);
        Http::assertSentCount(1);
    }

    public function test_the_lighthouse_body_decodes_to_exactly_the_documented_fields(): void
    {
        Http::fake(['*' => self::pixelforgeAccepted(self::queuedObject())]);

        $this->client()->createGeneration(self::lighthousePayload());

        $this->assertSame([
            'prompt' => self::LIGHTHOUSE_PROMPT,
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'metadata' => ['generation_id' => self::GENERATION_ID],
        ], self::decodedBody($this->recordedCreateRequests()[0]));
    }

    public function test_no_reference_images_sends_no_reference_key(): void
    {
        Http::fake(['*' => self::pixelforgeAccepted(self::queuedObject())]);

        $this->client()->createGeneration(new CreateGenerationPayloadData(self::GENERATION_ID, self::LIGHTHOUSE_PROMPT, ImageSize::Square, ImageQuality::Medium, []));

        $this->assertSame(['prompt', 'size', 'quality', 'metadata'], array_keys(self::decodedBody($this->recordedCreateRequests()[0])));
    }

    public function test_a_unicode_prompt_is_sent_unchanged(): void
    {
        Http::fake(['*' => self::pixelforgeAccepted(self::queuedObject())]);

        $this->client()->createGeneration(new CreateGenerationPayloadData(self::GENERATION_ID, self::UNICODE_PROMPT, ImageSize::Landscape, ImageQuality::High, []));

        $this->assertSame(self::UNICODE_PROMPT, self::decodedBody($this->recordedCreateRequests()[0])['prompt']);
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('replayedHeaders')]
    public function test_a_202_returns_the_snapshot_and_whether_it_was_replayed(array $headers, bool $replayed): void
    {
        Http::fake(['*' => Http::response(json_encode(self::queuedObject(), JSON_THROW_ON_ERROR), 202, $headers)]);

        $created = $this->client()->createGeneration(self::lighthousePayload());

        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $created->snapshot->id);
        $this->assertSame(GenerationStatus::Queued, $created->snapshot->status);
        $this->assertSame(self::GENERATION_ID, $created->snapshot->generationId);
        $this->assertSame($replayed, $created->replayed);
    }

    /**
     * @return array<string, array{array<string, string>, bool}>
     */
    public static function replayedHeaders(): array
    {
        return [
            'no header' => [[], false],
            'true' => [['Idempotent-Replayed' => 'true'], true],
            'True, any case' => [['Idempotent-Replayed' => 'True'], true],
            'false' => [['Idempotent-Replayed' => 'false'], false],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  class-string<PixelforgeApiException>  $class
     */
    #[DataProvider('documentedErrors')]
    public function test_each_documented_error_envelope_becomes_its_typed_error(PixelforgeErrorCode $code, array $headers, string $class, ?int $retryAfterSeconds): void
    {
        Http::fake(['*' => self::pixelforgeErrorFor($code, headers: $headers)]);

        try {
            $this->client()->createGeneration(self::lighthousePayload());
            $this->fail('Expected a typed Pixelforge error.');
        } catch (PixelforgeApiException $exception) {
            $this->assertInstanceOf($class, $exception);
            $this->assertSame($code, $exception->errorCode());
            $this->assertSame(self::pixelforgeErrorType($code), $exception->type);
            $this->assertSame($code->httpStatus(), $exception->status());
            $this->assertSame(sprintf('Pixelforge says %s.', str_replace('_', ' ', $code->value)), $exception->getMessage());
            $this->assertSame($retryAfterSeconds, $exception->retryAfterSeconds);
        }
    }

    /**
     * @return array<string, array{PixelforgeErrorCode, array<string, string>, class-string<PixelforgeApiException>, ?int}>
     */
    public static function documentedErrors(): array
    {
        return [
            '400 content policy violation' => [PixelforgeErrorCode::ContentPolicyViolation, [], ContentPolicyViolationException::class, null],
            '401 invalid api key' => [PixelforgeErrorCode::InvalidApiKey, [], InvalidApiKeyException::class, null],
            '404 not found' => [PixelforgeErrorCode::NotFound, [], NotFoundException::class, null],
            '409 idempotency key reused' => [PixelforgeErrorCode::IdempotencyKeyReused, [], IdempotencyKeyReusedException::class, null],
            '422 validation failed' => [PixelforgeErrorCode::ValidationFailed, [], ValidationFailedException::class, null],
            '429 rate limit exceeded, Retry-After 17' => [PixelforgeErrorCode::RateLimitExceeded, ['Retry-After' => '17'], RateLimitExceededException::class, 17],
            '429 concurrency limit exceeded, Retry-After 5' => [PixelforgeErrorCode::ConcurrencyLimitExceeded, ['Retry-After' => '5'], ConcurrencyLimitExceededException::class, 5],
            '429 daily limit exceeded, no Retry-After' => [PixelforgeErrorCode::DailyLimitExceeded, [], DailyLimitExceededException::class, null],
            '503 overloaded, Retry-After 2' => [PixelforgeErrorCode::Overloaded, ['Retry-After' => '2'], OverloadedException::class, 2],
        ];
    }

    public function test_a_validation_failure_keeps_its_param_and_field_errors(): void
    {
        Http::fake(['*' => self::pixelforgeErrorFor(PixelforgeErrorCode::ValidationFailed, [
            'message' => 'The size field is invalid.',
            'param' => 'size',
            'errors' => ['size' => ['The selected size is invalid.']],
        ])]);

        try {
            $this->client()->createGeneration(self::lighthousePayload());
            $this->fail('Expected a validation failure.');
        } catch (ValidationFailedException $exception) {
            $this->assertSame('size', $exception->param);
            $this->assertSame('The selected size is invalid.', $exception->errors['size'][0] ?? null);
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('nonEnvelopeResponses')]
    public function test_a_response_without_a_documented_envelope_is_ambiguous(int $status, string $body, array $headers): void
    {
        Http::fake(['*' => Http::response($body, $status, $headers)]);

        $exception = $this->createExpectingAmbiguous(self::lighthousePayload());

        $this->assertSame($status, $exception->status);
    }

    /**
     * @return array<string, array{int, string, array<string, string>}>
     */
    public static function nonEnvelopeResponses(): array
    {
        return [
            'a 502 HTML page' => [502, '<html><body><h1>502 Bad Gateway</h1></body></html>', ['Content-Type' => 'text/html']],
            'an empty 500' => [500, '', []],
            'an undocumented code' => [429, '{"error":{"type":"rate_limit_error","code":"quota_exceeded","message":"Quota exceeded."}}', ['Content-Type' => 'application/json']],
            'a documented code at another status' => [500, '{"error":{"type":"api_error","code":"overloaded","message":"Overloaded."}}', ['Content-Type' => 'application/json']],
        ];
    }

    #[DataProvider('unreadableGenerations')]
    public function test_a_2xx_that_is_not_a_generation_object_is_ambiguous(string $body, bool $hasPrevious): void
    {
        Http::fake(['*' => Http::response($body, 202, ['Content-Type' => 'application/json'])]);

        $exception = $this->createExpectingAmbiguous(self::lighthousePayload());

        $this->assertSame(202, $exception->status);
        $this->assertSame($hasPrevious, $exception->getPrevious() !== null);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function unreadableGenerations(): array
    {
        $withoutObject = self::queuedObject();
        unset($withoutObject['object']);

        return [
            'not JSON' => ['<html>Accepted</html>', true],
            'a JSON string' => ['"accepted"', false],
            'a JSON list' => ['[1, 2, 3]', true],
            'no object field' => [json_encode($withoutObject, JSON_THROW_ON_ERROR), true],
            'succeeded without usage' => [json_encode([
                ...self::queuedObject(),
                'status' => 'succeeded',
                'image_url' => 'https://pixelforge.promptedit.dev/images/x.png',
                'completed_at' => '2026-11-04T14:03:30Z',
            ], JSON_THROW_ON_ERROR), true],
        ];
    }

    public function test_an_unreadable_generation_keeps_the_parse_error_as_its_cause(): void
    {
        Http::fake(['*' => Http::response('{"object":"generation"}', 202)]);

        $exception = $this->createExpectingAmbiguous(self::lighthousePayload());

        $this->assertInstanceOf(InvalidGenerationSnapshotException::class, $exception->getPrevious());
    }

    #[DataProvider('missingKeys')]
    public function test_no_key_raises_not_configured_and_sends_nothing(?string $key): void
    {
        config(['pixelforge.api_key' => $key]);
        Http::fake();

        try {
            $this->client()->createGeneration(self::lighthousePayload());
            $this->fail('Expected a not-configured error.');
        } catch (PixelforgeNotConfiguredException $exception) {
            $this->assertStringContainsString('PIXELFORGE_API_KEY', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function missingKeys(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
        ];
    }

    private function client(): PixelforgeClient
    {
        return $this->app->make(PixelforgeClient::class);
    }

    private function createExpectingAmbiguous(CreateGenerationPayloadData $payload): AmbiguousPixelforgeOutcomeException
    {
        try {
            $this->client()->createGeneration($payload);
        } catch (AmbiguousPixelforgeOutcomeException $exception) {
            return $exception;
        }

        $this->fail('Expected an ambiguous outcome.');
    }

    private static function lighthousePayload(): CreateGenerationPayloadData
    {
        return new CreateGenerationPayloadData(
            self::GENERATION_ID,
            self::LIGHTHOUSE_PROMPT,
            ImageSize::Landscape,
            ImageQuality::High,
            ['https://example.com/style.png'],
        );
    }

    /**
     * The docs' create response, echoing our ULID.
     *
     * @return array<string, mixed>
     */
    private static function queuedObject(): array
    {
        return [
            ...self::pixelforgeFixture('generation-queued'),
            'metadata' => ['generation_id' => self::GENERATION_ID],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodedBody(Request $request): array
    {
        $body = json_decode($request->body(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * The header names we set, without those the HTTP stack adds to every request.
     *
     * @return list<string>
     */
    private static function applicationHeaderNames(Request $request): array
    {
        $names = array_map(strtolower(...), array_map(strval(...), array_keys($request->headers())));
        $names = array_values(array_diff($names, ['host', 'user-agent', 'content-length']));
        sort($names);

        return $names;
    }
}
