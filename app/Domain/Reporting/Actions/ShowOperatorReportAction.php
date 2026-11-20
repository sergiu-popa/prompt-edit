<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Actions;

use App\Domain\Billing\Enums\CreditLedgerEntryType;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Domain\Generation\Models\Generation;
use App\Domain\Reporting\DataTransferObjects\OperatorReportData;
use App\Domain\Reporting\DataTransferObjects\ReportFigures;
use App\Domain\Reporting\DataTransferObjects\ReportUserFigures;
use App\Domain\Reporting\ValueObjects\ReportRange;
use Illuminate\Database\Query\JoinClause;
use UnexpectedValueException;

/**
 * The operator report for a range (D-64), from one aggregate query over the generations
 * Pixelforge billed, D-09's provider-cost-only rows included:
 *
 * - each counts on the UTC date of Pixelforge's `completed_at`, converted to UTC in SQL, so
 *   neither the session's nor the app's timezone moves a day (D-10);
 * - spend is the negated user `charge` entry of each, the ledger being its source (D-08).
 *   Holds, releases and any other type, PE-017's grants and purchases too, are never read;
 * - provider cost is the generation's own.
 *
 * The rows, grouped by day and user, fold into zero-filled days and per-user totals, so the
 * days, the users and the overall totals agree by construction.
 */
final class ShowOperatorReportAction
{
    /**
     * `AT TIME ZONE 'UTC'` turns the `timestamptz` into its UTC wall time, whatever the
     * session's timezone; `date_trunc` or a `::date` cast would follow the session.
     * SumRecordedUsageAction buckets by it too, so both put a generation on the same day.
     */
    public const string UTC_DATE = "to_char(generations.completed_at at time zone 'UTC', 'YYYY-MM-DD')";

    /**
     * @throws NanodollarOverflowException
     * @throws UnexpectedValueException when the database returns a figure that isn't an int
     */
    public function execute(ReportRange $range): OperatorReportData
    {
        $days = array_fill_keys($range->dates(), ReportFigures::zero());
        /** @var array<int, ReportUserFigures> $users */
        $users = [];

        foreach ($this->rows($range) as $row) {
            $date = self::string($row->report_date);
            $userId = self::integer($row->user_id);
            $figures = new ReportFigures(
                self::integer($row->succeeded),
                new Nanodollars(self::integer($row->spend_nanodollars)),
                new Nanodollars(self::integer($row->provider_cost_nanodollars)),
            );

            $days[$date] = ($days[$date] ?? throw new UnexpectedValueException("A report row's date {$date} is outside the range."))->plus($figures);
            $users[$userId] = new ReportUserFigures(
                $userId,
                self::string($row->email),
                ($users[$userId] ?? null)?->figures->plus($figures) ?? $figures,
            );
        }

        return new OperatorReportData($range, $days, self::bySpend(array_values($users)));
    }

    /**
     * One row per UTC date and user. Postgres sums a bigint as numeric, which PDO returns as a
     * string, so every sum is cast back to bigint.
     *
     * @return list<object{report_date: mixed, user_id: mixed, email: mixed, succeeded: mixed, spend_nanodollars: mixed, provider_cost_nanodollars: mixed}>
     */
    private function rows(ReportRange $range): array
    {
        /** @var list<object{report_date: mixed, user_id: mixed, email: mixed, succeeded: mixed, spend_nanodollars: mixed, provider_cost_nanodollars: mixed}> $rows */
        $rows = Generation::query()
            ->billedByPixelforge()
            ->completedBetween($range->startsAt(), $range->endsBefore())
            ->leftJoin('credit_ledger_entries as charges', fn (JoinClause $join): JoinClause => $join
                ->on('charges.generation_id', '=', 'generations.id')
                ->where('charges.type', '=', CreditLedgerEntryType::Charge->value))
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->toBase()
            ->selectRaw(self::UTC_DATE . ' as report_date')
            ->addSelect('generations.user_id', 'users.email')
            ->selectRaw('count(*)::bigint as succeeded')
            ->selectRaw('coalesce(-sum(charges.amount_nanodollars), 0)::bigint as spend_nanodollars')
            ->selectRaw('coalesce(sum(generations.provider_cost_nanodollars), 0)::bigint as provider_cost_nanodollars')
            ->groupByRaw(self::UTC_DATE . ', generations.user_id, users.email')
            ->get()
            ->all();

        return $rows;
    }

    /**
     * @param  list<ReportUserFigures>  $users
     * @return list<ReportUserFigures>
     */
    private static function bySpend(array $users): array
    {
        usort($users, fn (ReportUserFigures $a, ReportUserFigures $b): int => $b->figures->spend->compareTo($a->figures->spend)
            ?: strcmp($a->email, $b->email));

        return $users;
    }

    /**
     * A non-int figure is a bug, never something to round.
     *
     * @throws UnexpectedValueException
     */
    private static function integer(mixed $value): int
    {
        return is_int($value) ? $value : throw new UnexpectedValueException('A report figure must be an int, got ' . get_debug_type($value) . '.');
    }

    /**
     * @throws UnexpectedValueException
     */
    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new UnexpectedValueException('A report label must be a string, got ' . get_debug_type($value) . '.');
    }
}
