<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Generation\Models\Generation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A generation's credit ledger entries as stored, read without the model so
 * every amount is the exact integer in the row.
 *
 * @phpstan-require-extends TestCase
 */
trait InteractsWithCreditLedger
{
    /**
     * @return list<array{string, int}> each entry's type and signed amount, in write order
     */
    protected function ledgerEntriesFor(Generation $generation): array
    {
        return array_values(DB::table('credit_ledger_entries')
            ->where('generation_id', $generation->getKey())
            ->orderBy('id')
            ->get(['type', 'amount_nanodollars'])
            ->map(function (object $entry): array {
                $this->assertIsString($entry->type);
                $this->assertIsInt($entry->amount_nanodollars);

                return [$entry->type, $entry->amount_nanodollars];
            })
            ->all());
    }

    /**
     * Postgres sums a bigint as numeric, which PDO returns as a string, so the
     * sum is cast back to bigint in SQL and never passes through a float.
     */
    protected function ledgerNetFor(Generation $generation): int
    {
        $net = DB::scalar(
            'select coalesce(sum(amount_nanodollars), 0)::bigint from credit_ledger_entries where generation_id = ?',
            [$generation->getKey()],
        );

        $this->assertIsInt($net);

        return $net;
    }
}
