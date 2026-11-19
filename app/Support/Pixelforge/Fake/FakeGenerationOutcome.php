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
}
