<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DataTransferObjects;

use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Reporting\ValueObjects\ReportRange;

/**
 * The operator report for one range (D-64): every UTC date of it, newest first and keyed
 * by date, so PE-018 can add Pixelforge's own figures beside ours; the users with a billed
 * generation, by spend; and the totals, which are the sum of the days.
 */
final readonly class OperatorReportData
{
    /**
     * @param  array<string, ReportFigures>  $days  every date of the range, newest first
     * @param  list<ReportUserFigures>  $users  by spend descending, then email
     */
    public function __construct(
        public ReportRange $range,
        public array $days,
        public array $users,
    ) {}

    /**
     * @throws NanodollarOverflowException
     */
    public function totals(): ReportFigures
    {
        return array_reduce(
            $this->days,
            fn (ReportFigures $total, ReportFigures $day): ReportFigures => $total->plus($day),
            ReportFigures::zero(),
        );
    }

    /**
     * @return array{
     *     days: list<array{date: string, succeeded: int, spend_nanodollars: int, provider_cost_nanodollars: int, profit_nanodollars: int}>,
     *     users: list<array{user_id: int, email: string, succeeded: int, spend_nanodollars: int, provider_cost_nanodollars: int, profit_nanodollars: int}>,
     *     totals: array{succeeded: int, spend_nanodollars: int, provider_cost_nanodollars: int, profit_nanodollars: int},
     * }
     *
     * @throws NanodollarOverflowException
     */
    public function toArray(): array
    {
        $days = [];

        foreach ($this->days as $date => $figures) {
            $days[] = ['date' => (string) $date, ...$figures->toArray()];
        }

        return [
            'days' => $days,
            'users' => array_map(fn (ReportUserFigures $user): array => $user->toArray(), $this->users),
            'totals' => $this->totals()->toArray(),
        ];
    }
}
