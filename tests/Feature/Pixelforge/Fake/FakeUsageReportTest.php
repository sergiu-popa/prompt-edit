<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Fake;

use App\Domain\Billing\Actions\CalculateProviderCostAction;
use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Domain\Billing\ValueObjects\TokenRates;
use App\Domain\Billing\ValueObjects\TokenUsage;
use App\Support\Pixelforge\DataTransferObjects\UsageReportData;
use App\Support\Pixelforge\Fake\FakeUsageReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithFakePixelforge;
use Tests\TestCase;

/**
 * The fake's `/v1/usage` from its own table (D-75): succeeded generations by the UTC
 * date of their `completed_at`, priced in decimal arithmetic on their own rate strings.
 */
final class FakeUsageReportTest extends TestCase
{
    use InteractsWithFakePixelforge;
    use RefreshDatabase;

    public function test_succeeded_generations_are_summed_by_their_completed_utc_day(): void
    {
        // Created at 23:59:55, it completes 4 s later, the last second of 11-16.
        $this->travelTo(CarbonImmutable::parse('2026-11-16T23:59:55Z'));
        $lastSecond = $this->succeedThroughFake();

        // Created at 23:59:56, it completes at the first second of 11-17.
        $this->travelTo(CarbonImmutable::parse('2026-11-16T23:59:56Z'));
        $firstSecond = $this->succeedThroughFake();
        $later = $this->succeedThroughFake();

        $this->assertSame('2026-11-16T23:59:59Z', $this->fakeGenerationRow($lastSecond)->snapshot['completed_at']);
        $this->assertSame('2026-11-17T00:00:00Z', $this->fakeGenerationRow($firstSecond)->snapshot['completed_at']);

        $report = $this->build('2026-11-15', '2026-11-18');

        $this->assertSame(['2026-11-15', '2026-11-16', '2026-11-17', '2026-11-18'], array_column($report['data'], 'date'));
        $this->assertSame([0, 1, 2, 0], array_column($report['data'], 'generations'));
        $this->assertSame($this->usdOf($lastSecond), $report['data'][1]['cost_usd']);
        $this->assertSame($this->usdOf($firstSecond, $later), $report['data'][2]['cost_usd']);

        $usage = $this->fakeUsage($firstSecond)->outputTokens + $this->fakeUsage($later)->outputTokens;
        $this->assertSame($usage, $report['data'][2]['output_tokens']);
    }

    public function test_failed_queued_and_processing_generations_count_nowhere(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17T12:00:00Z'));
        $succeeded = $this->succeedThroughFake();

        $this->createThroughFake(self::lighthousePayload(prompt: '[fail] a broken pocket watch'));
        $this->runFakeLifecycle();
        $this->createThroughFake(self::lighthousePayload());
        $this->createThroughFake(self::lighthousePayload());
        $this->runNextFakeLifecycleJob();

        $report = $this->build('2026-11-17', '2026-11-17');

        $this->assertSame(1, $report['data'][0]['generations']);
        $this->assertSame($this->usdOf($succeeded), $report['data'][0]['cost_usd']);
        $this->assertSame($report['data'][0]['cost_usd'], $report['total']['cost_usd']);
    }

    public function test_zero_days_read_zero_to_nine_decimals(): void
    {
        $report = $this->build('2026-11-15', '2026-11-16');

        foreach ([...$report['data'], $report['total']] as $row) {
            $this->assertSame('0.000000000', $row['cost_usd']);
            $this->assertSame(0, $row['generations']);
            $this->assertSame(0, $row['input_text_tokens']);
            $this->assertSame(0, $row['input_image_tokens']);
            $this->assertSame(0, $row['output_tokens']);
            $this->assertSame(0, $row['reasoning_tokens']);
        }
    }

    public function test_the_total_sums_the_days_and_has_no_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15T08:00:00Z'));
        $first = $this->succeedThroughFake();
        $this->travelTo(CarbonImmutable::parse('2026-11-17T08:00:00Z'));
        $second = $this->succeedThroughFake();

        $report = $this->build('2026-11-15', '2026-11-18');

        $this->assertSame(['generations', 'input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens', 'cost_usd'], array_keys($report['total']));
        $this->assertSame(2, $report['total']['generations']);
        $this->assertSame($this->usdOf($first, $second), $report['total']['cost_usd']);
        $this->assertSame(
            $this->fakeUsage($first)->reasoningTokens + $this->fakeUsage($second)->reasoningTokens,
            $report['total']['reasoning_tokens'],
        );
    }

    public function test_the_report_is_shaped_as_the_docs_show_it(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16T10:00:00Z'));
        $this->succeedThroughFake();

        $report = $this->build('2026-11-15', '2026-11-18');

        $this->assertSame('usage', $report['object']);
        $this->assertSame('2026-11-15', $report['start_date']);
        $this->assertSame('2026-11-18', $report['end_date']);
        $this->assertSame(['date', 'generations', 'input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens', 'cost_usd'], array_keys($report['data'][0]));

        $usage = UsageReportData::from($report);
        $this->assertSame($report, $usage->toArray());
    }

    /**
     * @return array{object: string, start_date: string, end_date: string, data: list<array<string, int|string>>, total: array<string, int|string>}
     */
    private function build(string $startDate, string $endDate): array
    {
        return $this->app->make(FakeUsageReport::class)->build($startDate, $endDate);
    }

    /**
     * A `[succeed]` generation through the fake's whole lifecycle; its provider id.
     */
    private function succeedThroughFake(): string
    {
        $created = $this->createThroughFake(self::lighthousePayload(prompt: '[succeed] a calm harbour'));
        $this->runFakeLifecycle();

        return $created->snapshot->id;
    }

    private function fakeUsage(string $providerId): TokenUsage
    {
        return TokenUsage::from($this->fakeGenerationRow($providerId)->usage);
    }

    /**
     * What our own integer calculator makes of the generations' usage and rates, as USD.
     */
    private function usdOf(string ...$providerIds): string
    {
        $cost = Nanodollars::zero();

        foreach ($providerIds as $providerId) {
            $rates = $this->fakeGenerationRow($providerId)->snapshot['rates'];
            $this->assertIsArray($rates);
            $cost = $cost->plus((new CalculateProviderCostAction)->execute($this->fakeUsage($providerId), TokenRates::from($rates)));
        }

        return $cost->toUsdString();
    }
}
