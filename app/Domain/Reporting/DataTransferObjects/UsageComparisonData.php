<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DataTransferObjects;

use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Reporting\Enums\UsageAvailability;
use App\Support\Pixelforge\DataTransferObjects\UsageReportData;
use UnexpectedValueException;

/**
 * The report's reconciliation with Pixelforge's `/v1/usage` (D-72): each UTC day of the
 * range, newest first, and the total. Today's figures can still move, so today is in
 * progress.
 */
final readonly class UsageComparisonData
{
    /**
     * @param  string  $today  now's UTC date
     * @param  array<string, UsageComparisonLine>  $days  every date of the range, newest first
     */
    private function __construct(
        public UsageAvailability $availability,
        public string $today,
        public array $days,
        public UsageComparisonLine $total,
    ) {}

    /**
     * Each recorded date beside Pixelforge's row for it. The total compares our summed days
     * with Pixelforge's own `total`, not with the sum of its rows.
     *
     * @param  array<string, UsageFigures>  $recorded  every date of the range, newest first
     *
     * @throws NanodollarOverflowException
     * @throws UnexpectedValueException when Pixelforge's report is for another range
     */
    public static function compared(string $today, array $recorded, UsageReportData $usage): self
    {
        $days = [];

        foreach ($recorded as $date => $figures) {
            $row = $usage->days[$date] ?? throw new UnexpectedValueException("Pixelforge's usage report has no row for {$date}.");
            $days[$date] = new UsageComparisonLine($figures, UsageFigures::fromPixelforge($row));
        }

        if (count($days) !== count($usage->days)) {
            throw new UnexpectedValueException('Pixelforge\'s usage report covers another range.');
        }

        return new self(
            UsageAvailability::Available,
            $today,
            $days,
            new UsageComparisonLine(self::sum($recorded), UsageFigures::fromPixelforge($usage->total)),
        );
    }

    /**
     * Our figures alone, when Pixelforge's are missing.
     *
     * @param  array<string, UsageFigures>  $recorded  every date of the range, newest first
     *
     * @throws NanodollarOverflowException
     */
    public static function withoutPixelforge(string $today, array $recorded, UsageAvailability $availability): self
    {
        return new self(
            $availability,
            $today,
            array_map(fn (UsageFigures $figures): UsageComparisonLine => new UsageComparisonLine($figures, null), $recorded),
            new UsageComparisonLine(self::sum($recorded), null),
        );
    }

    /**
     * Whether any day or the total is a Mismatch; never without Pixelforge's figures.
     */
    public function hasMismatch(): bool
    {
        foreach ([...array_values($this->days), $this->total] as $line) {
            if ($line->matches() === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every amount is integer n$ (D-03), every flag a bool or null.
     *
     * @return array{availability: string, has_mismatch: bool, days: list<array<string, mixed>>, total: array<string, mixed>}
     *
     * @throws NanodollarOverflowException
     */
    public function toArray(): array
    {
        $days = [];

        foreach ($this->days as $date => $line) {
            $days[] = ['date' => $date, 'in_progress' => $date === $this->today, ...$line->toArray()];
        }

        return [
            'availability' => $this->availability->value,
            'has_mismatch' => $this->hasMismatch(),
            'days' => $days,
            'total' => $this->total->toArray(),
        ];
    }

    /**
     * @param  array<string, UsageFigures>  $figures
     *
     * @throws NanodollarOverflowException
     */
    private static function sum(array $figures): UsageFigures
    {
        return array_reduce($figures, fn (UsageFigures $sum, UsageFigures $next): UsageFigures => $sum->plus($next), UsageFigures::zero());
    }
}
