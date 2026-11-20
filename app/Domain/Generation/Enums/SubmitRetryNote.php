<?php

declare(strict_types=1);

namespace App\Domain\Generation\Enums;

/**
 * Why a generation that Pixelforge has not accepted yet is waiting, kept on the row
 * so History can say so after a reload. Shown only while the generation is `pending`.
 */
enum SubmitRetryNote: string
{
    case Retrying = 'retrying';

    case WaitingForSlot = 'waiting_for_slot';
}
