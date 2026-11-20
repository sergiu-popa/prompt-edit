<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciler;

use App\Domain\Generation\Actions\GiveUpUnconfirmedGenerationAction;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Enums\SubmitAttemptOutcome;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\ValueObjects\SubmitBookkeeping;
use App\Jobs\SubmitGenerationJob;
use App\Models\User;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use App\Support\Pixelforge\PixelforgeCreateLock;
use Carbon\CarbonImmutable;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\DeliversPixelforgeWebhooks;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\Concerns\ReadsGenerationHistory;
use Tests\Concerns\RunsTheReconciler;
use Tests\TestCase;

/**
 * AC 20, on PE-014's red fox: a generation Pixelforge never confirmed is given up a
 * day after its creation, as `lost` and free (D-49). A late success then records
 * Pixelforge's cost only (D-09). One Pixelforge confirmed is never given up (AC 21).
 */
final class ReconcileGiveUpTest extends TestCase
{
    use CapturesLogMessages;
    use DeliversPixelforgeWebhooks;
    use InteractsWithCreditLedger;
    use InteractsWithPixelforgeApi;
    use ReadsGenerationHistory;
    use RefreshDatabase;
    use RunsTheReconciler;

    private const int DAY_SECONDS = 86_400;

    public function test_a_day_after_creation_a_handed_over_row_is_failed_lost_and_free(): void
    {
        $redFox = $this->givenTheHandedOverRedFox();
        $this->travel(self::DAY_SECONDS)->seconds();

        $this->runReconciler();

        $row = $this->reloadedGeneration($redFox);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(LocalFailureReason::Lost, $row->local_failure_reason);
        $this->assertSame(0, $row->provider_cost_nanodollars);
        $this->assertSame(0, $row->user_charge_nanodollars);
        $this->assertSame([['hold', -self::RED_FOX_HOLD], ['release', self::RED_FOX_HOLD]], $this->ledgerEntriesFor($redFox));
        $this->assertSame([], $this->redispatchedJobs());

        $errors = array_values(array_filter($this->loggedMessages(), fn (MessageLogged $message): bool => $message->level === 'error'));
        $this->assertCount(1, $errors);
        $this->assertSame('Gave up on a generation Pixelforge never confirmed; it is failed as lost and not charged.', $errors[0]->message);
        $this->assertSame($redFox->id, $errors[0]->context['generation_id']);
        $this->assertSame('applied', $errors[0]->context['outcome']);
    }

    /**
     * A re-dispatched create may still be in flight at the day's end. The give-up
     * waits for its lock, so that create cannot land on a `lost` row; the next run,
     * with the lock free, gives up.
     */
    public function test_a_row_whose_create_is_in_flight_is_given_up_at_the_next_run(): void
    {
        $redFox = $this->givenTheHandedOverRedFox();
        $this->travel(self::DAY_SECONDS)->seconds();
        $inFlightCreate = app(PixelforgeCreateLock::class)->for($redFox->id);
        $this->assertTrue($inFlightCreate->get());

        $this->runReconciler();

        $this->assertSame(GenerationStatus::Pending, $this->reloadedGeneration($redFox)->status);
        $this->assertSame([['hold', -self::RED_FOX_HOLD]], $this->ledgerEntriesFor($redFox));
        $this->assertSame([], array_values(array_filter($this->loggedMessages(), fn (MessageLogged $message): bool => $message->level === 'error')));
        $skipped = array_values(array_filter($this->loggedMessages(), fn (MessageLogged $message): bool => str_starts_with($message->message, 'A submit run holds the create lock')));
        $this->assertCount(1, $skipped);
        $this->assertSame('info', $skipped[0]->level);
        $this->assertSame($redFox->id, $skipped[0]->context['generation_id']);

        $inFlightCreate->release();
        $this->travel(1)->minutes();
        $this->runReconciler();

        $this->assertSame(LocalFailureReason::Lost, $this->reloadedGeneration($redFox)->local_failure_reason);
        $this->assertSame([['hold', -self::RED_FOX_HOLD], ['release', self::RED_FOX_HOLD]], $this->ledgerEntriesFor($redFox));
    }

