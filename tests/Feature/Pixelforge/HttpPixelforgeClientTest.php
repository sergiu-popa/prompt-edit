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
use App\Support\Pixelforge\ThrottledPixelforgeClient;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionParameter;
use SensitiveParameter;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;

/**
 * The HTTP driver against `Http::fake`: what it sends, and how every answer
 * becomes a created generation, a typed error or a single ambiguous error.
 */
final class HttpPixelforgeClientTest extends TestCase
{
    use CapturesLogMessages;
    use InteractsWithGenerationSnapshots;
    use InteractsWithPixelforgeApi;

    private const string GENERATION_ID = '01JC2Z8Q4N6V0M3H7X5T9R2B1K';

    private const string LIGHTHOUSE_PROMPT = 'A lighthouse on a cliff at dawn, oil painting';

    private const string CREATE_URL = 'https://pixelforge.promptedit.dev/v1/generations';

    private const string PROVIDER_ID = 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K';

    public function test_the_http_driver_resolves_the_http_client_afresh_each_time_behind_the_rate_budget(): void
    {
        config(['pixelforge.driver' => 'http']);

        $first = $this->app->make(PixelforgeClient::class);
        $second = $this->app->make(PixelforgeClient::class);

        $this->assertInstanceOf(ThrottledPixelforgeClient::class, $first);
        $this->assertInstanceOf(ThrottledPixelforgeClient::class, $second);
        $this->assertInstanceOf(HttpPixelforgeClient::class, $first->client);
        $this->assertInstanceOf(HttpPixelforgeClient::class, $second->client);
        $this->assertNotSame($first, $second);
        $this->assertNotSame($first->client, $second->client);
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
        ], self::decodedRequestBody($this->recordedCreateRequests()[0]));
    }

    public function test_no_reference_images_sends_no_reference_key(): void
    {
        Http::fake(['*' => self::pixelforgeAccepted(self::queuedObject())]);

        $this->client()->createGeneration(new CreateGenerationPayloadData(self::GENERATION_ID, self::LIGHTHOUSE_PROMPT, ImageSize::Square, ImageQuality::Medium, []));

        $this->assertSame(['prompt', 'size', 'quality', 'metadata'], array_keys(self::decodedRequestBody($this->recordedCreateRequests()[0])));
    }

    public function test_a_unicode_prompt_is_sent_unchanged(): void
    {
        Http::fake(['*' => self::pixelforgeAccepted(self::queuedObject())]);

        $this->client()->createGeneration(new CreateGenerationPayloadData(self::GENERATION_ID, self::UNICODE_PROMPT, ImageSize::Landscape, ImageQuality::High, []));

        $this->assertSame(self::UNICODE_PROMPT, self::decodedRequestBody($this->recordedCreateRequests()[0])['prompt']);
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
            'a documented code at another status' => [500, '{"error":{"type":"api_error","code":"overloaded","message":"Overloaded."}}', ['Content-Type' => 'application/json']],
            'a 503 whose code is not overloaded' => [503, '{"error":{"type":"api_error","code":"maintenance","message":"Down for maintenance."}}', ['Content-Type' => 'application/json', 'Retry-After' => '30']],
        ];
    }

    /**
     * A 429 never creates anything, so one without a documented 429 envelope is
     * a rate limit, keeping any whole-second `Retry-After` (AC 4).
     *
     * @param  array<string, string>  $headers
     */
    #[DataProvider('undocumentedRateLimits')]
    public function test_a_429_without_a_documented_envelope_is_a_rate_limit(string $body, array $headers, ?int $retryAfterSeconds): void
    {
        Http::fake(['*' => Http::response($body, 429, $headers)]);

        try {
            $this->client()->createGeneration(self::lighthousePayload());
            $this->fail('Expected a rate limit.');
        } catch (RateLimitExceededException $exception) {
            $this->assertSame(PixelforgeErrorCode::RateLimitExceeded, $exception->errorCode());
            $this->assertSame(429, $exception->status());
            $this->assertSame($retryAfterSeconds, $exception->retryAfterSeconds);
        }
    }

    /**
     * @return array<string, array{string, array<string, string>, ?int}>
     */
    public static function undocumentedRateLimits(): array
    {
        return [
            'an undocumented code' => ['{"error":{"type":"rate_limit_error","code":"quota_exceeded","message":"Quota exceeded."}}', ['Content-Type' => 'application/json'], null],
            'an HTML page with Retry-After 9' => ['<html><body><h1>429 Too Many Requests</h1></body></html>', ['Content-Type' => 'text/html', 'Retry-After' => '9'], 9],
            'overloaded sent with a 429' => ['{"error":{"type":"api_error","code":"overloaded","message":"Overloaded."}}', ['Content-Type' => 'application/json', 'Retry-After' => '2'], 2],
            'an HTTP-date Retry-After' => ['', ['Retry-After' => 'Fri, 20 Nov 2026 10:00:00 GMT'], null],
        ];
    }

    #[DataProvider('transportFailures')]
    public function test_a_transport_failure_is_ambiguous(string $curlMessage): void
    {
        Http::fake(['*' => Http::sequence()->pushFailedConnection($curlMessage)]);

        $exception = $this->createExpectingAmbiguous(self::lighthousePayload());

        $this->assertNull($exception->status);
        $this->assertInstanceOf(ConnectionException::class, $exception->getPrevious());
        $this->assertStringNotContainsString($curlMessage, $exception->getMessage());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function transportFailures(): array
    {
        return [
            'connect timeout' => ['cURL error 28: Connection timed out after 5001 milliseconds (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://pixelforge.promptedit.dev/v1/generations'],
            'read timeout' => ['cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://pixelforge.promptedit.dev/v1/generations'],
            'connection refused' => ['cURL error 7: Failed to connect to pixelforge.promptedit.dev port 443: Connection refused (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://pixelforge.promptedit.dev/v1/generations'],
        ];
    }

    /**
     * @param  class-string<ResponseTransferException>  $guzzleException
     */
    #[DataProvider('cutOffResponses')]
    public function test_a_response_cut_off_after_its_status_line_is_ambiguous(string $guzzleException, int $status, string $truncatedBody): void
    {
        Http::fake(['*' => self::pixelforgeCutOff($guzzleException, $status, $truncatedBody)]);

        $exception = $this->createExpectingAmbiguous(self::lighthousePayload());

        $this->assertSame($status, $exception->status);
        $this->assertInstanceOf(RequestException::class, $exception->getPrevious());
        $this->assertStringNotContainsString('cURL error', $exception->getMessage());
    }

    /**
     * @return array<string, array{class-string<ResponseTransferException>, int, string}>
     */
    public static function cutOffResponses(): array
    {
        return [
            'a 503 whose body stalls past the timeout' => [ResponseTimeoutException::class, 503, '{"error":{"type":"api_error","code":"over'],
            'a 429 reset mid-body' => [ResponseTransferException::class, 429, '{"error":{"type":"rate_limit_error","co'],
            'a 422 reset mid-body' => [ResponseTransferException::class, 422, '{"error":{"type":"invalid_request_error"'],
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

    /**
     * `Tests\TestCase` refuses every request no test has faked, so no test can
     * ever reach the real API.
     */
    public function test_an_unfaked_create_is_refused_before_it_leaves_the_suite(): void
    {
        try {
            $this->client()->createGeneration(self::lighthousePayload());
            $this->fail('Expected the unfaked create to be refused.');
        } catch (StrayRequestException $exception) {
            $this->assertStringNotContainsString(self::PIXELFORGE_API_KEY, (string) $exception);
        }
    }

    public function test_with_a_key_it_is_configured(): void
    {
        $this->assertTrue($this->client()->isConfigured());
    }

    #[DataProvider('missingKeys')]
    public function test_without_a_key_it_is_not_configured(?string $key): void
    {
        config(['pixelforge.api_key' => $key]);

        $this->assertFalse($this->client()->isConfigured());
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

    #[DataProvider('keyHygieneAnswers')]
    public function test_the_api_key_appears_in_no_log_or_exception(string $answer): void
    {
        Http::fake(['*' => match ($answer) {
            '202' => self::pixelforgeAccepted(self::queuedObject()),
            '401' => self::pixelforgeErrorFor(PixelforgeErrorCode::InvalidApiKey),
            'read timeout' => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'),
            '503 cut off' => self::pixelforgeCutOff(ResponseTimeoutException::class, 503, '{"error":{"type":"api_error","code":"over'),
            '503' => self::pixelforgeErrorFor(PixelforgeErrorCode::Overloaded, headers: ['Retry-After' => '2']),
            default => $this->fail(sprintf('Unknown answer %s.', $answer)),
        }]);

        $thrown = null;

        try {
            $this->client()->createGeneration(self::lighthousePayload());
        } catch (PixelforgeApiException|AmbiguousPixelforgeOutcomeException $exception) {
            $thrown = $exception;
        }

        $this->assertSame($answer === '202', $thrown === null);
        $this->assertStringNotContainsString(self::PIXELFORGE_API_KEY, (string) $thrown);

        foreach ($this->loggedMessages() as $logged) {
            $this->assertStringNotContainsString(self::PIXELFORGE_API_KEY, $logged->message);
            $this->assertStringNotContainsString(self::PIXELFORGE_API_KEY, (string) json_encode($logged->context, JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function keyHygieneAnswers(): array
    {
        return [
            'a 202' => ['202'],
            'a 401' => ['401'],
            'a read timeout' => ['read timeout'],
            'a 503 cut off mid-body' => ['503 cut off'],
            'a 503' => ['503'],
        ];
    }

    public function test_a_retrieve_returns_the_generation_as_it_is_now(): void
    {
        Http::fake(['*' => self::pixelforgeRetrieved(self::succeededObject())]);

        $snapshot = $this->client()->retrieveGeneration(self::PROVIDER_ID);

        $this->assertSame(self::PROVIDER_ID, $snapshot->id);
        $this->assertSame(GenerationStatus::Succeeded, $snapshot->status);
        $this->assertSame(self::GENERATION_ID, $snapshot->generationId);
        $this->assertSame(3_184, $snapshot->usage?->reasoningTokens);
    }

    public function test_a_retrieve_is_a_bare_get_of_the_generation(): void
    {
        $options = [];
        Http::fake(function (Request $request, array $requestOptions) use (&$options): PromiseInterface {
            $options = $requestOptions;

            return self::pixelforgeRetrieved(self::queuedObject());
        });

        $this->client()->retrieveGeneration(self::PROVIDER_ID);

        $requests = $this->recordedRetrieveRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('GET', $requests[0]->method());
        $this->assertSame(self::CREATE_URL . '/' . self::PROVIDER_ID, $requests[0]->url());
        $this->assertSame(['Bearer ' . self::PIXELFORGE_API_KEY], $requests[0]->header('Authorization'));
        $this->assertSame(['application/json'], $requests[0]->header('Accept'));
        $this->assertSame(['accept', 'authorization'], self::applicationHeaderNames($requests[0]));
        $this->assertSame('', $requests[0]->body());
        $this->assertSame(5, $options['connect_timeout'] ?? null);
        $this->assertSame(30, $options['timeout'] ?? null);
    }

    public function test_a_retrieve_encodes_the_id_into_one_path_segment(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        try {
            $this->client()->retrieveGeneration('gen_1/../usage?x=1');
        } catch (AmbiguousPixelforgeOutcomeException) {
            //
        }

        $this->assertSame(self::CREATE_URL . '/gen_1%2F..%2Fusage%3Fx%3D1', $this->recordedRetrieveRequests()[0]->url());
    }

    /**
     * @param  array<string, string>  $headers
     * @param  class-string<PixelforgeApiException>  $class
     */
    #[DataProvider('documentedRetrieveErrors')]
    public function test_a_documented_retrieve_error_becomes_its_typed_error(PixelforgeErrorCode $code, array $headers, string $class, ?int $retryAfterSeconds): void
    {
        Http::fake(['*' => self::pixelforgeErrorFor($code, headers: $headers)]);

        try {
            $this->client()->retrieveGeneration(self::PROVIDER_ID);
            $this->fail('Expected a typed Pixelforge error.');
        } catch (PixelforgeApiException $exception) {
            $this->assertInstanceOf($class, $exception);
            $this->assertSame($retryAfterSeconds, $exception->retryAfterSeconds);
        }
    }

    /**
     * @return array<string, array{PixelforgeErrorCode, array<string, string>, class-string<PixelforgeApiException>, ?int}>
     */
    public static function documentedRetrieveErrors(): array
    {
        return [
            '404 not found' => [PixelforgeErrorCode::NotFound, [], NotFoundException::class, null],
            '401 invalid api key' => [PixelforgeErrorCode::InvalidApiKey, [], InvalidApiKeyException::class, null],
            '429 rate limit exceeded, Retry-After 17' => [PixelforgeErrorCode::RateLimitExceeded, ['Retry-After' => '17'], RateLimitExceededException::class, 17],
        ];
    }

    #[DataProvider('ambiguousRetrieveAnswers')]
    public function test_a_retrieve_answer_that_is_no_generation_or_typed_error_is_ambiguous(string $answer, ?int $status): void
    {
        Http::fake(['*' => match ($answer) {
            '500' => Http::response('', 500),
            'undocumented 404' => Http::response('<html>Not Found</html>', 404, ['Content-Type' => 'text/html']),
            '302' => Http::response('', 302, ['Location' => 'https://elsewhere.example/generations']),
            'timeout' => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'),
            'unreadable 200' => Http::response('{"object":"generation"}', 200),
            default => $this->fail(sprintf('Unknown answer %s.', $answer)),
        }]);

        try {
            $this->client()->retrieveGeneration(self::PROVIDER_ID);
            $this->fail('Expected an ambiguous outcome.');
        } catch (AmbiguousPixelforgeOutcomeException $exception) {
            $this->assertSame($status, $exception->status);
        }

        Http::assertSentCount(1);
    }

    /**
     * @return array<string, array{string, ?int}>
     */
    public static function ambiguousRetrieveAnswers(): array
    {
        return [
            'a 500' => ['500', 500],
            'a 404 without the envelope' => ['undocumented 404', 404],
            'a 302, not followed' => ['302', 302],
            'a read timeout' => ['timeout', null],
            'an unreadable 200' => ['unreadable 200', 200],
        ];
    }

    #[DataProvider('missingKeys')]
    public function test_a_retrieve_without_a_key_raises_not_configured_and_sends_nothing(?string $key): void
    {
        config(['pixelforge.api_key' => $key]);
        Http::fake();

        $this->expectException(PixelforgeNotConfiguredException::class);

        try {
            $this->client()->retrieveGeneration(self::PROVIDER_ID);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_trace_through_the_constructor_redacts_the_key(): void
    {
        $parameter = new ReflectionParameter([HttpPixelforgeClient::class, '__construct'], 'apiKey');

        $this->assertNotSame([], $parameter->getAttributes(SensitiveParameter::class));
    }

    /**
     * The driver itself, not the binding: this class has no RefreshDatabase, and
     * the binding's budget would leave `cache` rows behind.
     */
    private function client(): HttpPixelforgeClient
    {
        return $this->app->make(HttpPixelforgeClient::class);
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
     * The docs' succeeded object, echoing our ULID.
     *
     * @return array<string, mixed>
     */
    private static function succeededObject(): array
    {
        return [
            ...self::pixelforgeFixture('generation-succeeded'),
            'metadata' => ['generation_id' => self::GENERATION_ID],
        ];
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
