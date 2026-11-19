<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Pixelforge\Exceptions;

use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use App\Support\Pixelforge\Exceptions\ConcurrencyLimitExceededException;
use App\Support\Pixelforge\Exceptions\ContentPolicyViolationException;
use App\Support\Pixelforge\Exceptions\DailyLimitExceededException;
use App\Support\Pixelforge\Exceptions\IdempotencyKeyReusedException;
use App\Support\Pixelforge\Exceptions\InvalidApiKeyException;
use App\Support\Pixelforge\Exceptions\NotFoundException;
use App\Support\Pixelforge\Exceptions\OverloadedException;
use App\Support\Pixelforge\Exceptions\PixelforgeApiException;
use App\Support\Pixelforge\Exceptions\RateLimitExceededException;
use App\Support\Pixelforge\Exceptions\ValidationFailedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Only an envelope documented exactly becomes a typed error. Everything else
 * is null, which the driver turns into a retryable ambiguous outcome.
 */
final class PixelforgeApiExceptionTest extends TestCase
{
    /**
     * @param  class-string<PixelforgeApiException>  $class
     */
    #[DataProvider('documentedCodes')]
    public function test_each_documented_code_at_its_status_gives_its_typed_error(PixelforgeErrorCode $code, int $status, string $type, string $class): void
    {
        $exception = PixelforgeApiException::tryFromResponse($status, self::envelope([
            'type' => $type,
            'code' => $code->value,
            'message' => 'The documented message.',
        ]), null);

        $this->assertInstanceOf($class, $exception);
        $this->assertSame($code, $exception->errorCode());
        $this->assertSame($type, $exception->type);
        $this->assertSame($status, $exception->status());
        $this->assertSame('The documented message.', $exception->getMessage());
        $this->assertNull($exception->param);
        $this->assertNull($exception->errors);
        $this->assertNull($exception->retryAfterSeconds);
    }

    /**
     * @return array<string, array{PixelforgeErrorCode, int, string, class-string<PixelforgeApiException>}>
     */
    public static function documentedCodes(): array
    {
        return [
            'content policy violation' => [PixelforgeErrorCode::ContentPolicyViolation, 400, 'invalid_request_error', ContentPolicyViolationException::class],
            'invalid api key' => [PixelforgeErrorCode::InvalidApiKey, 401, 'authentication_error', InvalidApiKeyException::class],
            'not found' => [PixelforgeErrorCode::NotFound, 404, 'invalid_request_error', NotFoundException::class],
            'idempotency key reused' => [PixelforgeErrorCode::IdempotencyKeyReused, 409, 'idempotency_error', IdempotencyKeyReusedException::class],
            'validation failed' => [PixelforgeErrorCode::ValidationFailed, 422, 'invalid_request_error', ValidationFailedException::class],
            'rate limit exceeded' => [PixelforgeErrorCode::RateLimitExceeded, 429, 'rate_limit_error', RateLimitExceededException::class],
            'concurrency limit exceeded' => [PixelforgeErrorCode::ConcurrencyLimitExceeded, 429, 'rate_limit_error', ConcurrencyLimitExceededException::class],
            'daily limit exceeded' => [PixelforgeErrorCode::DailyLimitExceeded, 429, 'rate_limit_error', DailyLimitExceededException::class],
            'overloaded' => [PixelforgeErrorCode::Overloaded, 503, 'api_error', OverloadedException::class],
        ];
    }

    public function test_param_and_errors_are_carried_when_present(): void
    {
        $exception = PixelforgeApiException::tryFromResponse(422, self::envelope([
            'type' => 'invalid_request_error',
            'code' => 'validation_failed',
            'message' => 'The size field is invalid.',
            'param' => 'size',
            'errors' => ['size' => ['The selected size is invalid.'], 'quality' => []],
        ]), null);

        $this->assertInstanceOf(ValidationFailedException::class, $exception);
        $this->assertSame('size', $exception->param);
        $this->assertSame(['size' => ['The selected size is invalid.'], 'quality' => []], $exception->errors);
    }

    public function test_a_null_param_and_null_errors_count_as_absent(): void
    {
        $exception = PixelforgeApiException::tryFromResponse(400, self::envelope([
            ...self::contentPolicyError(),
            'param' => null,
            'errors' => null,
        ]), null);

        $this->assertInstanceOf(ContentPolicyViolationException::class, $exception);
        $this->assertNull($exception->param);
        $this->assertNull($exception->errors);
    }

    public function test_a_255_character_param_is_kept(): void
    {
        $param = str_repeat('é', 255);

        $exception = PixelforgeApiException::tryFromResponse(400, self::envelope([...self::contentPolicyError(), 'param' => $param]), null);

        $this->assertSame($param, $exception?->param);
    }

