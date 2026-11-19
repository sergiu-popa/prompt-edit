<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\CreditLedgerEntryType;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Domain\Billing\ValueObjects\Nanodollars;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;

/**
 * Ends a generation's hold once it is terminal (D-14): the hold is released in
 * full and, when it succeeded, the actual user charge is written. It opens no
 * transaction: it runs inside the transition's, under its row lock, so the
 * entries commit or roll back with the status change.
 */
final class SettleGenerationCreditsAction
{
    /**
     * @param  ?Nanodollars  $userCharge  the actual charge, or null when the generation failed
     *
     * @throws ModelNotFoundException<CreditLedgerEntry> when the generation has no hold
     * @throws MultipleRecordsFoundException
     * @throws NanodollarOverflowException
     */
    public function execute(string $generationId, ?Nanodollars $userCharge): void
    {
        $hold = CreditLedgerEntry::query()
            ->where('generation_id', $generationId)
            ->where('type', CreditLedgerEntryType::Hold)
            ->sole();

        $this->write($hold, CreditLedgerEntryType::Release, Nanodollars::zero()->minus(new Nanodollars($hold->amount_nanodollars)));

        if ($userCharge === null) {
            return;
        }

        $this->write($hold, CreditLedgerEntryType::Charge, Nanodollars::zero()->minus($userCharge));
    }

    /**
     * The entry belongs to the hold's owner, so a settlement can never move
     * another user's credit.
     */
    private function write(CreditLedgerEntry $hold, CreditLedgerEntryType $type, Nanodollars $amount): void
    {
        CreditLedgerEntry::query()->create([
            'user_id' => $hold->user_id,
            'generation_id' => $hold->generation_id,
            'type' => $type,
            'amount_nanodollars' => $amount->amount,
        ]);
    }
}
