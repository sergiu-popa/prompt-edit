<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\Fake;

/**
 * How an accepted fake generation ends, decided at create from its keywords
 * and, when configured, from random chaos.
 */
enum FakeGenerationOutcome: string
{
    case Succeeded = 'succeeded';
    case GenerationFailed = 'generation_failed';
    case InternalError = 'internal_error';

    /**
     * The failed generation's `error`, or null when it succeeds.
     *
     * @return ?array{code: string, message: string}
     */
    public function error(): ?array
    {
        return match ($this) {
            self::Succeeded => null,
            self::GenerationFailed => ['code' => $this->value, 'message' => 'The model could not produce an image for this prompt.'],
            self::InternalError => ['code' => $this->value, 'message' => 'An internal error occurred. You were not charged; please retry.'],
        };
    }
}