    public function test_unknown_keys_are_ignored(): void
    {
        $exception = PixelforgeApiException::tryFromResponse(400, (string) json_encode([
            'error' => [...self::contentPolicyError(), 'doc_url' => 'https://pixelforge.promptedit.dev/docs#errors'],
            'request_id' => 'req_1',
        ]), null);

        $this->assertInstanceOf(ContentPolicyViolationException::class, $exception);
    }

    #[DataProvider('retryAfterHeaders')]
    public function test_retry_after_counts_only_as_whole_seconds(?string $header, ?int $seconds): void
    {
        $exception = PixelforgeApiException::tryFromResponse(503, self::envelope([
            'type' => 'api_error',
            'code' => 'overloaded',
            'message' => 'Pixelforge is overloaded.',
        ]), $header);

        $this->assertInstanceOf(OverloadedException::class, $exception);
        $this->assertSame($seconds, $exception->retryAfterSeconds);
    }

    /**
     * @return array<string, array{?string, ?int}>
     */
    public static function retryAfterHeaders(): array
    {
        return [
            'seventeen' => ['17', 17],
            'zero' => ['0', 0],
            'absent' => [null, null],
            'empty' => ['', null],
            'a word' => ['soon', null],
            'a fraction' => ['1.5', null],
            'negative' => ['-1', null],
            'an HTTP date' => ['Wed, 19 Nov 2026 18:00:00 GMT', null],
            'ten digits' => ['1234567890', null],
        ];
    }

    #[DataProvider('undocumentedResponses')]
    public function test_anything_but_a_documented_envelope_is_null(int $status, string $body): void
    {
        $this->assertNull(PixelforgeApiException::tryFromResponse($status, $body, null));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function undocumentedResponses(): array
    {
        $valid = self::contentPolicyError();
        $without = fn (string $key): array => array_diff_key($valid, [$key => true]);

        return [
            'not JSON' => [400, '<html>Bad Request</html>'],
            'empty' => [400, ''],
            'a JSON list' => [400, '[{"error": {}}]'],
            'a JSON string' => [400, '"error"'],
            'no error' => [400, '{"message": "Bad Request"}'],
            'a string error' => [400, '{"error": "content_policy_violation"}'],
            'a null error' => [400, '{"error": null}'],
            'no type' => [400, self::envelope($without('type'))],
            'no code' => [400, self::envelope($without('code'))],
            'no message' => [400, self::envelope($without('message'))],
            'a numeric type' => [400, self::envelope([...$valid, 'type' => 1])],
            'a numeric code' => [400, self::envelope([...$valid, 'code' => 400])],
            'a null message' => [400, self::envelope([...$valid, 'message' => null])],
            'a numeric param' => [400, self::envelope([...$valid, 'param' => 7])],
            'a 256-character param' => [400, self::envelope([...$valid, 'param' => str_repeat('p', 256)])],
            'string errors' => [400, self::envelope([...$valid, 'errors' => 'size is invalid'])],
            'errors as a list' => [400, self::envelope([...$valid, 'errors' => [['size is invalid']]])],
            'errors holding a string' => [400, self::envelope([...$valid, 'errors' => ['size' => 'invalid']])],
            'errors holding an object' => [400, self::envelope([...$valid, 'errors' => ['size' => ['first' => 'invalid']]])],
            'errors holding a number' => [400, self::envelope([...$valid, 'errors' => ['size' => [1]]])],
            'an undocumented code' => [429, self::envelope(['type' => 'rate_limit_error', 'code' => 'quota_exceeded', 'message' => 'Quota exceeded.'])],
            'a code differing in case' => [400, self::envelope([...$valid, 'code' => 'Content_Policy_Violation'])],
            'overloaded at 400' => [400, self::envelope(['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Overloaded.'])],
            'validation failed at 500' => [500, self::envelope(['type' => 'invalid_request_error', 'code' => 'validation_failed', 'message' => 'Invalid.'])],
            'a NUL byte in the code' => [400, self::envelope([...$valid, 'code' => "content_policy_violation\0"])],
            'a NUL byte in the message' => [400, self::envelope([...$valid, 'message' => "Rejected\0 here."])],
            'a NUL byte in the param' => [400, self::envelope([...$valid, 'param' => "prompt\0"])],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function contentPolicyError(): array
    {
        return [
            'type' => 'invalid_request_error',
            'code' => 'content_policy_violation',
            'message' => 'The prompt was rejected by the content policy.',
        ];
    }

    /**
     * @param  array<string, mixed>  $error
     */
    private static function envelope(array $error): string
    {
        return json_encode(['error' => $error], JSON_THROW_ON_ERROR);
    }
}
