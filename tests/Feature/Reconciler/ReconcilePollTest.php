<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciler;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use Carbon\CarbonImmutable;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\Concerns\RunsTheReconciler;
use Tests\TestCase;

/**
 * The poll step against `Http::fake`: which rows are polled and when, and how each
 * answer reaches the row through the transition action.
 */
final class ReconcilePollTest extends TestCase
{
    use CapturesLogMessages;
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;
    use RunsTheReconciler;

    private const string START = '2026-11-20T12:00:00Z';

    /**
     * The factory's hold: the definition's max user price.
     */
    private const int FACTORY_HOLD = 123_387_500;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::START));
    }

    /**
     * AC 5: a processing row last updated 2 min 50 s ago is skipped, and at 3 min
     * 10 s it is polled.
     */
    public function test_a_processing_row_is_polled_only_once_its_last_update_is_3_minutes_old(): void
    {
        $generation = $this->givenAProcessingGenerationLastUpdated(170);
        $this->answerPollsWith(fn (): PromiseInterface => self::pixelforgeRetrieved(self::generationObjectFor($generation, GenerationStatus::Processing)));

        $this->runReconciler();
        $this->assertSame([], $this->recordedRetrieveRequests());

        $this->travel(20)->seconds();
        $this->runReconciler();

        $requests = $this->recordedRetrieveRequests();
        $this->assertCount(1, $requests);
        $this->assertStringEndsWith('/generations/' . $generation->provider_id, $requests[0]->url());
    }

    /**
     * AC 6: nothing changed, so nothing is written but the reconciler's own next
     * check, a minute away for a young row, and nothing is sent before it.
     */
    public function test_an_unchanged_status_writes_nothing_but_the_next_check(): void
    {
        $generation = $this->givenAProcessingGenerationLastUpdated(200);
        $before = $this->rawGenerationRowWithoutBookkeeping($generation);
        $this->answerPollsWith(fn (): PromiseInterface => self::pixelforgeRetrieved(self::generationObjectFor($generation, GenerationStatus::Processing)));

        $this->runReconciler();

        $row = $this->reloadedGeneration($generation);
        $this->assertSame($before, $this->rawGenerationRowWithoutBookkeeping($generation));
        $this->assertSameInstant('2026-11-20T11:56:40Z', $row->last_provider_update_at);
        $this->assertSameInstant('2026-11-20T12:01:00Z', $row->reconcile_recheck_at);
        $this->assertSame([['hold', -self::FACTORY_HOLD]], $this->ledgerEntriesFor($generation));
        $this->assertSame([], $this->appliedPollLogs());

        $this->travel(59)->seconds();
        $this->runReconciler();
        $this->assertCount(1, $this->recordedRetrieveRequests());

        $this->travel(1)->seconds();
        $this->runReconciler();
        $this->assertCount(2, $this->recordedRetrieveRequests());
    }

    /**
     * AC 6: a row stuck for 2 h is polled at most 4 times in an hour, however often
     * the reconciler runs.
     */
    public function test_a_row_stuck_for_2_hours_is_polled_at_most_4_times_an_hour(): void
    {
        $generation = Generation::factory()->processing()->create([
            'created_at' => CarbonImmutable::now()->subHours(2),
            'last_provider_update_at' => CarbonImmutable::now()->subHours(2),
        ]);
        $this->answerPollsWith(fn (): PromiseInterface => self::pixelforgeRetrieved(self::generationObjectFor($generation, GenerationStatus::Processing)));

        for ($minute = 0; $minute < 60; $minute++) {
            $this->runReconciler();
            $this->travel(1)->minutes();
        }

        $this->assertCount(4, $this->recordedRetrieveRequests());
    }

    /**
     * AC 10: a poll may move a row forward without money, never back.
     */
    public function test_a_processing_poll_advances_a_queued_row_without_a_ledger_entry(): void
    {
        $generation = Generation::factory()->queued()->create(['last_provider_update_at' => CarbonImmutable::now()->subMinutes(5)]);
        $this->answerPollsWith(fn (): PromiseInterface => self::pixelforgeRetrieved(self::generationObjectFor($generation, GenerationStatus::Processing)));

        $this->runReconciler();

        $row = $this->reloadedGeneration($generation);
        $this->assertSame(GenerationStatus::Processing, $row->status);
        $this->assertSameInstant(self::START, $row->last_provider_update_at);
        $this->assertSame([['hold', -self::FACTORY_HOLD]], $this->ledgerEntriesFor($generation));
        $this->assertCount(1, $this->appliedPollLogs());
    }

    public function test_a_queued_poll_changes_nothing_on_a_processing_row(): void
    {
        $generation = $this->givenAProcessingGenerationLastUpdated(600);
        $before = $this->rawGenerationRowWithoutBookkeeping($generation);
        $this->answerPollsWith(fn (): PromiseInterface => self::pixelforgeRetrieved(self::generationObjectFor($generation, GenerationStatus::Queued)));

        $this->runReconciler();

        $this->assertCount(1, $this->recordedRetrieveRequests());
        $this->assertSame($before, $this->rawGenerationRowWithoutBookkeeping($generation));
        $this->assertSame([['hold', -self::FACTORY_HOLD]], $this->ledgerEntriesFor($generation));
    }

    /**
     * AC 18: logged with both ids, and the row is left as it was for this run.
     */
    public function test_not_found_is_a_warning_naming_both_ids_and_changes_nothing(): void
    {
        $generation = $this->givenAProcessingGenerationLastUpdated(600);
        $before = $this->rawGenerationRowWithoutBookkeeping($generation);
        $this->answerPollsWith(fn (): PromiseInterface => self::pixelforgeErrorFor(PixelforgeErrorCode::NotFound));

        $this->runReconciler();

        $this->assertSame($before, $this->rawGenerationRowWithoutBookkeeping($generation));
        $this->assertSame([['hold', -self::FACTORY_HOLD]], $this->ledgerEntriesFor($generation));
        $warnings = $this->logsWithLevel('warning');
        $this->assertCount(1, $warnings);
        $this->assertSame('Pixelforge did not find a generation we polled; it is left unchanged.', $warnings[0]->message);
        $this->assertSame(['generation_id' => $generation->id, 'provider_id' => $generation->provider_id], $warnings[0]->context);
    }

    /**
     * AC 1: a failed poll is logged and never retried inline; the row waits for its
     * next check.
     */
    public function test_a_failed_poll_is_logged_and_waits_for_the_next_check(): void
    {
        $generation = $this->givenAProcessingGenerationLastUpdated(600);
        $this->answerPollsWith(fn (): PromiseInterface => Http::response('', 500));

        $this->runReconciler();
        $this->travel(30)->seconds();
        $this->runReconciler();

        $this->assertCount(1, $this->recordedRetrieveRequests());
        $warnings = $this->logsWithLevel('warning');
        $this->assertCount(1, $warnings);
        $this->assertSame(
            ['generation_id' => $generation->id, 'provider_id' => $generation->provider_id, 'error_code' => 'ambiguous', 'status' => 500],
            $warnings[0]->context,
        );
        $this->assertSame(GenerationStatus::Processing, $this->reloadedGeneration($generation)->status);
    }

    /**
     * AC 4: one row that throws is logged with its ULID, and the run goes on.
     */
    public function test_a_row_that_cannot_be_settled_never_stops_the_run(): void
    {
        $unpriceable = $this->givenAProcessingGenerationLastUpdated(900);
        $next = $this->givenAProcessingGenerationLastUpdated(600);
        $this->answerPollsWith(fn (Generation $generation): PromiseInterface => self::pixelforgeRetrieved($generation->is($unpriceable)
            ? self::generationObjectFor($generation, GenerationStatus::Succeeded, ['rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.5005',
            ]])
            : self::generationObjectFor($generation, GenerationStatus::Succeeded)));

        $this->runReconciler();

        $this->assertSame(GenerationStatus::Processing, $this->reloadedGeneration($unpriceable)->status);
        $this->assertSame([['hold', -self::FACTORY_HOLD]], $this->ledgerEntriesFor($unpriceable));
        $this->assertSame(GenerationStatus::Succeeded, $this->reloadedGeneration($next)->status);
        $this->assertSame([['hold', -self::FACTORY_HOLD], ['release', self::FACTORY_HOLD], ['charge', -109_312_500]], $this->ledgerEntriesFor($next));

        $failed = array_values(array_filter(
            $this->logsWithLevel('error'),
            fn (MessageLogged $message): bool => str_starts_with($message->message, 'The reconciler could not handle a generation'),
        ));
        $this->assertCount(1, $failed);
        $this->assertSame($unpriceable->id, $failed[0]->context['generation_id']);
        $this->assertSame($unpriceable->provider_id, $failed[0]->context['provider_id']);
        $this->assertSame('App\Domain\Billing\Exceptions\InvalidRateException', $failed[0]->context['exception']);
    }

    /**
     * AC 16: at most 20 a run, the longest without news first.
     */
    public function test_a_run_polls_at_most_the_batch_oldest_first(): void
    {
        $generations = [];

        for ($row = 0; $row < 25; $row++) {
            $generations[] = $this->givenAProcessingGenerationLastUpdated(200 + 10 * $row);
        }

        $this->answerPollsWith(fn (Generation $generation): PromiseInterface => self::pixelforgeRetrieved(self::generationObjectFor($generation, GenerationStatus::Processing)));

        $this->runReconciler();

        $oldest = array_map(fn (Generation $generation): string => '/generations/' . $generation->provider_id, array_slice(array_reverse($generations), 0, 20));
        $this->assertSame($oldest, array_map(fn (Request $request): string => (string) strstr($request->url(), '/generations/'), $this->recordedRetrieveRequests()));
    }

    private function givenAProcessingGenerationLastUpdated(int $secondsAgo): Generation
    {
        return Generation::factory()->processing()->create([
            'created_at' => CarbonImmutable::now()->subMinutes(3),
            'last_provider_update_at' => CarbonImmutable::now()->subSeconds($secondsAgo),
        ]);
    }

    /**
     * Each retrieve is answered for the row whose provider id it asks for.
     *
     * @param  Closure(Generation): PromiseInterface  $answer
     */
    private function answerPollsWith(Closure $answer): void
    {
        Http::fake(function (Request $request) use ($answer): PromiseInterface {
            $providerId = rawurldecode(basename((string) parse_url($request->url(), PHP_URL_PATH)));

            return $answer(Generation::query()->where('provider_id', $providerId)->firstOrFail());
        });
    }

    /**
     * @return list<MessageLogged>
     */
    private function appliedPollLogs(): array
    {
        return array_values(array_filter(
            $this->loggedMessages(),
            fn (MessageLogged $message): bool => $message->message === 'Applied a polled Pixelforge snapshot to a generation.',
        ));
    }

    /**
     * @return list<MessageLogged>
     */
    private function logsWithLevel(string $level): array
    {
        return array_values(array_filter($this->loggedMessages(), fn (MessageLogged $message): bool => $message->level === $level));
    }
}
