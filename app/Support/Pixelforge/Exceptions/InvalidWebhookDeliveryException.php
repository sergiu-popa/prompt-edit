<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Exceptions;

use InvalidArgumentException;
use JsonException;

/**
 * A validly signed webhook body that cannot be read. Messages name the field
 * at fault and never echo the body.
 */
final class InvalidWebhookDeliveryException extends InvalidArgumentException
{
    private ?string $eventId = null;

    private ?string $generationId = null;

    public static function notJson(JsonException $previous): self
    {
        return new self(sprintf('The Pixelforge webhook body is not valid JSON: %s.', $previous->getMessage()), previous: $previous);
    }

    public static function notAnObject(): self
    {
        return new self('The Pixelforge webhook body must be a JSON object.');
    }

    public static function invalidField(string $path, string $expected, ?string $eventId = null): self
    {
        $exception = new self(sprintf('The Pixelforge webhook\'s %s must be %s.', $path, $expected));
        $exception->eventId = $eventId;

        return $exception;
    }

    public static function missingObject(string $eventId): self
    {
        $exception = new self('A Pixelforge generation event must carry its generation as data.object.');
        $exception->eventId = $eventId;

        return $exception;
    }

    /**
     * The snapshot's own reason is kept: it names the field at fault and at
     * most that one field's value.
     */
    public static function unreadableSnapshot(string $eventId, ?string $generationId, InvalidGenerationSnapshotException $previous): self
    {
        $exception = new self(sprintf('The Pixelforge webhook\'s data.object cannot be read: %s', $previous->getMessage()), previous: $previous);
        $exception->eventId = $eventId;
        $exception->generationId = $generationId;

        return $exception;
    }

    /**
     * @return array{event_id: ?string, generation_id: ?string}
     */
    public function context(): array
    {
        return ['event_id' => $this->eventId, 'generation_id' => $this->generationId];
    }
}
