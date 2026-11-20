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

    /**
     * `503 overloaded` past the overload deadline: nothing was created (AC 11).
     */
    case ProviderOverloaded = 'provider_overloaded';

    /**
     * Rate-limit, budget or concurrency waits that outlasted their bound (AC 7, 17).
     */
    case ProviderBusy = 'provider_busy';

    /**
     * The key was refused, or no request could ever be sent (AC 14, 17).
     */
    case ServiceUnavailable = 'service_unavailable';

    /**
     * `429 daily_limit_exceeded`: the account's 500 a UTC day are used (AC 8).
     */
    case DailyLimit = 'daily_limit';

    /**
     * Given up by the reconciler: still unconfirmed by Pixelforge a day after
     * creation (D-49). Only the reconciler records it, never the submit path.
     */
    case Lost = 'lost';
}
