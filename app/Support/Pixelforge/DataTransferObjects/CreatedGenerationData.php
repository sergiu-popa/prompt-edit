<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\DataTransferObjects;

/**
 * Pixelforge's answer to a create: the generation as it is now, and whether
 * it was replayed for an idempotency key it had already seen. A replayed
 * generation may have moved on, even to a terminal status.
 */
final readonly class CreatedGenerationData
{
    public function __construct(
        public GenerationSnapshot $snapshot,
        public bool $replayed,
    ) {}
}
