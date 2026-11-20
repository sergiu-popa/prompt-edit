<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Enums\SubmitRetryNote;
use App\Domain\Generation\Models\Generation;
use App\Models\User;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\Concerns\ReadsGenerationHistory;
use Tests\TestCase;

/**
 * Pixelforge's account limits at submit: waiting for a free slot, the daily limit
 * and a refused key. Each fails free only while nothing may exist at Pixelforge.
 */
final class SubmitGenerationLimitsTest extends TestCase
{
    use CapturesLogMessages;
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use InteractsWithPixelforgeApi;
    use ReadsGenerationHistory;
    use RefreshDatabase;

    private const string START = '2026-11-20T10:00:00Z';

    private const string READ_TIMEOUT = 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received';

    private const array LIGHTHOUSE_FREE = [['hold', -457_150_000], ['release', 457_150_000]];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::START));
    }

    /**
     * AC 5: the docs' `Retry-After: 5`, and a marker that survives a reload.
     */
    public function test_a_concurrency_limit_waits_for_a_free_slot(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => self::concurrencyLimited()]);

        $this->runSubmitJob($lighthouse)->assertReleased(5);

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Pending, $row->status);
        $this->assertSame(SubmitRetryNote::WaitingForSlot, $row->submit_retry_note);
        $this->assertSameInstant(self::START, $row->submit_first_concurrency_limited_at);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($lighthouse));
        $this->assertSame('Waiting for a free slot', $this->onlyHistoryItemOf(User::query()->findOrFail($row->user_id))['status_note']);
    }

    /**
     * AC 6: the marker goes as soon as Pixelforge accepts it.
     */
    public function test_the_free_slot_marker_clears_once_pixelforge_accepts_it(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => Http::sequence()
            ->pushResponse(self::concurrencyLimited())
            ->pushResponse(self::pixelforgeAccepted(self::generationObjectFor($lighthouse, GenerationStatus::Queued)))]);
        $this->runSubmitJob($lighthouse)->assertReleased(5);
        $this->travel(5)->seconds();

        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertNotReleased();

        $item = $this->onlyHistoryItemOf(User::query()->findOrFail($lighthouse->user_id));
        $this->assertSame('queued', $item['status']);
        $this->assertNull($item['status_note']);
    }

    /**
     * AC 7: no slot 10 min after the first concurrency 429.
     */
    public function test_a_slot_that_never_comes_fails_it_free_after_the_max_wait(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => self::concurrencyLimited()]);
        $this->runSubmitJob($lighthouse)->assertReleased(5);
        $this->travel(599)->seconds();
        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertReleased(5);
        $this->travel(1)->seconds();

        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertNotReleased();

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(LocalFailureReason::ProviderBusy, $row->local_failure_reason);
        $this->assertSame('concurrency_limit_exceeded', $row->error_code);
        $this->assertSame(0, $row->user_charge_nanodollars);
        $this->assertSame(self::LIGHTHOUSE_FREE, $this->ledgerEntriesFor($lighthouse));
    }

    /**
     * AC 13: an earlier attempt may have landed, so it keeps waiting.
     */
    public function test_a_latched_generation_keeps_waiting_for_a_slot_past_the_max_wait(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => Http::sequence()->pushFailedConnection(self::READ_TIMEOUT)->whenEmpty(self::concurrencyLimited())]);
        $this->runSubmitJob($lighthouse)->assertReleased(5);
        $this->travel(5)->seconds();
        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertReleased(5);
        $this->travel(600)->seconds();

        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertReleased(5);

        $this->assertSame(GenerationStatus::Pending, $this->reloadedGeneration($lighthouse)->status);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($lighthouse));
    }

    /**
     * AC 8: no `Retry-After`, no further call, and its hold released.
     */
    public function test_the_daily_limit_fails_it_free_at_once(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => self::pixelforgeErrorFor(PixelforgeErrorCode::DailyLimitExceeded)]);

        $this->runSubmitJob($lighthouse)->assertNotReleased();
        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertNotReleased();

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertCount(1, $this->recordedCreateRequests());
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(LocalFailureReason::DailyLimit, $row->local_failure_reason);
        $this->assertSame('daily_limit_exceeded', $row->error_code);
        $this->assertSame(0, $row->provider_cost_nanodollars);
        $this->assertSame(0, $row->user_charge_nanodollars);
        $this->assertSame(self::LIGHTHOUSE_FREE, $this->ledgerEntriesFor($lighthouse));
    }

    public function test_a_latched_generation_at_the_daily_limit_checks_back_a_minute_later(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection(self::READ_TIMEOUT)
            ->whenEmpty(self::pixelforgeErrorFor(PixelforgeErrorCode::DailyLimitExceeded))]);
        $this->runSubmitJob($lighthouse)->assertReleased(5);
        $this->travel(5)->seconds();

        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertReleased(60);

        $this->assertSame(GenerationStatus::Pending, $this->reloadedGeneration($lighthouse)->status);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($lighthouse));
    }

    /**
     * AC 14: a key rotated mid-deploy gets 3 tries in 40 s; then it is free, with one
     * critical alert.
     */
    public function test_a_refused_key_is_tried_three_times_then_fails_free_with_one_alert(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => self::pixelforgeErrorFor(PixelforgeErrorCode::InvalidApiKey)]);

        $this->runSubmitJob($lighthouse)->assertReleased(20);
        $this->travel(20)->seconds();
        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertReleased(20);
        $this->travel(20)->seconds();
        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertNotReleased();

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertCount(3, $this->recordedCreateRequests());
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(LocalFailureReason::ServiceUnavailable, $row->local_failure_reason);
        $this->assertSame(3, $row->submit_invalid_api_key_count);
        $this->assertSame(self::LIGHTHOUSE_FREE, $this->ledgerEntriesFor($lighthouse));
        $this->assertOneSecretFreeAlertFor($lighthouse);
    }

    /**
     * AC 13 and 14: latched, it is never failed here; the alert is still written once.
     */
    public function test_a_latched_generation_whose_key_is_refused_keeps_trying_with_one_alert(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection(self::READ_TIMEOUT)
            ->whenEmpty(self::pixelforgeErrorFor(PixelforgeErrorCode::InvalidApiKey))]);
        $this->runSubmitJob($lighthouse)->assertReleased(5);
        $this->travel(5)->seconds();
        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertReleased(20);
        $this->travel(20)->seconds();
        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertReleased(20);
        $this->travel(20)->seconds();

        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertReleased(60);
        $this->travel(60)->seconds();
        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertReleased(60);

        $this->assertSame(GenerationStatus::Pending, $this->reloadedGeneration($lighthouse)->status);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($lighthouse));
        $this->assertOneSecretFreeAlertFor($lighthouse);
    }

    private function assertOneSecretFreeAlertFor(Generation $generation): void
    {
        $alerts = array_values(array_filter(
            $this->loggedMessages(),
            fn (MessageLogged $logged): bool => $logged->level === 'critical',
        ));

        $this->assertCount(1, $alerts);
        $this->assertSame(['generation_id' => $generation->id, 'error_code' => 'invalid_api_key', 'status' => 401], $alerts[0]->context);

        $logged = $alerts[0]->message . json_encode($alerts[0]->context, JSON_THROW_ON_ERROR);

        foreach ([self::PIXELFORGE_API_KEY, 'Bearer', 'Authorization', 'Idempotency-Key', 'Content-Type', 'Accept'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $logged);
        }

        $this->assertNoLogLineCarriesTheKey();
    }

    /**
     * Every line the refused key led to, at any level, with its context.
     */
    private function assertNoLogLineCarriesTheKey(): void
    {
        $this->assertNotEmpty($this->loggedMessages());

        foreach ($this->loggedMessages() as $logged) {
            $line = $logged->message . json_encode($logged->context, JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString(self::PIXELFORGE_API_KEY, $line);
            $this->assertStringNotContainsStringIgnoringCase('Bearer', $line);
            $this->assertStringNotContainsStringIgnoringCase('Authorization', $line);
        }
    }

    private static function concurrencyLimited(): PromiseInterface
    {
        return self::pixelforgeErrorFor(PixelforgeErrorCode::ConcurrencyLimitExceeded, headers: ['Retry-After' => '5']);
    }
}
