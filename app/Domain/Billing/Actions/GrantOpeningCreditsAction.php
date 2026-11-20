<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\CreditLedgerEntryType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The one-time opening grant for accounts created before balances (D-70): each
 * user without a grant gets the starter plus whatever their settled charges
 * took, so they start where a new account starts, minus their active holds,
 * which settle or release as usual.
 *
 * It is one `INSERT … SELECT`, whose snapshot defines go-live: a charge settled
 * before it is forgiven, and a hold still active at it settles normally after.
 * A conflict on the one-grant index, the only index a grant can meet, is
 * ignored, so a re-run or a concurrent registration never fails it and every
 * run after go-live finds nobody. Locally failed generations Pixelforge billed
 * (D-09) have no charge entry, so they add nothing.
 */
final class GrantOpeningCreditsAction
{
    /**
     * @return int the number of users granted
     */
    public function execute(): int
    {
        $grant = CreditLedgerEntryType::Grant->value;

        return DB::affectingStatement(<<<'SQL'
            insert into credit_ledger_entries (user_id, type, amount_nanodollars, created_at)
            select users.id, ?, (?::bigint - coalesce((
                select sum(charges.amount_nanodollars)
                from credit_ledger_entries charges
                where charges.user_id = users.id and charges.type = ?
            ), 0))::bigint, ?
            from users
            where not exists (
                select 1 from credit_ledger_entries grants
                where grants.user_id = users.id and grants.type = ?
            )
            on conflict (user_id) where type = 'grant' do nothing
            SQL, [
            $grant,
            config()->integer('credits.starter_grant_nanodollars'),
            CreditLedgerEntryType::Charge->value,
            CarbonImmutable::now()->format('Y-m-d H:i:sP'),
            $grant,
        ]);
    }
}
