<?php

declare(strict_types=1);

namespace App\Domain\Generation\DataTransferObjects;

use App\Domain\Generation\Enums\LocalFailureReason;

/**
 * A failure decided here, with no provider snapshot: for example a
 * `400 content_policy_violation` at submit, when Pixelforge created nothing.
 * The error fields carry Pixelforge's error envelope when there is one.
 */
final readonly class LocalGenerationFailureData
{
    public function __construct(
        public LocalFailureReason $reason,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?string $errorParam,
    ) {}
}
