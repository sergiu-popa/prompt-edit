<?php

declare(strict_types=1);

namespace App\Domain\Generation\Enums;

/**
 * Why a generation failed here, without a terminal status from Pixelforge.
 * Only ever recorded while Pixelforge has created nothing for it.
 */
enum LocalFailureReason: string
{
    case ContentPolicyViolation = 'content_policy_violation';
}
