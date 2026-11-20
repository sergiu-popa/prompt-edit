<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\Actions\FetchPixelforgeUsageAction;
use App\Domain\Reporting\ValueObjects\ReportRange;
use App\Support\Pixelforge\DataTransferObjects\UsageReportData;
use App\Support\Pixelforge\Exceptions\AmbiguousPixelforgeOutcomeException;
use App\Support\Pixelforge\PixelforgeRequestBudget;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;

/**
 * AC 13: a success is reused for 60 s per range, on the database store as in production
 * (D-74), and a failure is never cached.
 */
final class FetchPixelforgeUsageActionTest extends TestCase
{
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'database']);
        $this->travelTo(CarbonImmutable::parse('2026-11-18T12:00:00Z'));
    }

    public function test_a_second_fetch_within_the_window_sends_nothing_and_takes_no_unit(): void
    {
        Http::fake(['*' => self::twoDays()]);

        $first = $this->fetch('2026-11-17', '2026-11-18');
        $remaining = $this->app->make(PixelforgeRequestBudget::class)->remaining();
        $this->travel(59)->seconds();
        $second = $this->fetch('2026-11-17', '2026-11-18');

        Http::assertSentCount(1);
        $this->assertSame($remaining, $this->app->make(PixelforgeRequestBudget::class)->remaining());
        $this->assertEquals($first, $second);
        $this->assertSame(17_480_000, $second->days['2026-11-17']->cost->amount);
    }

    public function test_after_the_window_it_sends_again(): void
    {
        Http::fake(['*' => self::twoDays()]);

        $this->fetch('2026-11-17', '2026-11-18');
        $this->travel(61)->seconds();
        $this->fetch('2026-11-17', '2026-11-18');

        Http::assertSentCount(2);
    }

    public function test_another_range_is_its_own_entry(): void
    {
        Http::fake([
            '*start_date=2026-11-17*' => self::twoDays(),
            '*start_date=2026-11-18*' => self::pixelforgeUsageAnswered([self::usageRow('2026-11-18')]),
        ]);

        $this->fetch('2026-11-17', '2026-11-18');
        $other = $this->fetch('2026-11-18', '2026-11-18');
        $this->fetch('2026-11-17', '2026-11-18');

        Http::assertSentCount(2);
        $this->assertSame(['2026-11-18'], array_keys($other->days));
    }

    public function test_a_failure_is_not_cached(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 500)->pushResponse(self::twoDays())]);

        try {
            $this->fetch('2026-11-17', '2026-11-18');
            $this->fail('Expected the 500 to surface.');
        } catch (AmbiguousPixelforgeOutcomeException $exception) {
            $this->assertSame(500, $exception->status);
        }

        $this->fetch('2026-11-17', '2026-11-18');
        $this->fetch('2026-11-17', '2026-11-18');

        Http::assertSentCount(2);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function staleEntries(): array
    {
        return [
            'another shape' => [['object' => 'usage', 'days' => []]],
            'no array' => ['0.017480000'],
            'another range' => [[
                'object' => 'usage',
                'start_date' => '2026-11-18',
                'end_date' => '2026-11-18',
                'data' => [['date' => '2026-11-18', 'generations' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => '0.000000000']],
                'total' => ['generations' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => '0.000000000'],
            ]],
        ];
    }

    /**
     * Review S2: an entry stored by an earlier deploy never fails the page.
     */
    #[DataProvider('staleEntries')]
    public function test_a_cached_entry_that_no_longer_reads_is_fetched_again(mixed $entry): void
    {
        Http::fake(['*' => self::twoDays()]);
        Cache::put('pixelforge-usage:2026-11-17:2026-11-18', $entry, 60);

        $usage = $this->fetch('2026-11-17', '2026-11-18');
        $this->fetch('2026-11-17', '2026-11-18');

        Http::assertSentCount(1);
        $this->assertSame(17_480_000, $usage->days['2026-11-17']->cost->amount);
    }

    private function fetch(string $startDate, string $endDate): UsageReportData
    {
        return $this->app->make(FetchPixelforgeUsageAction::class)->execute(ReportRange::fromDates($startDate, $endDate));
    }

    private static function twoDays(): PromiseInterface
    {
        return self::pixelforgeUsageAnswered([
            self::usageRow('2026-11-17', '0.017480000', 6, 0, 280, 500, 1),
            self::usageRow('2026-11-18'),
        ]);
    }
}
