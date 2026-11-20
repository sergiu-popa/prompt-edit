<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Domain\Billing\ValueObjects\CreditBalance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use UnexpectedValueException;

/**
 * Locks the user's row for the caller's transaction and reads their balance under the
 * lock (D-68). Every create takes this lock before it checks and holds, so two tabs
 * serialize on the row and the second reads the first's hold. Outside a transaction
 * the lock would end with the statement, so it refuses to run there.
 *
 * The lock is `FOR NO KEY UPDATE`: it conflicts with itself, which is all two creates
 * need, but not with the `FOR KEY SHARE` lock every insert referencing `users.id` takes.
 * So a webhook or reconciler settling another of the user's generations never waits
 * on an open create.
 */
final class LockCreditBalanceAction
{
    /**
     * @throws LogicException outside a transaction
     * @throws UnexpectedValueException
     */
    public function execute(User $user): CreditBalance
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The credit balance can only be locked inside a transaction.');
        }

        User::query()->whereKey($user->getKey())->lock('for no key update')->value('id');

        return CreditLedgerEntry::query()->ownedBy($user)->balance();
    }
}
