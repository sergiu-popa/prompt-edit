<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Generation\Models\Generation;
use App\Domain\Reporting\Actions\ShowOperatorReportAction;
use App\Domain\Reporting\DataTransferObjects\OperatorReportData;
use App\Domain\Reporting\DataTransferObjects\ReportFigures;
use App\Domain\Reporting\ValueObjects\ReportRange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The report's query (D-64): what counts, on which UTC day, and how the rows fold into
 * days, users and totals. The worked example's own numbers are in
 * OperatorReportWorkedExampleTest.
 */
final class ShowOperatorReportActionTest extends TestCase
{
    use RefreshDatabase;

    /** The `succeeded()` state's provider cost and charge. */
    private const int PROVIDER_COST = 87_450_000;

    private const int CHARGE = 109_312_500;

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
     * AC 8: a generation counts on the UTC date of Pixelforge's `completed_at`, never on
     * its creation or on its charge, whatever the session's and PHP's timezones are.
     * `SET TIME ZONE` runs inside RefreshDatabase's transaction, so the rollback reverts it.
     */
    #[DataProvider('timezones')]
    public function test_a_generation_counts_on_the_utc_date_it_completed(string $timezone): void
    {
        DB::statement("SET TIME ZONE '{$timezone}'");
        date_default_timezone_set($timezone);

        $createdLate = $this->givenASucceededGeneration('2026-11-17T00:00:12Z', ['created_at' => CarbonImmutable::parse('2026-11-16T23:59:50Z')]);

        $this->travelTo(CarbonImmutable::parse('2026-11-17T00:00:20Z'));
        $chargedLate = $this->givenASucceededGeneration('2026-11-16T23:59:58Z');
        $this->travelBack();
        $this->assertSame(
            '2026-11-17 00:00:20+00',
            DB::scalar(
                "select to_char(created_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' from credit_ledger_entries where generation_id = ? and type = 'charge'",
                [$chargedLate->id],
            ),
            'Precondition: the charge is written after midnight UTC.',
        );

        $report = $this->report('2026-11-15', '2026-11-18');

        $this->assertSame(['2026-11-18', '2026-11-17', '2026-11-16', '2026-11-15'], array_keys($report->days));
        $this->assertFigures([1, self::CHARGE, self::PROVIDER_COST], $report->days['2026-11-17']);
        $this->assertFigures([1, self::CHARGE, self::PROVIDER_COST], $report->days['2026-11-16']);
        $this->assertFigures([0, 0, 0], $report->days['2026-11-15']);
        $this->assertFigures([0, 0, 0], $report->days['2026-11-18']);
        $this->assertNotSame($createdLate->user_id, $chargedLate->user_id);
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
     * The range is the start's UTC midnight inclusive to the UTC midnight after the end,
     * exclusive.
     */
    #[DataProvider('rangeEdges')]
    public function test_the_range_keeps_completions_from_its_first_to_its_last_second(string $completedAt, bool $counted): void
    {
        DB::statement("SET TIME ZONE 'Pacific/Kiritimati'");

        $this->givenASucceededGeneration($completedAt);

        $this->assertSame($counted ? 1 : 0, $this->report('2026-11-16', '2026-11-17')->totals()->succeeded);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function rangeEdges(): array
    {
        return [
            'the start, 00:00:00Z' => ['2026-11-16T00:00:00Z', true],
            'the end, 23:59:59Z' => ['2026-11-17T23:59:59Z', true],
            'a second before the start' => ['2026-11-15T23:59:59Z', false],
            'midnight after the end' => ['2026-11-18T00:00:00Z', false],
        ];
    }

    /**
     * AC 7: every date of the range, newest first, with zeros where nothing was billed.
     */
    public function test_days_without_activity_show_zero_everywhere(): void
    {
        $report = $this->report('2026-11-14', '2026-11-17');

        $this->assertSame(['2026-11-17', '2026-11-16', '2026-11-15', '2026-11-14'], array_keys($report->days));

        foreach ($report->days as $figures) {
            $this->assertSame(['succeeded' => 0, 'spend_nanodollars' => 0, 'provider_cost_nanodollars' => 0, 'profit_nanodollars' => 0], $figures->toArray());
        }

        $this->assertSame([], $report->users);
        $this->assertFigures([0, 0, 0], $report->totals());
    }

    /**
     * AC 10: a provider failure and a local failure contribute 0 everywhere, the count too.
     */
    public function test_failed_generations_contribute_nothing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16T12:00:00Z'));
        Generation::factory()->failed()->create();
        Generation::factory()->failedLocally()->create(['completed_at' => CarbonImmutable::parse('2026-11-16T12:00:00Z')]);
        $this->travelBack();

        $report = $this->report('2026-11-16', '2026-11-16');

        $this->assertFigures([0, 0, 0], $report->days['2026-11-16']);
        $this->assertSame([], $report->users);
    }

    /**
     * D-09: Pixelforge billed a generation we failed locally and never charged, so it
     * counts as succeeded, with no spend, its provider cost and a negative profit.
     */
    public function test_a_provider_cost_only_generation_counts_with_no_spend_and_a_negative_profit(): void
    {
        $user = User::factory()->create();
        Generation::factory()->providerCostOnly()->create(['user_id' => $user->id, 'completed_at' => CarbonImmutable::parse('2026-11-16T09:00:00Z')]);

        $report = $this->report('2026-11-16', '2026-11-16');

        $this->assertFigures([1, 0, self::PROVIDER_COST], $report->days['2026-11-16']);
        $this->assertSame(-self::PROVIDER_COST, $report->days['2026-11-16']->profit()->amount);
        $this->assertCount(1, $report->users);
        $this->assertSame($user->id, $report->users[0]->userId);
        $this->assertFigures([1, 0, self::PROVIDER_COST], $report->users[0]->figures);
    }

    /**
     * Only a billed generation counts: rows still in flight hold credit, which is never spend.
     */
    public function test_generations_in_flight_contribute_nothing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16T12:00:00Z'));
        Generation::factory()->create();
        Generation::factory()->queued()->create();
        Generation::factory()->processing()->create(['completed_at' => CarbonImmutable::parse('2026-11-16T12:00:00Z')]);
        $this->travelBack();

        $report = $this->report('2026-11-16', '2026-11-16');

        $this->assertFigures([0, 0, 0], $report->days['2026-11-16']);
        $this->assertSame([], $report->users);
    }

