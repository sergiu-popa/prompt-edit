<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Generation\DataTransferObjects\NewGenerationData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\SubmitAttemptOutcome;
use App\Domain\Generation\Enums\SubmitRetryNote;
use App\Domain\Generation\Models\Generation;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Jobs\SubmitGenerationJob;
use App\Models\User;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\DeliversPixelforgeWebhooks;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\Concerns\ReadsGenerationHistory;
use Tests\TestCase;

/**
 * Whole submit sequences: retries create once and charge once, an ambiguous
 * attempt is never failed here, and a generation that may exist at Pixelforge is
 * handed to the reconciler at its window's end with its bookkeeping in place.
 */
final class SubmitGenerationRetriesTest extends TestCase
{
    use DeliversPixelforgeWebhooks;
    use InteractsWithCreditLedger;
    use InteractsWithPixelforgeApi;
    use ReadsGenerationHistory;
    use RefreshDatabase;

    private const string START = '2026-11-20T10:00:00Z';

    private const string READ_TIMEOUT = 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::START));
    }

    /**
     * AC 10: one create of two requests, under one key, applied once.
     */
    public function test_an_overload_then_an_acceptance_stores_one_provider_id_and_only_the_hold(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        $accepted = self::generationObjectFor($lighthouse, GenerationStatus::Queued);
        Http::fake(['*' => Http::sequence()
            ->pushResponse(self::overloaded())
            ->pushResponse(self::pixelforgeAccepted($accepted))]);

        $this->runAndWait($lighthouse);
        $this->runAndWait($lighthouse);

        [$first, $second] = $this->recordedCreateRequests();
        $this->assertSame($first->header('Idempotency-Key'), $second->header('Idempotency-Key'));
        $this->assertSame($first->body(), $second->body());
        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Queued, $row->status);
        $this->assertSame($accepted['id'], $row->provider_id);
        $this->assertSame(1, Generation::query()->whereNotNull('provider_id')->count());
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($lighthouse));
    }

    /**
     * AC 10: the replay has moved on to `succeeded`, and is applied exactly once.
     */
    public function test_an_overload_then_a_replay_that_already_succeeded_is_charged_once(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        $succeeded = self::lighthouseSucceededObjectFor($lighthouse);
        Http::fake(['*' => Http::sequence()
            ->pushResponse(self::overloaded())
            ->pushResponse(self::pixelforgeAccepted($succeeded, replayed: 'true'))]);

        $this->runAndWait($lighthouse);
        $this->runAndWait($lighthouse);
        $charged = [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)];
        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', $succeeded))->assertOk();

        $this->assertSame(GenerationStatus::Succeeded, $this->reloadedGeneration($lighthouse)->status);
        $this->assertSame([['hold', -457_150_000], ['release', 457_150_000], ['charge', -381_887_500]], $this->ledgerEntriesFor($lighthouse));
        $this->assertSame($charged, [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)]);
    }

    /**
     * AC 16: `A lighthouse at dusk`, medium, 1024x1024, no references, holds
     * 86,287,500 n$; usage 5 / 0 / 1,100 / 1,500 costs 62,775,000 n$ and is
     * charged 78,468,750 n$, once, though the webhook arrives twice.
     */
    public function test_retries_never_produce_a_second_charge(): void
    {
        $dusk = $this->createGenerationFor(User::factory()->create(), new NewGenerationData('A lighthouse at dusk', ImageSize::Square, ImageQuality::Medium, []));
        Http::fake(['*' => Http::sequence()
            ->pushResponse(self::overloaded())
            ->pushResponse(self::pixelforgeAccepted(self::generationObjectFor($dusk, GenerationStatus::Queued)))]);
        $this->runAndWait($dusk);
        $this->runAndWait($dusk);
        $succeeded = self::generationObjectFor($this->reloadedGeneration($dusk), GenerationStatus::Succeeded, ['usage' => [
            'input_text_tokens' => 5,
            'input_image_tokens' => 0,
            'output_tokens' => 1_100,
            'reasoning_tokens' => 1_500,
            'total_tokens' => 2_605,
        ]]);

        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', $succeeded))->assertOk();
        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', $succeeded))->assertOk();

        $row = $this->reloadedGeneration($dusk);
        $this->assertCount(2, $this->recordedCreateRequests());
        $this->assertSame(GenerationStatus::Succeeded, $row->status);
        $this->assertSame(62_775_000, $row->provider_cost_nanodollars);
        $this->assertSame(78_468_750, $row->user_charge_nanodollars);
        $this->assertSame([['hold', -86_287_500], ['release', 86_287_500], ['charge', -78_468_750]], $this->ledgerEntriesFor($dusk));
        $this->assertSame(-78_468_750, $this->ledgerNetFor($dusk));
    }

    /**
     * AC 18: every retry is the same request, byte for byte.
     */
    public function test_an_overload_a_timeout_and_an_acceptance_send_byte_identical_requests(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => Http::sequence()
            ->pushResponse(self::overloaded())
            ->pushFailedConnection(self::READ_TIMEOUT)
            ->pushResponse(self::pixelforgeAccepted(self::generationObjectFor($lighthouse, GenerationStatus::Queued)))]);

        $this->runAndWait($lighthouse);
        $this->runAndWait($lighthouse);
        $this->runAndWait($lighthouse);

        $requests = $this->recordedCreateRequests();
        $this->assertCount(3, $requests);

        foreach ($requests as $request) {
            $this->assertSame($requests[0]->method(), $request->method());
            $this->assertSame($requests[0]->url(), $request->url());
            $this->assertSame([$lighthouse->id], $request->header('Idempotency-Key'));
            $this->assertSame(['generation_id' => $lighthouse->id], self::decodedRequestBody($request)['metadata']);
            $this->assertSame($requests[0]->body(), $request->body());
        }

        $this->assertSame(GenerationStatus::Queued, $this->reloadedGeneration($lighthouse)->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ambiguousAnswers(): array
    {
        return [
            'a read timeout' => ['read timeout'],
            'a refused connection' => ['connection refused'],
            'a 500' => ['500'],
            'a 502' => ['502'],
            'a 504' => ['504'],
            'a 503 without overloaded' => ['503 maintenance'],
            'a 2xx that is not a generation' => ['202 not a generation'],
        ];
    }

    /**
     * AC 12: it may have been created, so it is retried and never failed here.
     */
    #[DataProvider('ambiguousAnswers')]
    public function test_an_ambiguous_answer_latches_and_is_retried_but_never_failed(string $answer): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => match ($answer) {
            'read timeout' => Http::failedConnection(self::READ_TIMEOUT),
            'connection refused' => Http::failedConnection('cURL error 7: Failed to connect to pixelforge.promptedit.dev port 443: Connection refused'),
            '500' => Http::response('', 500),
            '502' => Http::response('<html><body><h1>502 Bad Gateway</h1></body></html>', 502, ['Content-Type' => 'text/html']),
            '504' => Http::response('<html><body><h1>504 Gateway Timeout</h1></body></html>', 504, ['Content-Type' => 'text/html']),
            '503 maintenance' => Http::response('{"error":{"type":"api_error","code":"maintenance","message":"Down for maintenance."}}', 503, ['Content-Type' => 'application/json', 'Retry-After' => '2']),
            '202 not a generation' => Http::response('{"status":"accepted"}', 202, ['Content-Type' => 'application/json']),
            default => $this->fail(sprintf('Unknown answer %s.', $answer)),
        }]);

        $this->runSubmitJob($lighthouse)->assertReleased(5);

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Pending, $row->status);
        $this->assertSame(SubmitAttemptOutcome::Ambiguous, $row->submit_last_outcome);
        $this->assertSameInstant(self::START, $row->submit_ambiguous_at);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($lighthouse));
    }

    /**
     * AC 13: once latched, the overload deadline no longer fails it.
     */
    public function test_a_latched_generation_overloaded_past_its_deadline_keeps_retrying(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => Http::sequence()->pushFailedConnection(self::READ_TIMEOUT)->whenEmpty(self::overloaded())]);

        $this->runAndWait($lighthouse);

        while (CarbonImmutable::now()->lessThan(CarbonImmutable::parse('2026-11-20T10:03:00Z'))) {
            $this->runAndWait($lighthouse)->assertReleased();
        }

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Pending, $row->status);
        $this->assertSame(SubmitAttemptOutcome::Overloaded, $row->submit_last_outcome);
        $this->assertSameInstant('2026-11-20T10:00:05Z', $row->submit_first_overloaded_at);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($lighthouse));
    }

    /**
     * AC 13: at the window's end it stays pending with its hold, for PE-014.
     */
    public function test_a_latched_generation_is_handed_over_at_its_windows_end(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => Http::failedConnection(self::READ_TIMEOUT)]);

        $runs = $this->runUntilItStops($lighthouse);

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Pending, $row->status);
        $this->assertNull($row->local_failure_reason);
        $this->assertNotNull($row->submit_handed_over_at);
        $this->assertSame(SubmitRetryNote::Retrying, $row->submit_retry_note);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($lighthouse));
        $this->assertCount($runs, $this->recordedCreateRequests());
        $this->assertSame('Pixelforge is busy — retrying automatically', $this->onlyHistoryItemOf(User::query()->findOrFail($row->user_id))['status_note']);
    }

    /**
     * AC 20: the ambiguous attempt had landed, and its webhook moved the row on.
     */
    public function test_a_generation_that_advanced_while_its_job_waited_is_not_sent_again(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => Http::failedConnection(self::READ_TIMEOUT)]);
        $this->runAndWait($lighthouse);
        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.processing', self::generationObjectFor($lighthouse, GenerationStatus::Processing)))->assertOk();
        $processing = [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)];

        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertNotReleased();

        $this->assertCount(1, $this->recordedCreateRequests());
        $this->assertSame(GenerationStatus::Processing, $this->reloadedGeneration($lighthouse)->status);
        $this->assertSame($processing, [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)]);
    }

    /**
     * AC 23: what PE-014's reconciler reads, after each step of a `503` → timeout →
     * window-end sequence. The hand-off is set only once the job stops for good.
     */
    public function test_the_bookkeeping_after_an_overload_a_timeout_and_the_windows_end(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        $creates = 0;
        Http::fake(function (Request $request) use (&$creates): PromiseInterface {
            $creates++;

            return $creates === 1 ? self::overloaded() : Http::failedConnection(self::READ_TIMEOUT)($request);
        });

        $this->runAndWait($lighthouse)->assertReleased(2);
        $this->assertBookkeeping($lighthouse, [
            'first_attempted_at' => '2026-11-20T10:00:00Z',
            'last_attempted_at' => '2026-11-20T10:00:00Z',
            'last_outcome' => SubmitAttemptOutcome::Overloaded,
            'ambiguous_at' => null,
            'first_overloaded_at' => '2026-11-20T10:00:00Z',
            'handed_over_at' => null,
            'retry_note' => SubmitRetryNote::Retrying,
        ]);

        $this->runAndWait($lighthouse)->assertReleased(5);
        $this->assertBookkeeping($lighthouse, [
            'first_attempted_at' => '2026-11-20T10:00:00Z',
            'last_attempted_at' => '2026-11-20T10:00:02Z',
            'last_outcome' => SubmitAttemptOutcome::Ambiguous,
            'ambiguous_at' => '2026-11-20T10:00:02Z',
            'first_overloaded_at' => '2026-11-20T10:00:00Z',
            'handed_over_at' => null,
            'retry_note' => SubmitRetryNote::Retrying,
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-11-20T10:30:00Z'));
        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertNotReleased();
        $this->assertBookkeeping($lighthouse, [
            'first_attempted_at' => '2026-11-20T10:00:00Z',
            'last_attempted_at' => '2026-11-20T10:00:02Z',
            'last_outcome' => SubmitAttemptOutcome::Ambiguous,
            'ambiguous_at' => '2026-11-20T10:00:02Z',
            'first_overloaded_at' => '2026-11-20T10:00:00Z',
            'handed_over_at' => '2026-11-20T10:30:00Z',
            'retry_note' => SubmitRetryNote::Retrying,
        ]);
        $this->assertCount(2, $this->recordedCreateRequests());
        $this->assertSame(GenerationStatus::Pending, $this->reloadedGeneration($lighthouse)->status);
    }

    /**
     * @param  array{first_attempted_at: string, last_attempted_at: string, last_outcome: SubmitAttemptOutcome, ambiguous_at: ?string, first_overloaded_at: string, handed_over_at: ?string, retry_note: SubmitRetryNote}  $expected
     */
    private function assertBookkeeping(Generation $generation, array $expected): void
    {
        $row = $this->reloadedGeneration($generation);

        $this->assertSameInstant($expected['first_attempted_at'], $row->submit_first_attempted_at);
        $this->assertSameInstant($expected['last_attempted_at'], $row->submit_last_attempted_at);
        $this->assertSame($expected['last_outcome'], $row->submit_last_outcome);
        $this->assertSameInstantOrNull($expected['ambiguous_at'], $row->submit_ambiguous_at);
        $this->assertSameInstant($expected['first_overloaded_at'], $row->submit_first_overloaded_at);
        $this->assertSameInstantOrNull($expected['handed_over_at'], $row->submit_handed_over_at);
        $this->assertSame($expected['retry_note'], $row->submit_retry_note);
    }

    private function assertSameInstantOrNull(?string $expected, ?CarbonImmutable $actual): void
    {
        if ($expected === null) {
            $this->assertNull($actual);

            return;
        }

        $this->assertSameInstant($expected, $actual);
    }

    /**
     * One run of the job, then a travel past its release, if any.
     */
    private function runAndWait(Generation $generation): SubmitGenerationJob
    {
        $job = $this->runSubmitJob($this->reloadedGeneration($generation));

        if ($job->job instanceof FakeJob && $job->job->isReleased()) {
            $this->travel((int) $job->job->releaseDelay)->seconds();
        }

        return $job;
    }

    /**
     * @return int how many runs it took until the job was not released
     */
    private function runUntilItStops(Generation $generation): int
    {
        for ($runs = 1; $runs <= 200; $runs++) {
            $job = $this->runAndWait($generation);

            if (! $job->job instanceof FakeJob || ! $job->job->isReleased()) {
                return $runs;
            }
        }

        $this->fail('The submit job was still being released after 200 runs.');
    }

    private static function overloaded(): PromiseInterface
    {
        return self::pixelforgeErrorFor(PixelforgeErrorCode::Overloaded, headers: ['Retry-After' => '2']);
    }
}
