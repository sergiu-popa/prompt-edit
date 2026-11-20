<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Generation\Models\Generation;
use App\Domain\Reporting\Actions\ShowOperatorReportAction;
use App\Domain\Reporting\Actions\SumRecordedUsageAction;
use App\Domain\Reporting\DataTransferObjects\UsageFigures;
use App\Domain\Reporting\ValueObjects\ReportRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "We recorded" (AC 4): the provider cost and tokens of every generation Pixelforge billed,
 * D-09's rows included, per UTC date of its `completed_at`, with D-64's scopes.
 */
final class SumRecordedUsageActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The `succeeded()` and `providerCostOnly()` states' usage and provider cost.
     */
    private const array FACTORY_FIGURES = ['provider_cost_nanodollars' => 87_450_000, 'input_text_tokens' => 10, 'input_image_tokens' => 765, 'output_tokens' => 1_650, 'reasoning_tokens' => 1_100];

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

    public function test_succeeded_and_provider_cost_only_rows_are_summed_per_day(): void
    {
        $this->givenASucceededGeneration('2026-11-16T10:00:00Z', [5, 0, 280, 312], 15_125_000);
        $this->givenASucceededGeneration('2026-11-16T23:59:58Z', [9, 0, 1_100, 1_234], 59_470_000);
        Generation::factory()->providerCostOnly()->create([
            'completed_at' => CarbonImmutable::parse('2026-11-17T00:00:12Z'),
            ...self::usage([6, 0, 280, 500], 17_480_000),
        ]);

        $recorded = $this->recorded('2026-11-15', '2026-11-18');

        $this->assertFigures([2, 74_595_000, 14, 0, 1_380, 1_546], $recorded['2026-11-16']);
        $this->assertFigures([1, 17_480_000, 6, 0, 280, 500], $recorded['2026-11-17']);
    }

    public function test_failed_pending_and_in_flight_rows_count_nowhere(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16T12:00:00Z'));
        Generation::factory()->failed()->create();
        Generation::factory()->failedLocally()->create(['completed_at' => CarbonImmutable::parse('2026-11-16T12:00:00Z')]);
        Generation::factory()->create();
        Generation::factory()->queued()->create();
        Generation::factory()->processing()->create(['completed_at' => CarbonImmutable::parse('2026-11-16T12:00:00Z')]);
        $this->travelBack();

        $this->assertFigures([0, 0, 0, 0, 0, 0], $this->recorded('2026-11-16', '2026-11-16')['2026-11-16']);
    }

    public function test_every_date_is_present_newest_first_with_zeros(): void
    {
        $recorded = $this->recorded('2026-11-14', '2026-11-17');

        $this->assertSame(['2026-11-17', '2026-11-16', '2026-11-15', '2026-11-14'], array_keys($recorded));

        foreach ($recorded as $figures) {
            $this->assertEquals(UsageFigures::zero(), $figures);
        }
    }

    /**
     * The same edge as PE-016's: neither the session's nor PHP's timezone moves a day.
     */
    #[DataProvider('timezones')]
    public function test_a_generation_counts_on_the_utc_date_it_completed(string $timezone): void
    {
        DB::statement("SET TIME ZONE '{$timezone}'");
        date_default_timezone_set($timezone);

        Generation::factory()->succeeded()->create([
            'created_at' => CarbonImmutable::parse('2026-11-16T23:59:50Z'),
            'completed_at' => CarbonImmutable::parse('2026-11-17T00:00:12Z'),
        ]);
        Generation::factory()->succeeded()->create(['completed_at' => CarbonImmutable::parse('2026-11-16T23:59:58Z')]);

        $recorded = $this->recorded('2026-11-15', '2026-11-18');

        $this->assertSame(1, $recorded['2026-11-16']->generations);
        $this->assertSame(1, $recorded['2026-11-17']->generations);
        $this->assertSame(0, $recorded['2026-11-15']->generations);
        $this->assertSame(0, $recorded['2026-11-18']->generations);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function timezones(): array
    {
        return [
            'UTC+14' => ['Pacific/Kiritimati'],
            'UTC−11' => ['Pacific/Pago_Pago'],
        ];
    }

    /**
     * The range is the start's UTC midnight inclusive to the UTC midnight after the end.
     */
    public function test_the_range_keeps_completions_from_its_first_to_its_last_second(): void
    {
        foreach (['2026-11-15T23:59:59Z', '2026-11-16T00:00:00Z', '2026-11-17T23:59:59Z', '2026-11-18T00:00:00Z'] as $completedAt) {
            Generation::factory()->succeeded()->create(['completed_at' => CarbonImmutable::parse($completedAt)]);
        }

        $recorded = $this->recorded('2026-11-16', '2026-11-17');

        $this->assertSame(1, $recorded['2026-11-16']->generations);
        $this->assertSame(1, $recorded['2026-11-17']->generations);
    }

    public function test_each_days_cost_equals_the_reports_provider_cost(): void
    {
        $this->givenASucceededGeneration('2026-11-16T10:00:00Z', [5, 0, 280, 312], 15_125_000);
        $this->givenASucceededGeneration('2026-11-16T23:59:58Z', [9, 0, 1_100, 1_234], 59_470_000);
        Generation::factory()->providerCostOnly()->create(['completed_at' => CarbonImmutable::parse('2026-11-17T00:00:12Z')]);
        Generation::factory()->failed()->create(['completed_at' => CarbonImmutable::parse('2026-11-17T12:00:00Z')]);

        $range = ReportRange::fromDates('2026-11-15', '2026-11-18');
        $report = $this->app->make(ShowOperatorReportAction::class)->execute($range);
        $recorded = $this->app->make(SumRecordedUsageAction::class)->execute($range);

        $this->assertSame(array_keys($report->days), array_keys($recorded));

        foreach ($report->days as $date => $figures) {
            $this->assertSame($figures->providerCost->amount, $recorded[$date]->providerCost->amount, $date);
            $this->assertSame($figures->succeeded, $recorded[$date]->generations, $date);
        }
    }

    public function test_every_figure_is_an_int_sums_of_two_rows_included(): void
    {
        Generation::factory()->succeeded()->create(['completed_at' => CarbonImmutable::parse('2026-11-16T09:00:00Z')]);
        Generation::factory()->providerCostOnly()->create(['completed_at' => CarbonImmutable::parse('2026-11-16T10:00:00Z')]);

        $this->assertSame(
            ['generations' => 2, ...array_map(fn (int $figure): int => 2 * $figure, self::FACTORY_FIGURES)],
            $this->recorded('2026-11-16', '2026-11-16')['2026-11-16']->toArray(),
        );
    }

    /**
     * @param  array{int, int, int, int}  $tokens  input text, input image, output and reasoning
     */
    private function givenASucceededGeneration(string $completedAt, array $tokens, int $providerCost): void
    {
        Generation::factory()->succeeded()->create(['completed_at' => CarbonImmutable::parse($completedAt), ...self::usage($tokens, $providerCost)]);
    }

    /**
     * @param  array{int, int, int, int}  $tokens
     * @return array<string, int>
     */
    private static function usage(array $tokens, int $providerCost): array
    {
        return [
            'input_text_tokens' => $tokens[0],
            'input_image_tokens' => $tokens[1],
            'output_tokens' => $tokens[2],
            'reasoning_tokens' => $tokens[3],
            'provider_cost_nanodollars' => $providerCost,
        ];
    }

    /**
     * @return array<string, UsageFigures>
     */
    private function recorded(string $startDate, string $endDate): array
    {
        return $this->app->make(SumRecordedUsageAction::class)->execute(ReportRange::fromDates($startDate, $endDate));
    }

    /**
     * @param  array{int, int, int, int, int, int}  $expected  count, provider cost and the four token counts
     */
    private function assertFigures(array $expected, UsageFigures $figures): void
    {
        $this->assertSame($expected, array_values($figures->toArray()));
    }
}
