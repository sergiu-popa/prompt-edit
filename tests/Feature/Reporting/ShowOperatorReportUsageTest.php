<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Generation\Models\Generation;
use App\Models\User;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;

/**
 * The page's `usage` prop (AC 6–14) with the PE-016 worked example: G1 and G2 on 11-16,
 * G4 (D-09) on 11-17, clock at 2026-11-18T12:00:00Z, range 2026-11-15 to 2026-11-18.
 */
final class ShowOperatorReportUsageTest extends TestCase
{
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;

    private const array RANGE = ['start_date' => '2026-11-15', 'end_date' => '2026-11-18'];

    private const array FIGURES = ['provider_cost_nanodollars', 'input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    private const array ZERO_DELTA = ['provider_cost_nanodollars' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-18T12:00:00Z'));
        $this->givenTheWorkedExample();
    }

    /**
     * AC 6: every day and the total Match.
     */
    public function test_the_worked_example_matches_every_day_and_the_total(): void
    {
        Http::fake(['*' => self::pixelforgeSays()]);

        $this->report()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('usage.availability', 'available')
                ->where('usage.has_mismatch', false)
                ->has('usage.days', 4)
                ->where('usage.days.0.date', '2026-11-18')
                ->where('usage.days.2.date', '2026-11-16')
                ->where('usage.days.2.recorded', ['generations' => 2, 'provider_cost_nanodollars' => 74_595_000, 'input_text_tokens' => 14, 'input_image_tokens' => 0, 'output_tokens' => 1_380, 'reasoning_tokens' => 1_546])
                ->where('usage.days.2.pixelforge', ['generations' => 2, 'provider_cost_nanodollars' => 74_595_000, 'input_text_tokens' => 14, 'input_image_tokens' => 0, 'output_tokens' => 1_380, 'reasoning_tokens' => 1_546])
                ->where('usage.days.1.recorded.provider_cost_nanodollars', 17_480_000)
                ->where('usage.days.1.pixelforge.reasoning_tokens', 500)
                ->where('usage.days.0.delta', self::ZERO_DELTA)
                ->where('usage.days.0.matches', true)
                ->where('usage.days.1.matches', true)
                ->where('usage.days.2.matches', true)
                ->where('usage.days.3.matches', true)
                ->where('usage.total.recorded', ['generations' => 3, 'provider_cost_nanodollars' => 92_075_000, 'input_text_tokens' => 20, 'input_image_tokens' => 0, 'output_tokens' => 1_660, 'reasoning_tokens' => 2_046])
                ->where('usage.total.pixelforge.provider_cost_nanodollars', 92_075_000)
                ->where('usage.total.delta', self::ZERO_DELTA)
                ->where('usage.total.matches', true));

        $this->assertSame(
            ['https://pixelforge.promptedit.dev/v1/usage?start_date=2026-11-15&end_date=2026-11-18'],
            array_map(fn ($request): string => $request->url(), $this->recordedUsageRequests()),
        );
    }

    /**
     * AC 7: one nanodollar.
     */
    public function test_one_nanodollar_more_mismatches_that_day_and_the_total(): void
    {
        Http::fake(['*' => self::pixelforgeSays('0.017480001')]);

        $this->report()->assertInertia(fn (Assert $page) => $page
            ->where('usage.has_mismatch', true)
            ->where('usage.days.1.matches', false)
            ->where('usage.days.1.delta', [...self::ZERO_DELTA, 'provider_cost_nanodollars' => 1])
            ->where('usage.days.2.matches', true)
            ->where('usage.total.matches', false)
            ->where('usage.total.delta.provider_cost_nanodollars', 1));
    }

    /**
     * AC 8: one more `[succeed]` generation with the production key, made elsewhere.
     */
    public function test_a_generation_made_outside_the_app_shows_its_deltas(): void
    {
        Http::fake(['*' => self::pixelforgeSays('0.032605000', [11, 0, 560, 812])]);

        $this->report()->assertInertia(fn (Assert $page) => $page
            ->where('usage.has_mismatch', true)
            ->where('usage.days.1.matches', false)
            ->where('usage.days.1.delta', ['provider_cost_nanodollars' => 15_125_000, 'input_text_tokens' => 5, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 312]));
    }

    /**
     * AC 9.
     */
    public function test_only_today_is_in_progress(): void
    {
        Http::fake(['*' => self::pixelforgeSays()]);

        $this->report()->assertInertia(fn (Assert $page) => $page
            ->where('usage.days.0.in_progress', true)
            ->where('usage.days.1.in_progress', false)
            ->where('usage.days.2.in_progress', false)
            ->where('usage.days.3.in_progress', false));
    }

    /**
     * AC 11: the range is refused locally, so nothing reaches Pixelforge.
     */
    public function test_a_93_day_range_sends_nothing(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->operator()->create())
            ->get('/report?start_date=2026-08-18&end_date=2026-11-18')
            ->assertRedirect('/report')
            ->assertSessionHasErrors(['end_date']);

        Http::assertNothingSent();
    }

    /**
     * AC 11: should Pixelforge refuse the range anyway, our report still renders, unchanged.
     */
    public function test_a_range_refused_by_pixelforge_shows_our_figures_with_its_notice(): void
    {
        Http::fake(['*' => self::pixelforgeErrorFor(PixelforgeErrorCode::DateRangeTooLong)]);

        $this->report()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('usage.availability', 'range_too_long')
                ->where('usage.has_mismatch', false)
                ->where('usage.days.2.recorded.provider_cost_nanodollars', 74_595_000)
                ->where('usage.days.2.pixelforge', null)
                ->where('report.totals', ['succeeded' => 3, 'spend_nanodollars' => 93_243_750, 'provider_cost_nanodollars' => 92_075_000, 'profit_nanodollars' => 1_168_750]));
    }

    /**
     * AC 13: a reload within the cache window sends nothing.
     */
    public function test_two_loads_send_one_request(): void
    {
        Http::fake(['*' => self::pixelforgeSays()]);

        $this->report()->assertOk();
        $this->travel(30)->seconds();
        $this->report()->assertOk()->assertInertia(fn (Assert $page) => $page->where('usage.availability', 'available'));

        Http::assertSentCount(1);
    }

    /**
     * AC 14: Pixelforge is down; our figures, no comparison and no error page.
     */
    public function test_pixelforge_unavailable_shows_our_figures_only(): void
    {
        Http::fake(['*' => self::pixelforgeErrorFor(PixelforgeErrorCode::Overloaded, headers: ['Retry-After' => '2'])]);

        $this->report()
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $page->where('usage.availability', 'unavailable')
                    ->where('usage.has_mismatch', false)
                    ->where('usage.days.2.recorded.provider_cost_nanodollars', 74_595_000)
                    ->where('usage.total.recorded.provider_cost_nanodollars', 92_075_000);

                foreach (['usage.days.0', 'usage.days.2', 'usage.total'] as $line) {
                    $page->where("{$line}.pixelforge", null)
                        ->where("{$line}.delta", null)
                        ->where("{$line}.matches", null);
                }
            });
    }

    public function test_every_figure_is_an_integer_and_every_flag_a_boolean(): void
    {
        Http::fake(['*' => self::pixelforgeSays('0.017480001')]);

        $this->report()->assertInertia(function (Assert $page): void {
            $page->whereType('usage.has_mismatch', 'boolean');

            foreach (['usage.days.0', 'usage.days.1', 'usage.days.3', 'usage.total'] as $line) {
                $page->whereType("{$line}.matches", 'boolean')
                    ->whereType("{$line}.recorded.generations", 'integer')
                    ->whereType("{$line}.pixelforge.generations", 'integer');

                foreach (self::FIGURES as $figure) {
                    $page->whereType("{$line}.recorded.{$figure}", 'integer')
                        ->whereType("{$line}.pixelforge.{$figure}", 'integer')
                        ->whereType("{$line}.delta.{$figure}", 'integer');
                }
            }

            foreach ([0, 1, 2, 3] as $day) {
                $page->whereType("usage.days.{$day}.in_progress", 'boolean');
            }
        });
    }

    /**
     * @return TestResponse<Response>
     */
    private function report(): TestResponse
    {
        return $this->actingAs(User::factory()->operator()->create())->get('/report?' . http_build_query(self::RANGE));
    }

    /**
     * Pixelforge's answer for the range: the worked example, with 11-17 as given.
     *
     * @param  array{int, int, int, int}  $tokens  11-17's input text, input image, output and reasoning
     */
    private static function pixelforgeSays(string $costUsd = '0.017480000', array $tokens = [6, 0, 280, 500]): PromiseInterface
    {
        return self::pixelforgeUsageAnswered([
            self::usageRow('2026-11-15'),
            self::usageRow('2026-11-16', '0.074595000', 14, 0, 1_380, 1_546, 2),
            self::usageRow('2026-11-17', $costUsd, $tokens[0], $tokens[1], $tokens[2], $tokens[3], 1),
            self::usageRow('2026-11-18'),
        ]);
    }

    /**
     * G1 and G2 succeeded on 11-16, G3 failed, and G4 is D-09's row on 11-17.
     */
    private function givenTheWorkedExample(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        Generation::factory()->succeeded()->create(['user_id' => $userA->id, 'completed_at' => CarbonImmutable::parse('2026-11-16T10:00:00Z'), ...self::usage([5, 0, 280, 312], 15_125_000), 'user_charge_nanodollars' => 18_906_250]);
        Generation::factory()->succeeded()->create(['user_id' => $userB->id, 'completed_at' => CarbonImmutable::parse('2026-11-16T23:59:58Z'), ...self::usage([9, 0, 1_100, 1_234], 59_470_000), 'user_charge_nanodollars' => 74_337_500]);
        Generation::factory()->failed()->create(['user_id' => $userA->id, 'completed_at' => CarbonImmutable::parse('2026-11-16T12:00:00Z')]);
        Generation::factory()->providerCostOnly()->create([
            'user_id' => $userA->id,
            'created_at' => CarbonImmutable::parse('2026-11-16T23:59:50Z'),
            'completed_at' => CarbonImmutable::parse('2026-11-17T00:00:12Z'),
            ...self::usage([6, 0, 280, 500], 17_480_000),
        ]);
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
}
