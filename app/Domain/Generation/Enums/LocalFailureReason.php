<?php

declare(strict_types=1);

namespace App\Domain\Generation\Enums;

/**
 * Why a generation failed here, without a terminal status from Pixelforge.
 * Only ever recorded while Pixelforge has created nothing for it. Each value
 * is also a GenerationFailureCode case, which holds its copy for History; a
 * reason added here (PE-013, PE-014) gets its case and copy there too.
 */
enum LocalFailureReason: string
{
    /**
     * `400`: Pixelforge rejected the prompt.
     */
    case ContentPolicyViolation = 'content_policy_violation';

    /**
     * `422`: Pixelforge refused a param our own validation accepted.
     */
    case ValidationFailed = 'validation_failed';

    /**
     * `409`: Pixelforge holds our key with other params, which is a bug here.
     */
    case IdempotencyKeyReused = 'idempotency_key_reused';
}
