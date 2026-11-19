<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\CreditLedgerEntryType;
use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Domain\Billing\ValueObjects\Nanodollars;

/**
 * Holds a new generation's max user price, so a user can never spend more than
 * they had (D-14). It runs inside the caller's transaction, beside the row it
 * holds for: a row just created cannot already have a hold, so a duplicate is
 * a bug and fails loudly on the unique index.
 */
final class HoldGenerationCreditsAction
{
    public function execute(int $userId, string $generationId, Nanodollars $maxUserPrice): void
    {
        CreditLedgerEntry::query()->create([
            'user_id' => $userId,
            'generation_id' => $generationId,
            'type' => CreditLedgerEntryType::Hold,
            'amount_nanodollars' => -$maxUserPrice->amount,
        ]);
    }
}
