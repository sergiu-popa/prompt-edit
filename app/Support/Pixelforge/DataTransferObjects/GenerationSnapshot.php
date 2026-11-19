<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\DataTransferObjects;

use App\Domain\Billing\Enums\TokenType;
use App\Domain\Billing\Exceptions\InvalidTokenUsageException;
use App\Domain\Billing\ValueObjects\TokenRates;
use App\Domain\Billing\ValueObjects\TokenUsage;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Support\Pixelforge\Exceptions\InvalidGenerationSnapshotException;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Pixelforge's generation object as it is now, from an API response or a
 * webhook's `data.object`. Every instant is in UTC.
 */
final readonly class GenerationSnapshot
{
    /**
     * ISO-8601 in whole seconds, with `Z` or an offset, as Pixelforge sends it.
     * The offset is range-checked here, because PHP rolls `+14:60` over to
     * `+15:00` without a warning.
     */
    private const string TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)\z/';

    /**
     * @param  ?array<string, string>  $rates  the rate strings exactly as received
     */
    private function __construct(
        public string $id,
        public GenerationStatus $status,
        public ImageSize $size,
        public ImageQuality $quality,
        public ?string $generationId,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?TokenUsage $usage,
        public ?array $rates,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /**
     * Unknown top-level fields are ignored; anything else that cannot be read
     * exactly throws. A succeeded snapshot must carry its whole result, while a
     * failed one may have a null error (shown as a generic failure). A
     * succeeded snapshot without its result is also logged with our
     * generation id, because it means Pixelforge billed a generation we
     * cannot price.
     *
     * @param  array<array-key, mixed>  $generation
     *
     * @throws InvalidGenerationSnapshotException
     */
    public static function from(array $generation): self
    {
        $id = self::requiredString($generation, 'id');

        if (self::required($generation, 'object') !== 'generation') {
            throw InvalidGenerationSnapshotException::invalidField('object', '"generation"', $generation['object']);
        }

        $metadata = self::required($generation, 'metadata');

        if (! is_array($metadata)) {
            throw InvalidGenerationSnapshotException::invalidField('metadata', 'an object', $metadata);
        }

        [$errorCode, $errorMessage] = self::error($generation);
        $status = self::status(self::required($generation, 'status'));

        $generationId = self::generationId($metadata);

        if ($status === GenerationStatus::Succeeded) {
            foreach (['usage', 'rates', 'image_url', 'completed_at'] as $field) {
                if (($generation[$field] ?? null) === null) {
                    $exception = InvalidGenerationSnapshotException::incompleteSuccess($field);

                    Log::error('Refused a succeeded Pixelforge snapshot that does not carry its whole result.', [
                        'generation_id' => $generationId,
                        'provider_id' => $id,
                        'error' => $exception->getMessage(),
                    ]);

                    throw $exception;
                }
            }
        }

        return new self(
            $id,
            $status,
            self::enum(ImageSize::class, $generation, 'size'),
            self::enum(ImageQuality::class, $generation, 'quality'),
            $generationId,
            $errorCode,
            $errorMessage,
            self::usage($generation),
            self::rates($generation),
            self::optionalString($generation, 'image_url'),
            self::optionalInstant($generation, 'image_url_expires_at'),
            self::instant('created_at', self::required($generation, 'created_at')),
            self::optionalInstant($generation, 'started_at'),
            self::optionalInstant($generation, 'completed_at'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $generation
     *
     * @throws InvalidGenerationSnapshotException
     */
    private static function required(array $generation, string $field): mixed
    {
        if (! array_key_exists($field, $generation)) {
            throw InvalidGenerationSnapshotException::missingField($field);
        }

        return $generation[$field];
    }

    /**
     * @param  array<array-key, mixed>  $generation
     *
     * @throws InvalidGenerationSnapshotException
     */
    private static function requiredString(array $generation, string $field): string
    {
        $value = self::required($generation, $field);

        if (! is_string($value) || $value === '') {
            throw InvalidGenerationSnapshotException::invalidField($field, 'a non-empty string', $value);
        }

        return self::storable($field, $value);
    }

    /**
     * Absent means the same as null.
     *
     * @param  array<array-key, mixed>  $generation
     *
     * @throws InvalidGenerationSnapshotException
     */
    private static function optionalString(array $generation, string $field): ?string
    {
        $value = $generation[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            throw InvalidGenerationSnapshotException::invalidField($field, 'a non-empty string or null', $value);
        }

        return self::storable($field, $value);
    }

    /**
     * JSON's `\u0000` decodes to a NUL byte, which Postgres text cannot hold
     * and pdo_pgsql silently cuts the value at. A cut provider id would refuse
     * every later snapshot of its generation, so the value is refused instead.
     *
     * @throws InvalidGenerationSnapshotException
     */
    private static function storable(string $path, string $value): string
    {
        if (str_contains($value, "\0")) {
            throw InvalidGenerationSnapshotException::nulByte($path);
        }

        return $value;
    }

    /**
     * `pending` is ours, never Pixelforge's, so it counts as unknown.
     *
     * @throws InvalidGenerationSnapshotException
     */
    private static function status(mixed $value): GenerationStatus
    {
        $status = is_string($value) ? GenerationStatus::tryFrom($value) : null;

        if ($status === null || $status === GenerationStatus::Pending) {
            throw InvalidGenerationSnapshotException::unknownStatus($value);
        }

        return $status;
    }

    /**
     * @template TEnum of ImageSize|ImageQuality
     *
     * @param  class-string<TEnum>  $enum
     * @param  array<array-key, mixed>  $generation
     * @return TEnum
     *
     * @throws InvalidGenerationSnapshotException
     */
    private static function enum(string $enum, array $generation, string $field): ImageSize|ImageQuality
    {
        $value = self::required($generation, $field);
        $case = is_string($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            throw InvalidGenerationSnapshotException::invalidField($field, sprintf('one of %s', implode(', ', array_column($enum::cases(), 'value'))), $value);
        }

        return $case;
    }

    /**
     * Absent becomes null, which the transition refuses as a conflict.
     *
     * @param  array<array-key, mixed>  $metadata
     *
     * @throws InvalidGenerationSnapshotException
     */
    private static function generationId(array $metadata): ?string
    {
        if (! array_key_exists('generation_id', $metadata)) {
            return null;
        }

        if (! is_string($metadata['generation_id'])) {
            throw InvalidGenerationSnapshotException::invalidField('metadata.generation_id', 'a string', $metadata['generation_id']);
        }

        return $metadata['generation_id'];
    }

    /**
     * @param  array<array-key, mixed>  $generation
     * @return array{?string, ?string}
     *
     * @throws InvalidGenerationSnapshotException
     */
    private static function error(array $generation): array
    {
        $error = $generation['error'] ?? null;

        if ($error === null) {
            return [null, null];
        }

        if (! is_array($error)) {
            throw InvalidGenerationSnapshotException::invalidField('error', 'an object or null', $error);
        }

        foreach (['code', 'message'] as $key) {
            if (! array_key_exists($key, $error)) {
                throw InvalidGenerationSnapshotException::missingField('error.' . $key);
            }

            if (! is_string($error[$key])) {
                throw InvalidGenerationSnapshotException::invalidField('error.' . $key, 'a string', $error[$key]);
            }
        }

        return [self::storable('error.code', $error['code']), self::storable('error.message', $error['message'])];
    }

    /**
     * @param  array<array-key, mixed>  $generation
     *
     * @throws InvalidGenerationSnapshotException
     */
    private static function usage(array $generation): ?TokenUsage
    {
        $usage = $generation['usage'] ?? null;

        if ($usage === null) {
            return null;
        }

        if (! is_array($usage)) {
            throw InvalidGenerationSnapshotException::invalidField('usage', 'an object or null', $usage);
        }

        try {
            return TokenUsage::from($usage);
        } catch (InvalidTokenUsageException $exception) {
            throw InvalidGenerationSnapshotException::invalidUsage($exception);
        }
    }

    /**
     * Exactly the priced token types, so none is silently dropped, each a
     * decimal string as PE-001 reads it. The strings are kept as received:
     * conversion to n$ (and its whole-n$ check) happens at settlement.
     *
     * @param  array<array-key, mixed>  $generation
     * @return ?array<string, string>
     *
     * @throws InvalidGenerationSnapshotException
     */
    private static function rates(array $generation): ?array
    {
        $rates = $generation['rates'] ?? null;

        if ($rates === null) {
            return null;
        }

        if (! is_array($rates)) {
            throw InvalidGenerationSnapshotException::invalidField('rates', 'an object or null', $rates);
        }

        foreach (array_keys($rates) as $key) {
            if (! in_array($key, TokenType::keys(), true)) {
                throw InvalidGenerationSnapshotException::unpricedRate('rates.' . $key);
            }
        }

        foreach (TokenType::keys() as $key) {
            if (! array_key_exists($key, $rates)) {
                throw InvalidGenerationSnapshotException::missingField('rates.' . $key);
            }
        }

        $strings = [];

        foreach ($rates as $key => $rate) {
            if (! is_string($rate) || preg_match(TokenRates::RATE_PATTERN, $rate) !== 1) {
                throw InvalidGenerationSnapshotException::invalidField('rates.' . $key, 'a decimal string', $rate);
            }

            $strings[(string) $key] = $rate;
        }

        return $strings;
    }

    /**
     * @param  array<array-key, mixed>  $generation
     *
     * @throws InvalidGenerationSnapshotException
     */
    private static function optionalInstant(array $generation, string $field): ?CarbonImmutable
    {
        $value = $generation[$field] ?? null;

        return $value === null ? null : self::instant($field, $value);
    }

    /**
     * A date that does not exist (Feb 30) is rejected, not rolled over.
     *
     * @throws InvalidGenerationSnapshotException
     */
    private static function instant(string $field, mixed $value): CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::TIMESTAMP_PATTERN, $value) !== 1) {
            throw InvalidGenerationSnapshotException::invalidField($field, 'an ISO-8601 UTC timestamp in whole seconds', $value);
        }

        $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($instant === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw InvalidGenerationSnapshotException::invalidField($field, 'an existing date and time', $value);
        }

        return CarbonImmutable::instance($instant)->utc();
    }
}
