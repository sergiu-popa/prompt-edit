<?php

declare(strict_types=1);

namespace App\Domain\Generation\Enums;

/**
 * A generation's lifecycle. `pending` is ours (the row exists, nothing is
 * submitted yet); the rest are spelled as Pixelforge spells them. Status only
 * moves to a higher rank, and the terminal statuses never change (D-06).
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed => true,
            self::Pending, self::Queued, self::Processing => false,
        };
    }

    /**
     * What users see; a pending generation reads as queued.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending, self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
        };
    }
}
