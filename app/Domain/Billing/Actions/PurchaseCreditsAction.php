<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\CreditLedgerEntryType;
use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Models\User;

/**
 * Records a fake purchase of a credit pack (D-71): no payment is taken. The Buy
 * credits page sends one token per visit, unique per user in the database, so a
 * double click or a resent request records one purchase. The first request wins,
 * even when a replay names another pack, and the replay gets the stored purchase.
 *
 * It never reuses SettleGenerationCreditsAction's writer, whose conflict-blind
 * `ON CONFLICT DO NOTHING` would swallow any other violation too (D-26).
 */
final class PurchaseCreditsAction
{
    /**
     * @return CreditLedgerEntry the purchase stored for the token, new or replayed
     */
    public function execute(User $user, Nanodollars $pack, string $purchaseToken): CreditLedgerEntry
    {
        /*
         * createOrFirst inserts under a savepoint when a transaction is open, so a
         * conflict on `(user_id, purchase_token)` rolls back only the insert and the
         * stored purchase is read instead.
         */
        return CreditLedgerEntry::query()->createOrFirst(
            ['user_id' => $user->getKey(), 'purchase_token' => $purchaseToken],
            ['type' => CreditLedgerEntryType::Purchase, 'amount_nanodollars' => $pack->amount],
        );
    }
}