    /**
     * The give-up race: a re-dispatched create lands between the selection and the
     * transition. The transition refuses and logs the conflict, and nothing claims
     * the generation went free.
     */
    public function test_a_row_confirmed_after_its_selection_is_not_given_up(): void
    {
        $redFox = $this->givenTheHandedOverRedFox();
        $this->travel(self::DAY_SECONDS)->seconds();
        $selected = $this->reloadedGeneration($redFox);
        $this->transitionGeneration($redFox, self::snapshotFor($redFox, GenerationStatus::Queued));
        $confirmed = $this->reloadedGeneration($redFox);

        app(GiveUpUnconfirmedGenerationAction::class)->execute($selected);

        $row = $this->reloadedGeneration($redFox);
        $this->assertSame(GenerationStatus::Queued, $row->status);
        $this->assertSame($confirmed->provider_id, $row->provider_id);
        $this->assertNull($row->local_failure_reason);
        $this->assertSame([['hold', -self::RED_FOX_HOLD]], $this->ledgerEntriesFor($redFox));

        $messages = $this->loggedMessages();
        $this->assertSame([], array_values(array_filter($messages, fn (MessageLogged $message): bool => str_starts_with($message->message, 'Gave up'))));
        $movedOn = array_values(array_filter($messages, fn (MessageLogged $message): bool => str_starts_with($message->message, 'A generation moved on before the reconciler gave up on it')));
        $this->assertCount(1, $movedOn);
        $this->assertSame('info', $movedOn[0]->level);
        $this->assertSame($redFox->id, $movedOn[0]->context['generation_id']);
        $this->assertSame('refused_conflict', $movedOn[0]->context['outcome']);
    }

    public function test_a_second_before_the_day_it_is_redispatched_instead(): void
    {
        $redFox = $this->givenTheHandedOverRedFox();
        $this->travel(self::DAY_SECONDS - 1)->seconds();

        $this->runReconciler();

        $this->assertSame(GenerationStatus::Pending, $this->reloadedGeneration($redFox)->status);
        $this->assertSame([$redFox->id], array_map(fn (SubmitGenerationJob $job): string => $job->generationId, $this->redispatchedJobs()));
    }

