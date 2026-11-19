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
     * @return array{event_id: ?string}
     */
    public function context(): array
    {
        return ['event_id' => $this->eventId];
    }
}
