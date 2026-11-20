<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Reporting\ValueObjects;

use App\Domain\Reporting\ValueObjects\ReportRange;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The report's range is UTC dates, inclusive, at most 92 days (D-65), whatever PHP's
 * default timezone is. No database and no app are needed.
 */
final class ReportRangeTest extends TestCase
{
    private string $defaultTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);

        parent::tearDown();
    }

    /**
     * AC 5: the last 30 UTC days ending today, inclusive.
     */
    #[DataProvider('instantsOnTheEighteenth')]
    public function test_the_default_range_is_the_last_30_utc_days_ending_today(string $now, string $phpTimezone): void
    {
        date_default_timezone_set($phpTimezone);

        $range = ReportRange::defaultFor(CarbonImmutable::parse($now));

        $this->assertSame('2026-10-20', $range->startDate());
        $this->assertSame('2026-11-18', $range->endDate());
        $this->assertCount(ReportRange::DEFAULT_DAYS, $range->dates());
        $this->assertSame('2026-11-18', $range->dates()[0]);
        $this->assertSame('2026-11-17', $range->dates()[1]);
        $this->assertSame('2026-10-20', $range->dates()[29]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function instantsOnTheEighteenth(): array
    {
        return [
            'noon, UTC' => ['2026-11-18T12:00:00Z', 'UTC'],
            'noon, PHP in Pacific/Kiritimati' => ['2026-11-18T12:00:00Z', 'Pacific/Kiritimati'],
            'midnight, PHP in Pacific/Kiritimati' => ['2026-11-18T00:00:00Z', 'Pacific/Kiritimati'],
            'the last second, PHP in Pacific/Kiritimati' => ['2026-11-18T23:59:59Z', 'Pacific/Kiritimati'],
            'the last second, PHP in Pacific/Pago_Pago' => ['2026-11-18T23:59:59Z', 'Pacific/Pago_Pago'],
            'now given with an offset that is already the 19th' => ['2026-11-19T01:00:00+14:00', 'UTC'],
        ];
    }

    /**
     * AC 6: 2026-08-01 to 2026-10-31 is 92 days inclusive.
     */
    public function test_a_range_of_92_days_is_accepted(): void
    {
        $range = ReportRange::fromDates('2026-08-01', '2026-10-31');

        $this->assertSame(92, ReportRange::inclusiveDayCount('2026-08-01', '2026-10-31'));
        $this->assertCount(ReportRange::MAX_DAYS, $range->dates());
        $this->assertSame('2026-10-31', $range->dates()[0]);
        $this->assertSame('2026-08-01', $range->dates()[91]);
    }

    public function test_a_range_of_one_day_is_accepted(): void
    {
        $range = ReportRange::fromDates('2026-11-17', '2026-11-17');

        $this->assertSame(['2026-11-17'], $range->dates());
        $this->assertSame(1, ReportRange::inclusiveDayCount('2026-11-17', '2026-11-17'));
    }

    /**
     * A guard: the request already rejects each of these with a field error.
     */
    #[DataProvider('invalidRanges')]
    public function test_an_invalid_range_throws(string $start, string $end): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReportRange::fromDates($start, $end);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidRanges(): array
    {
        return [
            '93 days' => ['2026-08-01', '2026-11-01'],
            'start after end' => ['2026-11-18', '2026-11-17'],
            'an impossible date' => ['2026-02-30', '2026-03-01'],
            'an unpadded date' => ['2026-1-5', '2026-01-06'],
            'a date with a time' => ['2026-11-01', '2026-11-02 00:00:00'],
            'not a date' => ['yesterday', '2026-11-02'],
        ];
    }

    public function test_dates_are_validated_strictly_as_utc_calendar_dates(): void
    {
        $this->assertTrue(ReportRange::isDate('2026-11-18'));
        $this->assertTrue(ReportRange::isDate('2028-02-29'));
        $this->assertFalse(ReportRange::isDate('2026-02-29'));
        $this->assertFalse(ReportRange::isDate('2026-02-30'));
        $this->assertFalse(ReportRange::isDate('2026-1-5'));
        $this->assertFalse(ReportRange::isDate(' 2026-11-18'));
        $this->assertFalse(ReportRange::isDate("2026-11-18\n"));
        $this->assertFalse(ReportRange::isDate(''));
    }

    public function test_the_day_count_spans_a_month_and_dst_changes_exactly(): void
    {
        date_default_timezone_set('Europe/Bucharest');

        $this->assertSame(93, ReportRange::inclusiveDayCount('2026-08-01', '2026-11-01'));
        $this->assertSame(2, ReportRange::inclusiveDayCount('2026-10-24', '2026-10-25'));
        $this->assertSame(0, ReportRange::inclusiveDayCount('2026-11-18', '2026-11-17'));
    }

    /**
     * The query reads completions at or after the start's UTC midnight and strictly before
     * the UTC midnight after the end.
     */
    public function test_it_starts_and_ends_at_utc_midnights(): void
    {
        date_default_timezone_set('Pacific/Kiritimati');

        $range = ReportRange::fromDates('2026-11-16', '2026-11-17');

        $this->assertSame('2026-11-16 00:00:00+00:00', $range->startsAt()->format('Y-m-d H:i:sP'));
        $this->assertSame('2026-11-18 00:00:00+00:00', $range->endsBefore()->format('Y-m-d H:i:sP'));
        $this->assertSame(['2026-11-17', '2026-11-16'], $range->dates());
    }

    public function test_it_serializes_as_the_usage_endpoints_params(): void
    {
        $this->assertSame(['start_date' => '2026-11-16', 'end_date' => '2026-11-17'], ReportRange::fromDates('2026-11-16', '2026-11-17')->toArray());
    }

    public function test_the_limits_are_92_days_ending_on_todays_utc_date(): void
    {
        date_default_timezone_set('Pacific/Kiritimati');

        $this->assertSame(['max_days' => 92, 'today' => '2026-11-18'], ReportRange::limitsFor(CarbonImmutable::parse('2026-11-18T23:59:59Z')));
    }
}