    /**
     * The webhook names our ULID, so it is matched; the row stays failed and free.
     */
    public function test_a_lost_row_that_later_succeeds_records_the_provider_cost_only(): void
    {
        $redFox = $this->givenTheHandedOverRedFox();
        $this->travel(self::DAY_SECONDS)->seconds();
        $this->runReconciler();
        $ledger = $this->ledgerEntriesFor($redFox);

        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', self::generationObjectFor($redFox, GenerationStatus::Succeeded, [
            'usage' => [
                'input_text_tokens' => 5,
                'input_image_tokens' => 0,
                'output_tokens' => 280,
                'reasoning_tokens' => 312,
                'total_tokens' => 597,
            ],
        ])))->assertOk();

        $row = $this->reloadedGeneration($redFox);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(15_125_000, $row->provider_cost_nanodollars);
        $this->assertSame(0, $row->user_charge_nanodollars);
        $this->assertSame($ledger, $this->ledgerEntriesFor($redFox));

        $item = $this->onlyHistoryItemOf(User::query()->findOrFail($redFox->user_id));
        $this->assertSame('failed', $item['status']);
        $this->assertSame('We couldn\'t confirm this generation with Pixelforge. You were not charged.', $item['failure_message']);
        $this->assertNull($item['user_charge_nanodollars']);
        $this->assertNull($item['image_url']);
    }

    /**
     * AC 21: never given up, because Pixelforge confirmed it. After a day of
     * `not_found` the operator gets one error log, whatever further runs answer.
     */
    public function test_a_confirmed_row_not_found_for_over_a_day_is_reported_once_and_keeps_its_hold(): void
    {
        $redFox = $this->givenTheSubmittedRedFox();
        $this->answerPollsWith(fn (): PromiseInterface => self::pixelforgeErrorFor(PixelforgeErrorCode::NotFound));
        $this->travel(5)->minutes();
        $this->runReconciler();
        $since = CarbonImmutable::now()->startOfSecond();

        $this->travel(self::DAY_SECONDS + 1)->seconds();

        for ($run = 0; $run < 4; $run++) {
            $this->runReconciler();
            $this->travel(15)->minutes();
        }

        $row = $this->reloadedGeneration($redFox);
        $this->assertCount(5, $this->recordedRetrieveRequests());
        $this->assertSame(GenerationStatus::Queued, $row->status);
        $this->assertSame([['hold', -self::RED_FOX_HOLD]], $this->ledgerEntriesFor($redFox));
        $this->assertTrue($row->reconcile_not_found_since?->equalTo($since));
        $this->assertNotNull($row->reconcile_not_found_reported_at);

        $reports = $this->notFoundReports();
        $this->assertCount(1, $reports);
        $this->assertSame('error', $reports[0]->level);
        $this->assertSame($redFox->id, $reports[0]->context['generation_id']);
        $this->assertSame($row->provider_id, $reports[0]->context['provider_id']);
    }

    public function test_a_day_of_not_found_is_not_yet_too_long(): void
    {
        $this->givenTheSubmittedRedFox();
        $this->answerPollsWith(fn (): PromiseInterface => self::pixelforgeErrorFor(PixelforgeErrorCode::NotFound));
        $this->travel(5)->minutes();
        $this->runReconciler();

        $this->travel(self::DAY_SECONDS)->seconds();
        $this->runReconciler();

        $this->assertCount(2, $this->recordedRetrieveRequests());
        $this->assertSame([], $this->notFoundReports());
    }

    public function test_any_snapshot_ends_the_not_found_streak(): void
    {
        $redFox = $this->givenTheSubmittedRedFox();
        $answer = fn (): PromiseInterface => self::pixelforgeErrorFor(PixelforgeErrorCode::NotFound);
        $this->answerPollsWith(function () use (&$answer): PromiseInterface {
            return $answer();
        });
        $this->travel(5)->minutes();
        $this->runReconciler();
        $this->assertNotNull($this->reloadedGeneration($redFox)->reconcile_not_found_since);

        $answer = fn (): PromiseInterface => self::pixelforgeRetrieved(self::generationObjectFor($redFox, GenerationStatus::Queued));
        $this->travel(5)->minutes();
        $this->runReconciler();

        $row = $this->reloadedGeneration($redFox);
        $this->assertCount(2, $this->recordedRetrieveRequests());
        $this->assertNull($row->reconcile_not_found_since);
        $this->assertNull($row->reconcile_not_found_reported_at);
    }

    /**
     * Its create was answered `202`, so it has a provider id and is `queued`.
     */
    private function givenTheSubmittedRedFox(): Generation
    {
        $redFox = $this->givenTheRedFoxGeneration();
        $this->transitionGeneration($redFox, self::snapshotFor($redFox, GenerationStatus::Queued));

        return $this->reloadedGeneration($redFox);
    }

    /**
     * @param  Closure(): PromiseInterface  $answer
     */
    private function answerPollsWith(Closure $answer): void
    {
        Http::fake(fn (): PromiseInterface => $answer());
    }

    /**
     * @return list<MessageLogged>
     */
    private function notFoundReports(): array
    {
        return array_values(array_filter(
            $this->loggedMessages(),
            fn (MessageLogged $message): bool => str_starts_with($message->message, 'Pixelforge has not found a generation it confirmed'),
        ));
    }

    /**
     * Its one attempt timed out, and PE-013 handed it over at its window's end.
     */
    private function givenTheHandedOverRedFox(): Generation
    {
        $redFox = $this->givenTheRedFoxGeneration();
        $attemptedAt = CarbonImmutable::now();
        $redFox->recordSubmitBookkeeping((new SubmitBookkeeping)
            ->startAttempt($attemptedAt)
            ->finishAttempt(SubmitAttemptOutcome::Ambiguous, $attemptedAt)
            ->handOver($attemptedAt->addMinutes(30)));

        return $this->reloadedGeneration($redFox);
    }

    /**
     * @return list<SubmitGenerationJob>
     */
    private function redispatchedJobs(): array
    {
        $queue = Queue::getFacadeRoot();
        $this->assertInstanceOf(QueueFake::class, $queue);

        return array_values(array_filter(
            $queue->pushed(SubmitGenerationJob::class)->all(),
            fn (mixed $job): bool => $job instanceof SubmitGenerationJob && $job->redispatchedAt !== null,
        ));
    }
}
