<?php

declare(strict_types=1);

namespace App\Domain\Generation\Exceptions;

use App\Domain\Generation\Enums\GenerationStatus;
use RuntimeException;

/**
 * The row changed under its lock, so the change is rolled back. The callers'
 * retry paths (webhook redelivery, job retry, the next poll) then classify it
 * against the row as it is.
 */
final class ConcurrentGenerationTransitionException extends RuntimeException
{
    public static function rowChanged(string $generationId, GenerationStatus $readStatus, GenerationStatus $incomingStatus): self
    {
        return new self(sprintf(
            'Generation %s was no longer %s when its %s transition was written; the transition was rolled back.',
            $generationId,
            $readStatus->value,
            $incomingStatus->value,
        ));
    }
}
