<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Pixelforge\DataTransferObjects;

use App\Support\Pixelforge\DataTransferObjects\UsageReportData;
use App\Support\Pixelforge\Exceptions\InvalidUsageReportException;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\TestCase;

final class UsageReportDataTest extends TestCase
{
    use InteractsWithGenerationSnapshots;

    public function test_the_docs_example_parses_into_exact_integers(): void
    {
        $usage = UsageReportData::from(self::pixelforgeFixture('usage'));

        $this->assertSame('2026-11-01', $usage->startDate);
        $this->assertSame('2026-11-02', $usage->endDate);
        $this->assertSame(['2026-11-01', '2026-11-02'], array_keys($usage->days));

        $first = $usage->days['2026-11-01'];
        $this->assertSame(3, $first->generations);
        $this->assertSame(41, $first->tokens->inputTextTokens);
        $this->assertSame(0, $first->tokens->inputImageTokens);
        $this->assertSame(3_300, $first->tokens->outputTokens);
        $this->assertSame(2_950, $first->tokens->reasoningTokens);
        $this->assertSame(169_080_000, $first->cost->amount);

        $this->assertSame(0, $usage->days['2026-11-02']->generations);
        $this->assertSame(0, $usage->days['2026-11-02']->cost->amount);

        $this->assertSame(3, $usage->total->generations);
        $this->assertSame(2_950, $usage->total->tokens->reasoningTokens);
        $this->assertSame(169_080_000, $usage->total->cost->amount);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function exactCosts(): array
    {
        return [
            'the PE-016 worked example' => ['0.074595000', 74_595_000],
            'the docs\' worked maximum' => ['0.373875000', 373_875_000],
            'one nanodollar' => ['0.000000001', 1],
            'zero' => ['0.000000000', 0],
        ];
    }

    #[DataProvider('exactCosts')]
    public function test_each_cost_is_read_to_the_nanodollar(string $costUsd, int $nanodollars): void
    {
        $usage = UsageReportData::from(self::report(fn (array $report): array => self::withCost($report, $costUsd)));

        $this->assertSame($nanodollars, $usage->days['2026-11-01']->cost->amount);
        $this->assertSame($nanodollars, $usage->total->cost->amount);
    }

    /**
     * @return array<string, array{Closure(array<string, mixed>): array<string, mixed>}>
     */
    public static function unreadableReports(): array
    {
        return [
            'ten decimals' => [fn (array $report): array => self::withCost($report, '0.0000000001')],
            'a negative cost' => [fn (array $report): array => self::withCost($report, '-0.000000001')],
            'a negative zero' => [fn (array $report): array => self::withCost($report, '-0.000000000')],
            'a word' => [fn (array $report): array => self::withCost($report, 'abc')],
            'an empty cost' => [fn (array $report): array => self::withCost($report, '')],
            'an exponent' => [fn (array $report): array => self::withCost($report, '1e-9')],
            'a plus sign' => [fn (array $report): array => self::withCost($report, '+0.074595000')],
            'a leading space' => [fn (array $report): array => self::withCost($report, ' 0.074595000')],
            'a trailing space' => [fn (array $report): array => self::withCost($report, '0.074595000 ')],
            'a trailing newline' => [fn (array $report): array => self::withCost($report, "0.074595000\n")],
            'a JSON number' => [fn (array $report): array => self::withRowField($report, 'cost_usd', 0.16908)],
            'a null cost' => [fn (array $report): array => self::withRowField($report, 'cost_usd', null)],
            'a count as a string' => [fn (array $report): array => self::withRowField($report, 'output_tokens', '5')],
            'a count as a float' => [fn (array $report): array => self::withRowField($report, 'output_tokens', 5.0)],
            'a negative count' => [fn (array $report): array => self::withRowField($report, 'generations', -1)],
            'a negative token count' => [fn (array $report): array => self::withRowField($report, 'reasoning_tokens', -1)],
            'a missing count' => [fn (array $report): array => self::withoutRowField($report, 'input_image_tokens')],
            'a missing cost' => [fn (array $report): array => self::withoutRowField($report, 'cost_usd')],
            'another object' => [fn (array $report): array => [...$report, 'object' => 'list']],
            'no object' => [fn (array $report): array => array_diff_key($report, ['object' => true])],
            'no start date' => [fn (array $report): array => array_diff_key($report, ['start_date' => true])],
            'a malformed start date' => [fn (array $report): array => [...$report, 'start_date' => '2026-11-1']],
            'a start after the end' => [fn (array $report): array => [...$report, 'start_date' => '2026-11-03']],
            'no data' => [fn (array $report): array => array_diff_key($report, ['data' => true])],
            'data as an object' => [fn (array $report): array => [...$report, 'data' => ['2026-11-01' => self::row($report, 0)]]],
            'a row that is no object' => [fn (array $report): array => [...$report, 'data' => [self::row($report, 0), 'row']]],
            'a missing date' => [fn (array $report): array => [...$report, 'data' => [self::row($report, 0)]]],
            'a repeated date' => [fn (array $report): array => [...$report, 'data' => [self::row($report, 0), self::row($report, 0)]]],
            'a date outside the range' => [fn (array $report): array => [...$report, 'data' => [self::row($report, 0), [...self::row($report, 1), 'date' => '2026-11-03']]]],
            'a malformed row date' => [fn (array $report): array => [...$report, 'data' => [self::row($report, 0), [...self::row($report, 1), 'date' => '2026-11-2']]]],
            'a row without a date' => [fn (array $report): array => [...$report, 'data' => [self::row($report, 0), array_diff_key(self::row($report, 1), ['date' => true])]]],
            'no total' => [fn (array $report): array => array_diff_key($report, ['total' => true])],
            'a total that is no object' => [fn (array $report): array => [...$report, 'total' => '0.169080000']],
            'a total with a bad cost' => [fn (array $report): array => [...$report, 'total' => [...self::total($report), 'cost_usd' => '0.1690800001']]],
        ];
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     */
    #[DataProvider('unreadableReports')]
    public function test_a_report_that_breaks_the_docs_throws(Closure $change): void
    {
        $this->expectException(InvalidUsageReportException::class);

        UsageReportData::from(self::report($change));
    }

    public function test_the_rows_may_come_in_any_order(): void
    {
        $report = self::pixelforgeFixture('usage');
        $report['data'] = array_reverse(self::rows($report));

        $usage = UsageReportData::from($report);

        $this->assertSame(['2026-11-01', '2026-11-02'], array_keys($usage->days));
    }

    public function test_unknown_keys_are_ignored(): void
    {
        $report = self::pixelforgeFixture('usage');
        $report['has_more'] = false;
        $report['data'] = array_map(fn (array $row): array => [...$row, 'currency' => 'usd'], self::rows($report));
        $report['total'] = [...self::total($report), 'currency' => 'usd'];

        $usage = UsageReportData::from($report);

        $this->assertSame(169_080_000, $usage->total->cost->amount);
    }

    public function test_it_reads_back_what_it_writes(): void
    {
        $fixture = self::pixelforgeFixture('usage');
        $usage = UsageReportData::from($fixture);

        $this->assertSame($fixture, $usage->toArray());
        $this->assertEquals($usage, UsageReportData::from($usage->toArray()));
        $this->assertSame('0.000000000', $usage->toArray()['data'][1]['cost_usd']);
    }

    /**
     * The docs' example, changed.
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private static function report(Closure $change): array
    {
        return $change(self::pixelforgeFixture('usage'));
    }

    /**
     * The first day's and the total's cost, with the second day at zero.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function withCost(array $report, string $costUsd): array
    {
        $report = self::withRowField($report, 'cost_usd', $costUsd);
        $report['total'] = [...self::total($report), 'cost_usd' => $costUsd];

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function withRowField(array $report, string $field, mixed $value): array
    {
        $rows = self::rows($report);
        $rows[0][$field] = $value;
        $report['data'] = $rows;

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function withoutRowField(array $report, string $field): array
    {
        $rows = self::rows($report);
        unset($rows[0][$field]);
        $report['data'] = $rows;

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    private static function rows(array $report): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $report['data'];

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function row(array $report, int $index): array
    {
        return self::rows($report)[$index];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function total(array $report): array
    {
        /** @var array<string, mixed> $total */
        $total = $report['total'];

        return $total;
    }
}
