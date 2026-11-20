<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AC 6: the range is validated with field errors, never exceptions, and an invalid range
 * goes back to the default report without running the report's query (D-65).
 */
final class ShowOperatorReportValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private string $defaultTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultTimezone = date_default_timezone_get();
        $this->travelTo(CarbonImmutable::parse('2026-11-18T12:00:00Z'));
        $this->operator = User::factory()->operator()->create();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);

        parent::tearDown();
    }

    public function test_a_range_of_92_days_is_accepted(): void
    {
        $this->report(['start_date' => '2026-08-01', 'end_date' => '2026-10-31'])
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertInertia(fn (Assert $page) => $page
                ->where('range', ['start_date' => '2026-08-01', 'end_date' => '2026-10-31'])
                ->has('report.days', 92)
                ->where('report.days.0.date', '2026-10-31')
                ->where('report.days.91.date', '2026-08-01'));
    }

    public function test_a_range_of_93_days_is_rejected_without_reading_generations(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->report(['start_date' => '2026-08-01', 'end_date' => '2026-11-01'])
            ->assertRedirect('/report')
            ->assertSessionHasErrors(['end_date' => 'The range can be at most 92 days.'])
            ->assertSessionDoesntHaveErrors(['start_date']);

        $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'generations'))));
    }

    public function test_a_start_after_the_end_is_a_start_date_error(): void
    {
        $this->report(['start_date' => '2026-11-10', 'end_date' => '2026-11-09'])
            ->assertRedirect('/report')
            ->assertSessionHasErrors(['start_date' => 'The start date must be on or before the end date.'])
            ->assertSessionDoesntHaveErrors(['end_date']);
    }

    public function test_an_end_after_today_in_utc_is_an_end_date_error(): void
    {
        $this->report(['start_date' => '2026-11-10', 'end_date' => '2026-11-19'])
            ->assertRedirect('/report')
            ->assertSessionHasErrors(['end_date' => "The end date can't be after today (UTC)."])
            ->assertSessionDoesntHaveErrors(['start_date']);
    }

    /**
     * At 23:59:59Z it is still the 18th in UTC, though already the 19th in UTC+14.
     */
    public function test_an_end_of_today_in_utc_is_accepted(): void
    {
        date_default_timezone_set('Pacific/Kiritimati');
        $this->travelTo(CarbonImmutable::parse('2026-11-18T23:59:59Z'));

        $this->report(['start_date' => '2026-11-18', 'end_date' => '2026-11-18'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('range', ['start_date' => '2026-11-18', 'end_date' => '2026-11-18']));

        $this->report(['start_date' => '2026-11-18', 'end_date' => '2026-11-19'])
            ->assertSessionHasErrors(['end_date' => "The end date can't be after today (UTC)."]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('invalidDates')]
    public function test_an_invalid_date_is_a_field_error(array $query, string $field): void
    {
        $this->report($query)
            ->assertRedirect('/report')
            ->assertSessionHasErrors([$field => 'Enter a valid date.']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidDates(): array
    {
        return [
            'February 30th' => [['start_date' => '2026-02-30', 'end_date' => '2026-03-01'], 'start_date'],
            'an unpadded date' => [['start_date' => '2026-11-01', 'end_date' => '2026-11-5'], 'end_date'],
            'a date and time' => [['start_date' => '2026-11-01 00:00:00', 'end_date' => '2026-11-05'], 'start_date'],
            'a word' => [['start_date' => '2026-11-01', 'end_date' => 'today'], 'end_date'],
            'an array' => [['start_date' => ['2026-11-01'], 'end_date' => '2026-11-05'], 'start_date'],
        ];
    }

    /**
     * An invalid end date is reported once, without an order error beside it.
     */
    public function test_an_invalid_date_adds_no_order_or_span_error(): void
    {
        $this->report(['start_date' => '2026-11-10', 'end_date' => '2026-02-30'])
            ->assertSessionHasErrors(['end_date' => 'Enter a valid date.'])
            ->assertSessionDoesntHaveErrors(['start_date']);
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('oneDateOnly')]
    public function test_one_date_alone_requires_the_other(array $query, string $field, string $message): void
    {
        $this->report($query)
            ->assertRedirect('/report')
            ->assertSessionHasErrors([$field => $message]);
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function oneDateOnly(): array
    {
        return [
            'start only' => [['start_date' => '2026-11-01'], 'end_date', 'Enter an end date.'],
            'end only' => [['end_date' => '2026-11-01'], 'start_date', 'Enter a start date.'],
            'an empty end' => [['start_date' => '2026-11-01', 'end_date' => ''], 'end_date', 'Enter an end date.'],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('noDates')]
    public function test_no_dates_is_the_default_range(array $query): void
    {
        $this->report($query)
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertInertia(fn (Assert $page) => $page->where('range', ['start_date' => '2026-10-20', 'end_date' => '2026-11-18']));
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function noDates(): array
    {
        return [
            'no params' => [[]],
            'both empty' => [['start_date' => '', 'end_date' => '']],
        ];
    }

    /**
     * A full page load of an invalid URL lands on the default report with the error.
     */
    public function test_following_the_redirect_shows_the_default_range_with_the_error(): void
    {
        $this->actingAs($this->operator)
            ->followingRedirects()
            ->get('/report?start_date=2026-08-01&end_date=2026-11-01')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('range', ['start_date' => '2026-10-20', 'end_date' => '2026-11-18'])
                ->where('errors.end_date', 'The range can be at most 92 days.'));
    }

    /**
     * `can:` runs before the request is validated, so a non-operator never learns more.
     */
    public function test_a_non_operator_with_an_invalid_range_still_gets_403(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/report?start_date=2026-08-01&end_date=2026-11-01')
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function report(array $query): TestResponse
    {
        return $this->actingAs($this->operator)->get('/report?' . http_build_query($query));
    }
}
