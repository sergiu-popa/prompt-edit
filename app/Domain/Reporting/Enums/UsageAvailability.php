<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

/**
 * Whether the report could compare its range with Pixelforge's `/v1/usage` (D-74).
 */
enum UsageAvailability: string
{
    case Available = 'available';

    /**
     * Pixelforge failed or answered unreadably: our figures are shown alone.
     */
    case Unavailable = 'unavailable';

    /**
     * Pixelforge refused the range as too long, although we never send one.
     */
    case RangeTooLong = 'range_too_long';
}