    /**
     * Spend is the generation's own `charge` entry only (D-64): the user's top-ups, the
     * factory's starter grant and a purchase (D-67), are never spend.
     */
    public function test_spend_reads_only_the_generations_charge_entry(): void
    {
        $generation = $this->givenASucceededGeneration('2026-11-16T09:00:00Z');
        $this->assertDatabaseHas('credit_ledger_entries', ['user_id' => $generation->user_id, 'type' => 'grant']);
        DB::table('credit_ledger_entries')->insert([
            ['user_id' => $generation->user_id, 'type' => 'purchase', 'amount_nanodollars' => 1_000_000_000, 'purchase_token' => '0b6d6b6e-4a35-4c6e-9d0a-6f1c2e3d4b5a', 'created_at' => '2026-11-16 09:00:03+00:00'],
        ]);

        $report = $this->report('2026-11-16', '2026-11-16');

        $this->assertFigures([1, self::CHARGE, self::PROVIDER_COST], $report->days['2026-11-16']);
        $this->assertFigures([1, self::CHARGE, self::PROVIDER_COST], $report->users[0]->figures);
    }

    /**
     * The ledger is the source of spend (D-08): the generation's own `user_charge_nanodollars`
     * column is never read, so a row whose column disagrees still shows its ledger charge.
     */
    public function test_spend_comes_from_the_ledger_not_the_generations_charge_column(): void
    {
        $generation = $this->givenASucceededGeneration('2026-11-16T09:00:00Z');
        DB::table('generations')->where('id', $generation->id)->update(['user_charge_nanodollars' => 1]);

        $report = $this->report('2026-11-16', '2026-11-16');

        $this->assertFigures([1, self::CHARGE, self::PROVIDER_COST], $report->days['2026-11-16']);
    }

    /**
     * One aggregate query per range, however many users and days it covers: no N+1.
     */
    public function test_one_query_reads_the_whole_range(): void
    {
        foreach (['2026-11-14T09:00:00Z', '2026-11-15T09:00:00Z', '2026-11-16T09:00:00Z'] as $completedAt) {
            $this->givenASucceededGeneration($completedAt);
        }
        Generation::factory()->providerCostOnly()->create(['completed_at' => CarbonImmutable::parse('2026-11-16T10:00:00Z')]);

        DB::enableQueryLog();
        $report = $this->report('2026-11-14', '2026-11-16');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(4, $report->users);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('"generations"', $queries[0]['query']);
    }

