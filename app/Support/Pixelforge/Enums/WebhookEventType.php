<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Enums;

use App\Domain\Generation\Enums\GenerationStatus;

/**
 * The webhook event types Pixelforge documents. There is no `queued` event.
 */
enum WebhookEventType: string
{
    case GenerationProcessing = 'generation.processing';
    case GenerationSucceeded = 'generation.succeeded';
    case GenerationFailed = 'generation.failed';
    case Ping = 'ping';

    /**
     * The status a generation event announces; null for a ping, which
     * concerns no generation.
     */
    public function generationStatus(): ?GenerationStatus
    {
        return match ($this) {
            self::GenerationProcessing => GenerationStatus::Processing,
            self::GenerationSucceeded => GenerationStatus::Succeeded,
            self::GenerationFailed => GenerationStatus::Failed,
            self::Ping => null,
        };
    }
}
