<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\CreditLedgerEntryType;
use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Models\User;

/**
 * Grants a new account its starter credit (D-70). Every creation path calls it in
 * the transaction that creates the user, so an account never exists without its
 * grant. The one-grant-per-user index makes a second call add nothing, so a path
 * that runs again (a seeder, a replayed registration) is safe. The amount is read
 * at call time, because Fortify keeps its user creator as a singleton.
 */
final class GrantStarterCreditsAction
{
    public function execute(User $user): void
    {
        /*
         * Inside a transaction, createOrFirst inserts under a savepoint, so a
         * conflict rolls back only the insert and the stored grant is read instead.
         */
        CreditLedgerEntry::query()->createOrFirst(
            ['user_id' => $user->getKey(), 'type' => CreditLedgerEntryType::Grant],
            ['amount_nanodollars' => config()->integer('credits.starter_grant_nanodollars')],
        );
    }
}
