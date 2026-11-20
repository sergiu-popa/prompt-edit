<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * What a credit ledger entry records. A hold and a charge are negative and a
 * release is positive; each happens at most once per generation. A grant and
 * a purchase are positive top-ups, never tied to a generation and never spend
 * (D-67): reporting reads charges only.
 */
enum CreditLedgerEntryType: string
{
    case Hold = 'hold';
    case Release = 'release';
    case Charge = 'charge';
    case Grant = 'grant';
    case Purchase = 'purchase';
}
