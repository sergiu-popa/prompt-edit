<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use App\Support\Pixelforge\Contracts\ReportsPixelforgeContext;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use JsonException;
use RuntimeException;

/**
 * A documented Pixelforge error envelope, `{error: {type, code, message,
 * param?, errors?}}`, with one subclass per code. Only an envelope that is
 * documented exactly becomes one; anything else is ambiguous (D-31).
 */
abstract class PixelforgeApiException extends RuntimeException implements ReportsPixelforgeContext
{
    /**
     * `error_param` is a varchar(255).
     */
    private const int MAX_PARAM_LENGTH = 255;

    /**
     * @param  ?array<string, list<string>>  $errors  per-field messages, as a 422 carries them
     * @param  ?int  $retryAfterSeconds  the `Retry-After` header, when it was sent as whole seconds
     */
    final public function __construct(
        public readonly string $type,
        string $message,
        public readonly ?string $param = null,
        public readonly ?array $errors = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    abstract public function errorCode(): PixelforgeErrorCode;

    public function status(): int
    {
        return $this->errorCode()->httpStatus();
    }

    /**
     * Merged into the log entry whenever the exception is reported.
     *
     * @return array{error_code: string, status: int, retry_after_seconds: ?int}
     */
    public function context(): array
    {
        return [
            'error_code' => $this->errorCode()->value,
            'status' => $this->status(),
            'retry_after_seconds' => $this->retryAfterSeconds,
        ];
    }

    /**
     * The typed error for a documented envelope, or null when the response is
     * not one: not a JSON object with an `error` object; a `type`, `code` or
     * `message` that is not a string; a `param` that is not a string of at
     * most 255 characters; `errors` that are not lists of strings; a NUL byte
     * in `code`, `message` or `param` (Postgres text would cut it); or a code
     * that is undocumented or sent with another status. Unknown keys are
     * ignored. `Retry-After` counts only as whole seconds.
     */
    public static function tryFromResponse(int $status, string $body, ?string $retryAfterHeader): ?self
    {
        $error = self::errorObject($body);

        if ($error === null) {
            return null;
        }

        $type = $error['type'] ?? null;
        $code = $error['code'] ?? null;
        $message = $error['message'] ?? null;
        $param = $error['param'] ?? null;
        $errors = self::fieldErrors($error['errors'] ?? null);

        if (! is_string($type) || ! is_string($code) || ! is_string($message) || $errors === false) {
            return null;
        }

        if ($param !== null && (! is_string($param) || mb_strlen($param) > self::MAX_PARAM_LENGTH)) {
            return null;
        }

        if (str_contains($code . $message . ($param ?? ''), "\0")) {
            return null;
        }

        $errorCode = PixelforgeErrorCode::tryFrom($code);

        if ($errorCode === null || $errorCode->httpStatus() !== $status) {
            return null;
        }

        $retryAfterSeconds = $retryAfterHeader !== null && preg_match('/^\d{1,9}\z/', $retryAfterHeader) === 1
            ? (int) $retryAfterHeader
            : null;

        return match ($errorCode) {
            PixelforgeErrorCode::ContentPolicyViolation => new ContentPolicyViolationException($type, $message, $param, $errors, $retryAfterSeconds),
            PixelforgeErrorCode::InvalidApiKey => new InvalidApiKeyException($type, $message, $param, $errors, $retryAfterSeconds),
            PixelforgeErrorCode::NotFound => new NotFoundException($type, $message, $param, $errors, $retryAfterSeconds),
            PixelforgeErrorCode::IdempotencyKeyReused => new IdempotencyKeyReusedException($type, $message, $param, $errors, $retryAfterSeconds),
            PixelforgeErrorCode::ValidationFailed => new ValidationFailedException($type, $message, $param, $errors, $retryAfterSeconds),
            PixelforgeErrorCode::RateLimitExceeded => new RateLimitExceededException($type, $message, $param, $errors, $retryAfterSeconds),
            PixelforgeErrorCode::ConcurrencyLimitExceeded => new ConcurrencyLimitExceededException($type, $message, $param, $errors, $retryAfterSeconds),
            PixelforgeErrorCode::DailyLimitExceeded => new DailyLimitExceededException($type, $message, $param, $errors, $retryAfterSeconds),
            PixelforgeErrorCode::Overloaded => new OverloadedException($type, $message, $param, $errors, $retryAfterSeconds),
        };
    }

    /**
     * @return ?array<array-key, mixed>
     */
    private static function errorObject(string $body): ?array
    {
        try {
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        return is_array($error) ? $error : null;
    }

    /**
     * @return array<string, list<string>>|null|false false when present but not an object of string lists
     */
    private static function fieldErrors(mixed $errors): array|null|false
    {
        if ($errors === null) {
            return null;
        }

        if (! is_array($errors)) {
            return false;
        }

        $fieldErrors = [];

        foreach ($errors as $field => $messages) {
            if (! is_string($field) || ! is_array($messages) || ! array_is_list($messages)) {
                return false;
            }

            $strings = [];

            foreach ($messages as $message) {
                if (! is_string($message)) {
                    return false;
                }

                $strings[] = $message;
            }

            $fieldErrors[$field] = $strings;
        }

        return $fieldErrors;
    }
}
