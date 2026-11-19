<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * What a credit ledger entry records. A hold and a charge are negative and a
 * release is positive; each happens at most once per generation.
 */
enum CreditLedgerEntryType: string
{
    case Hold = 'hold';
    case Release = 'release';
    case Charge = 'charge';
}
