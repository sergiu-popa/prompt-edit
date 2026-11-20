<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Actions;

use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Domain\Generation\Models\Generation;
use App\Domain\Reporting\DataTransferObjects\UsageFigures;
use App\Domain\Reporting\ValueObjects\ReportRange;
use UnexpectedValueException;

/**
 * What we recorded for the reconciliation (D-72): each generation Pixelforge billed, D-09's
 * provider-cost-only rows included, with its own provider cost and usage, on the UTC date of
 * its `completed_at`. It is a sibling of ShowOperatorReportAction's query, with the same
 * scopes and the same date expression, so a difference can only come from the data.
 */
final class SumRecordedUsageAction
{
    /**
     * Every date of the range, newest first, zero-filled.
     *
     * @return array<string, UsageFigures>
     *
     * @throws NanodollarOverflowException
     * @throws UnexpectedValueException when the database returns a figure that isn't an int
     */
    public function execute(ReportRange $range): array
    {
        $days = array_fill_keys($range->dates(), UsageFigures::zero());

        foreach ($this->rows($range) as $row) {
            $date = is_string($row->report_date) ? $row->report_date : throw new UnexpectedValueException('A usage row\'s date must be a string, got ' . get_debug_type($row->report_date) . '.');

            $days[$date] = ($days[$date] ?? throw new UnexpectedValueException("A usage row's date {$date} is outside the range."))->plus(new UsageFigures(
                self::integer($row->generations),
                new Nanodollars(self::integer($row->provider_cost_nanodollars)),
                self::integer($row->input_text_tokens),
                self::integer($row->input_image_tokens),
                self::integer($row->output_tokens),
                self::integer($row->reasoning_tokens),
            ));
        }

        return $days;
    }

    /**
     * One row per UTC date. Postgres sums a bigint as numeric, which PDO returns as a
     * string, so every sum is cast back to bigint.
     *
     * @return list<object{report_date: mixed, generations: mixed, provider_cost_nanodollars: mixed, input_text_tokens: mixed, input_image_tokens: mixed, output_tokens: mixed, reasoning_tokens: mixed}>
     */
    private function rows(ReportRange $range): array
    {
        $query = Generation::query()
            ->billedByPixelforge()
            ->completedBetween($range->startsAt(), $range->endsBefore())
            ->toBase()
            ->selectRaw(ShowOperatorReportAction::UTC_DATE . ' as report_date')
            ->selectRaw('count(*)::bigint as generations');

        foreach (['provider_cost_nanodollars', 'input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $column) {
            $query->selectRaw("coalesce(sum(generations.{$column}), 0)::bigint as {$column}");
        }

        /** @var list<object{report_date: mixed, generations: mixed, provider_cost_nanodollars: mixed, input_text_tokens: mixed, input_image_tokens: mixed, output_tokens: mixed, reasoning_tokens: mixed}> $rows */
        $rows = $query->groupByRaw(ShowOperatorReportAction::UTC_DATE)->get()->all();

        return $rows;
    }

    /**
     * A non-int figure is a bug, never something to round.
     *
     * @throws UnexpectedValueException
     */
    private static function integer(mixed $value): int
    {
        return is_int($value) ? $value : throw new UnexpectedValueException('A usage figure must be an int, got ' . get_debug_type($value) . '.');
    }
}
