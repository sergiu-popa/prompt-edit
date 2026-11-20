<?php

declare(strict_types=1);

namespace App\Domain\Reporting\ValueObjects;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The operator report's range: UTC calendar dates, both inclusive, at most `MAX_DAYS`
 * long, by default the last `DEFAULT_DAYS` ending today (D-65). These are `/v1/usage`'s own
 * limit and default, so PE-018 can ask Pixelforge for the very same range. Dates are read
 * as UTC midnights, never through PHP's or the app's timezone.
 */
final readonly class ReportRange
{
    public const int MAX_DAYS = 92;

    public const int DEFAULT_DAYS = 30;

    private const string DATE_FORMAT = 'Y-m-d';

    private const int SECONDS_PER_DAY = 86_400;

    /**
     * @param  CarbonImmutable  $start  the first date's UTC midnight
     * @param  CarbonImmutable  $end  the last date's UTC midnight
     */
    private function __construct(
        private CarbonImmutable $start,
        private CarbonImmutable $end,
    ) {}

    /**
     * The `DEFAULT_DAYS` UTC dates ending on now's UTC date.
     */
    public static function defaultFor(CarbonImmutable $now): self
    {
        $end = $now->utc()->startOfDay();

        return new self($end->subDays(self::DEFAULT_DAYS - 1), $end);
    }

    /**
     * @throws InvalidArgumentException when a date isn't a strict `Y-m-d` date, the start is
     *                                  after the end, or the range is longer than `MAX_DAYS`
     */
    public static function fromDates(string $startDate, string $endDate): self
    {
        $start = self::parse($startDate);
        $end = self::parse($endDate);
        $days = self::daysBetween($start, $end);

        if ($days < 1) {
            throw new InvalidArgumentException("The report range starts after it ends: {$startDate} to {$endDate}.");
        }

        if ($days > self::MAX_DAYS) {
            throw new InvalidArgumentException("The report range spans {$days} days, more than " . self::MAX_DAYS . '.');
        }

        return new self($start, $end);
    }

    /**
     * A strict `Y-m-d` calendar date: zero-padded, nothing around it, and a real day.
     */
    public static function isDate(string $date): bool
    {
        return self::tryParse($date) !== null;
    }

    /**
     * How many dates the range from the start to the end holds, both inclusive; 0 or less
     * when the start is after the end.
     *
     * @throws InvalidArgumentException when a date isn't a strict `Y-m-d` date
     */
    public static function inclusiveDayCount(string $startDate, string $endDate): int
    {
        return self::daysBetween(self::parse($startDate), self::parse($endDate));
    }

    public function startDate(): string
    {
        return $this->start->format(self::DATE_FORMAT);
    }

    public function endDate(): string
    {
        return $this->end->format(self::DATE_FORMAT);
    }

    /**
     * The start's UTC midnight: the first instant in the range.
     */
    public function startsAt(): CarbonImmutable
    {
        return $this->start;
    }

    /**
     * The UTC midnight after the end: the first instant past the range.
     */
    public function endsBefore(): CarbonImmutable
    {
        return $this->end->addDay();
    }

    /**
     * Every date of the range, newest first.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = [];

        for ($date = $this->end; $date->greaterThanOrEqualTo($this->start); $date = $date->subDay()) {
            $dates[] = $date->format(self::DATE_FORMAT);
        }

        return $dates;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function parse(string $date): CarbonImmutable
    {
        return self::tryParse($date) ?? throw new InvalidArgumentException("Not a Y-m-d date: {$date}.");
    }

    /**
     * The date's UTC midnight, or null. PHP's own parser, unlike Carbon's strict mode, returns
     * false instead of throwing, and it rolls 2026-02-30 over to March, so the round trip
     * through the format rejects an impossible or unpadded date.
     */
    private static function tryParse(string $date): ?CarbonImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $date, new DateTimeZone('UTC'));

        if ($parsed === false || $parsed->format(self::DATE_FORMAT) !== $date) {
            return null;
        }

        return CarbonImmutable::instance($parsed);
    }

    /**
     * UTC days have no DST, so whole days are exact seconds.
     */
    private static function daysBetween(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return intdiv($end->getTimestamp() - $start->getTimestamp(), self::SECONDS_PER_DAY) + 1;
    }
}
