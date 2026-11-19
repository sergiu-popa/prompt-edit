<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\CreditLedgerEntryType;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Domain\Billing\ValueObjects\Nanodollars;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Support\Facades\Log;

/**
 * Ends a generation's hold once it is terminal (D-14): the hold is released in
 * full and, when it succeeded, the actual user charge is written, even above
 * the hold. It opens no transaction: it runs inside the transition's, under
 * its row lock, so the entries commit or roll back with the status change.
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

        $heldAmount = Nanodollars::zero()->minus(new Nanodollars($hold->amount_nanodollars));

        $this->write($hold, CreditLedgerEntryType::Release, $heldAmount);

        if ($userCharge === null) {
            return;
        }

        $this->write($hold, CreditLedgerEntryType::Charge, Nanodollars::zero()->minus($userCharge));

        if ($userCharge->compareTo($heldAmount) > 0) {
            $this->warnAboveHold($generationId, $heldAmount, $userCharge);
        }
    }

    /**
     * The rates can change between the estimate and creation (requirements
     * §6), so the actual is charged in full; the operator sees each case.
     */
    private function warnAboveHold(string $generationId, Nanodollars $heldAmount, Nanodollars $userCharge): void
    {
        Log::warning('A generation\'s actual charge exceeded its hold; the full charge was recorded.', [
            'generation_id' => $generationId,
            'hold_nanodollars' => $heldAmount->amount,
            'charge_nanodollars' => $userCharge->amount,
        ]);
    }

    /**
     * The entry belongs to the hold's owner, so a settlement can never move
     * another user's credit.
     *
     * `ON CONFLICT DO NOTHING` (D-26): a unique violation would abort the
     * whole Postgres transaction and roll the status change back with it. The
     * status guard makes a duplicate impossible in normal operation, so an
     * ignored insert means a guard was bypassed and is logged for the operator.
     */
    private function write(CreditLedgerEntry $hold, CreditLedgerEntryType $type, Nanodollars $amount): void
    {
        $inserted = CreditLedgerEntry::query()->fillAndInsertOrIgnore([[
            'user_id' => $hold->user_id,
            'generation_id' => $hold->generation_id,
            'type' => $type,
            'amount_nanodollars' => $amount->amount,
        ]]);

        if ($inserted === 0) {
            Log::error('Ignored a duplicate credit ledger entry: its generation already has one of this type.', [
                'generation_id' => $hold->generation_id,
                'type' => $type->value,
            ]);
        }
    }
}
