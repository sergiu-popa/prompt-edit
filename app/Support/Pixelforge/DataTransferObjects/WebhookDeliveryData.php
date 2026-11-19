<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\DataTransferObjects;

use App\Support\Pixelforge\Enums\WebhookEventType;
use App\Support\Pixelforge\Exceptions\InvalidGenerationSnapshotException;
use App\Support\Pixelforge\Exceptions\InvalidWebhookDeliveryException;
use JsonException;

/**
 * One verified webhook delivery: the signed envelope `{id, object: event,
 * type, created_at, data: {object}}`, read from the exact raw bytes, plus the
 * unsigned headers, which are informational only. Unknown fields are ignored.
 */
final readonly class WebhookDeliveryData
{
    /**
     * The unsigned delivery headers. They only inform: the signed body wins.
     */
    public const string EVENT_ID_HEADER = 'Pixelforge-Event-Id';

    public const string EVENT_TYPE_HEADER = 'Pixelforge-Event-Type';

    public const string DELIVERY_ATTEMPT_HEADER = 'Pixelforge-Delivery-Attempt';

    /**
     * Postgres `integer`, so a stored attempt can never raise a DB error.
     */
    private const int MAX_DELIVERY_ATTEMPT = 2_147_483_647;

    /**
     * The `string` columns the id and type are stored in, so also the most of
     * an unsigned header value worth logging.
     */
    public const int MAX_FIELD_LENGTH = 255;

    private function __construct(
        public string $eventId,
        public string $type,
        public ?WebhookEventType $knownType,
        public ?GenerationSnapshot $snapshot,
        public ?int $deliveryAttempt,
        public ?string $headerEventId,
        public ?string $headerEventType,
    ) {}

    /**
     * @throws InvalidWebhookDeliveryException
     */
    public static function from(string $payload, ?string $headerEventId, ?string $headerEventType, ?string $headerDeliveryAttempt): self
    {
        try {
            $envelope = json_decode($payload, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidWebhookDeliveryException::notJson($exception);
        }

        if (! is_array($envelope) || ($envelope !== [] && array_is_list($envelope))) {
            throw InvalidWebhookDeliveryException::notAnObject();
        }

        $eventId = self::storableString($envelope, 'id', null);
        $type = self::storableString($envelope, 'type', $eventId);
        $knownType = WebhookEventType::tryFrom($type);

        return new self(
            $eventId,
            $type,
            $knownType,
            $knownType?->generationStatus() === null ? null : self::snapshot($envelope, $eventId),
            self::deliveryAttempt($headerDeliveryAttempt),
            $headerEventId,
            $headerEventType,
        );
    }

    /**
     * A non-empty string that fits its column. pdo_pgsql cuts a string at a
     * NUL byte, so two different ids could otherwise be stored as one.
     *
     * @param  array<array-key, mixed>  $envelope
     *
     * @throws InvalidWebhookDeliveryException
     */
    private static function storableString(array $envelope, string $field, ?string $eventId): string
    {
        $value = $envelope[$field] ?? null;

        if (! is_string($value) || $value === '' || mb_strlen($value) > self::MAX_FIELD_LENGTH || str_contains($value, "\0")) {
            throw InvalidWebhookDeliveryException::invalidField(
                $field,
                sprintf('a non-empty string of at most %d characters without a NUL byte', self::MAX_FIELD_LENGTH),
                $eventId,
            );
        }

        return $value;
    }

    /**
     * A generation event carries its generation as `data.object`. A ping or an
     * unknown type is never read past its envelope.
     *
     * @param  array<array-key, mixed>  $envelope
     *
     * @throws InvalidWebhookDeliveryException
     */
    private static function snapshot(array $envelope, string $eventId): GenerationSnapshot
    {
        $data = $envelope['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;

        if (! is_array($object)) {
            throw InvalidWebhookDeliveryException::missingObject($eventId);
        }

        try {
            return GenerationSnapshot::from($object);
        } catch (InvalidGenerationSnapshotException $exception) {
            $metadata = $object['metadata'] ?? null;
            $generationId = is_array($metadata) && is_string($metadata['generation_id'] ?? null) ? $metadata['generation_id'] : null;

            throw InvalidWebhookDeliveryException::unreadableSnapshot($eventId, $generationId, $exception);
        }
    }

    /**
     * Anything but a whole number from 1 that fits the column is dropped,
     * because the header is not signed and only informs.
     */
    private static function deliveryAttempt(?string $header): ?int
    {
        if ($header === null || preg_match('/^[1-9]\d{0,9}\z/', $header) !== 1) {
            return null;
        }

        $attempt = (int) $header;

        return $attempt <= self::MAX_DELIVERY_ATTEMPT ? $attempt : null;
    }
}
