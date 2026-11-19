<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * The image qualities Pixelforge accepts, as sent in a generation's `quality`.
 */
enum ImageQuality: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