    /**
     * AC 13: users by spend descending, then by email; each user's totals sum their days.
     */
    public function test_users_are_sorted_by_spend_then_email_and_sum_their_days(): void
    {
        $zoe = User::factory()->create(['email' => 'zoe@example.com']);
        $ada = User::factory()->create(['email' => 'ada@example.com']);
        $bob = User::factory()->create(['email' => 'bob@example.com']);
        $this->givenASucceededGeneration('2026-11-16T09:00:00Z', ['user_id' => $zoe->id]);
        $this->givenASucceededGeneration('2026-11-17T09:00:00Z', ['user_id' => $zoe->id]);
        $this->givenASucceededGeneration('2026-11-16T10:00:00Z', ['user_id' => $bob->id]);
        $this->givenASucceededGeneration('2026-11-17T10:00:00Z', ['user_id' => $ada->id]);
        Generation::factory()->providerCostOnly()->create(['user_id' => $ada->id, 'completed_at' => CarbonImmutable::parse('2026-11-17T11:00:00Z')]);

        $report = $this->report('2026-11-16', '2026-11-17');

        $this->assertSame(['zoe@example.com', 'ada@example.com', 'bob@example.com'], array_map(fn ($user): string => $user->email, $report->users));
        $this->assertFigures([2, 2 * self::CHARGE, 2 * self::PROVIDER_COST], $report->users[0]->figures);
        $this->assertFigures([2, self::CHARGE, 2 * self::PROVIDER_COST], $report->users[1]->figures);
        $this->assertFigures([1, self::CHARGE, self::PROVIDER_COST], $report->users[2]->figures);

        $this->assertFigures([2, 2 * self::CHARGE, 2 * self::PROVIDER_COST], $report->days['2026-11-16']);
        $this->assertFigures([3, 2 * self::CHARGE, 3 * self::PROVIDER_COST], $report->days['2026-11-17']);
        $this->assertEquals($report->totals(), array_reduce($report->users, fn (ReportFigures $sum, $user): ReportFigures => $sum->plus($user->figures), ReportFigures::zero()));
    }

    /**
     * AC 12: every figure reaches the page as a PHP int, sums over several rows included.
     */
    public function test_every_figure_is_an_int(): void
    {
        $user = User::factory()->create();
        $this->givenASucceededGeneration('2026-11-16T09:00:00Z', ['user_id' => $user->id]);
        $this->givenASucceededGeneration('2026-11-16T10:00:00Z', ['user_id' => $user->id]);

        $report = $this->report('2026-11-16', '2026-11-17')->toArray();

        $this->assertSame(
            ['date' => '2026-11-16', 'succeeded' => 2, 'spend_nanodollars' => 2 * self::CHARGE, 'provider_cost_nanodollars' => 2 * self::PROVIDER_COST, 'profit_nanodollars' => 2 * (self::CHARGE - self::PROVIDER_COST)],
            $report['days'][1],
        );
        $this->assertSame(
            ['user_id' => $user->id, 'email' => $user->email, 'succeeded' => 2, 'spend_nanodollars' => 2 * self::CHARGE, 'provider_cost_nanodollars' => 2 * self::PROVIDER_COST, 'profit_nanodollars' => 2 * (self::CHARGE - self::PROVIDER_COST)],
            $report['users'][0],
        );
        $this->assertSame(
            ['succeeded' => 2, 'spend_nanodollars' => 2 * self::CHARGE, 'provider_cost_nanodollars' => 2 * self::PROVIDER_COST, 'profit_nanodollars' => 2 * (self::CHARGE - self::PROVIDER_COST)],
            $report['totals'],
        );
    }

    /**
     * A succeeded generation from the factory, completed at that instant and charged now.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function givenASucceededGeneration(string $completedAt, array $attributes = []): Generation
    {
        return Generation::factory()->succeeded()->create(['completed_at' => CarbonImmutable::parse($completedAt), ...$attributes]);
    }

    private function report(string $startDate, string $endDate): OperatorReportData
    {
        return $this->app->make(ShowOperatorReportAction::class)->execute(ReportRange::fromDates($startDate, $endDate));
    }

    /**
     * @param  array{int, int, int}  $expected  succeeded, spend and provider cost
     */
    private function assertFigures(array $expected, ReportFigures $figures): void
    {
        $this->assertSame($expected, [$figures->succeeded, $figures->spend->amount, $figures->providerCost->amount]);
    }
}
