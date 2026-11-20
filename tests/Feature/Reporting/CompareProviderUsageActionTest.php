<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Generation\Models\Generation;
use App\Domain\Reporting\Actions\CompareProviderUsageAction;
use App\Domain\Reporting\DataTransferObjects\UsageComparisonData;
use App\Domain\Reporting\Enums\UsageAvailability;
use App\Domain\Reporting\ValueObjects\ReportRange;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use App\Support\Pixelforge\PixelforgeRequestBudget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;

/**
 * AC 11 and 14: whatever Pixelforge does, the report gets our recorded figures, and only a
 * readable answer adds the comparison.
 */
final class CompareProviderUsageActionTest extends TestCase
{
    use CapturesLogMessages;
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-18T12:00:00Z'));
        Generation::factory()->providerCostOnly()->create([
            'completed_at' => CarbonImmutable::parse('2026-11-17T00:00:12Z'),
            'input_text_tokens' => 6,
            'input_image_tokens' => 0,
            'output_tokens' => 280,
            'reasoning_tokens' => 500,
            'provider_cost_nanodollars' => 17_480_000,
        ]);
    }

    public function test_a_matching_answer_is_compared(): void
    {
        Http::fake(['*' => self::pixelforgeUsageAnswered([
            self::usageRow('2026-11-17', '0.017480000', 6, 0, 280, 500, 1),
            self::usageRow('2026-11-18'),
        ])]);

        $comparison = $this->compare();

        $this->assertSame(UsageAvailability::Available, $comparison->availability);
        $this->assertTrue($comparison->days['2026-11-17']->matches());
        $this->assertTrue($comparison->total->matches());
        $this->assertFalse($comparison->hasMismatch());
        $this->assertSame([], $this->warnings());
    }

    public function test_a_range_too_long_keeps_our_figures_with_its_own_notice(): void
    {
        Http::fake(['*' => self::pixelforgeErrorFor(PixelforgeErrorCode::DateRangeTooLong)]);

        $comparison = $this->compare();

        $this->assertSame(UsageAvailability::RangeTooLong, $comparison->availability);
        $this->assertOurFiguresAlone($comparison);
        $this->assertOneWarning('date_range_too_long');
    }

    #[DataProvider('failures')]
    public function test_any_failure_keeps_our_figures_and_reaches_no_one(string $failure, string $errorCode, bool $sends): void
    {
        match ($failure) {
            'timeout' => Http::fake(['*' => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received')]),
            '500' => Http::fake(['*' => Http::response('<html>Internal Server Error</html>', 500)]),
            '503' => Http::fake(['*' => self::pixelforgeErrorFor(PixelforgeErrorCode::Overloaded, headers: ['Retry-After' => '2'])]),
            '429' => Http::fake(['*' => self::pixelforgeErrorFor(PixelforgeErrorCode::RateLimitExceeded, headers: ['Retry-After' => '17'])]),
            '401' => Http::fake(['*' => self::pixelforgeErrorFor(PixelforgeErrorCode::InvalidApiKey)]),
            'unreadable 200' => Http::fake(['*' => Http::response('{"object":"usage"}', 200)]),
            'spent budget' => $this->spendTheBudget(),
            'no key' => $this->withoutAKey(),
            default => $this->fail(sprintf('Unknown failure %s.', $failure)),
        };

        $comparison = $this->compare();

        $this->assertSame(UsageAvailability::Unavailable, $comparison->availability);
        $this->assertOurFiguresAlone($comparison);
        $this->assertOneWarning($errorCode);
        Http::assertSentCount($sends ? 1 : 0);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function failures(): array
    {
        return [
            'a read timeout' => ['timeout', 'ambiguous', true],
            'an HTML 500' => ['500', 'ambiguous', true],
            'a 503' => ['503', 'overloaded', true],
            'a 429' => ['429', 'rate_limit_exceeded', true],
            'a 401' => ['401', 'invalid_api_key', true],
            'an unreadable 200' => ['unreadable 200', 'ambiguous', true],
            'a spent budget' => ['spent budget', 'rate_budget_exhausted', false],
            'no key' => ['no key', 'not_configured', false],
        ];
    }

    private function spendTheBudget(): void
    {
        Http::fake();
        config(['pixelforge.rate_limit_per_minute' => 1]);
        $this->app->make(PixelforgeRequestBudget::class)->take();
    }

    private function withoutAKey(): void
    {
        Http::fake();
        config(['pixelforge.api_key' => null]);
    }

    private function compare(): UsageComparisonData
    {
        return $this->app->make(CompareProviderUsageAction::class)->execute(ReportRange::fromDates('2026-11-17', '2026-11-18'), CarbonImmutable::now());
    }

    private function assertOurFiguresAlone(UsageComparisonData $comparison): void
    {
        $this->assertSame(17_480_000, $comparison->days['2026-11-17']->recorded->providerCost->amount);
        $this->assertSame(500, $comparison->days['2026-11-17']->recorded->reasoningTokens);
        $this->assertSame(17_480_000, $comparison->total->recorded->providerCost->amount);
        $this->assertNull($comparison->days['2026-11-17']->pixelforge);
        $this->assertNull($comparison->total->pixelforge);
        $this->assertFalse($comparison->hasMismatch());
    }

    private function assertOneWarning(string $errorCode): void
    {
        $warnings = $this->warnings();

        $this->assertCount(1, $warnings);
        $this->assertSame(CompareProviderUsageAction::UNAVAILABLE_LOG_MESSAGE, $warnings[0]->message);
        $this->assertSame('2026-11-17', $warnings[0]->context['start_date'] ?? null);
        $this->assertSame('2026-11-18', $warnings[0]->context['end_date'] ?? null);
        $this->assertSame($errorCode, $warnings[0]->context['error_code'] ?? null);

        foreach ($this->loggedMessages() as $logged) {
            $logs = $logged->message . json_encode($logged->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
            $this->assertStringNotContainsString(self::PIXELFORGE_API_KEY, $logs);
            $this->assertStringNotContainsStringIgnoringCase('authorization', $logs);
        }
    }

    /**
     * @return list<MessageLogged>
     */
    private function warnings(): array
    {
        return array_values(array_filter($this->loggedMessages(), fn (MessageLogged $logged): bool => $logged->level === 'warning'));
    }
}
