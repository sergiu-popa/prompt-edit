<?php

declare(strict_types=1);

namespace App\Domain\Billing\QueryBuilders;

use App\Domain\Billing\Enums\CreditLedgerEntryType;
use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Domain\Billing\ValueObjects\CreditBalance;
use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use UnexpectedValueException;

/**
 * Reads of the credit ledger. `ownedBy()->balance()` is a user's balance, which
 * the `(user_id, purchase_token)` index serves by its leading column.
 *
 * @extends Builder<CreditLedgerEntry>
 */
final class CreditLedgerEntryQueryBuilder extends Builder
{
    public function ownedBy(User|int $user): self
    {
        return $this->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * One aggregate over the selected entries, with no balance column (D-67).
     * Available is the sum of every amount. Each release is exactly minus its
     * hold, so reserved is minus the sum of holds and releases, and the running
     * generations are the holds not yet released. Postgres sums a bigint as
     * numeric, which PDO returns as a string, so each sum is cast back to bigint
     * in SQL and never passes through a float.
     *
     * @throws UnexpectedValueException when the database returns a non-integer
     */
    public function balance(): CreditBalance
    {
        $hold = CreditLedgerEntryType::Hold->value;
        $release = CreditLedgerEntryType::Release->value;

        $row = $this->toBase()
            ->selectRaw(
                'coalesce(sum(amount_nanodollars), 0)::bigint as available, '
                . '(-coalesce(sum(amount_nanodollars) filter (where type in (?, ?)), 0))::bigint as reserved, '
                . '(count(*) filter (where type = ?) - count(*) filter (where type = ?))::bigint as running',
                [$hold, $release, $hold, $release],
            )
            ->first();

        return new CreditBalance(
            new Nanodollars(self::integer($row, 'available')),
            new Nanodollars(self::integer($row, 'reserved')),
            self::integer($row, 'running'),
        );
    }

    /**
     * @throws UnexpectedValueException
     */
    private static function integer(?object $row, string $column): int
    {
        $value = $row->{$column} ?? null;

        if (! is_int($value)) {
            throw new UnexpectedValueException(sprintf('The credit balance aggregate returned a non-integer %s.', $column));
        }

        return $value;
    }
}
