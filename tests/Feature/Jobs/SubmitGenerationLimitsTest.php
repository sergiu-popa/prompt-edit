<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Enums\SubmitRetryNote;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;

/**
 * Pixelforge's account limits at submit: waiting for a free slot, the daily limit
 * and a refused key. Each fails free only while nothing may exist at Pixelforge.
 */
final class SubmitGenerationLimitsTest extends TestCase
{
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use InteractsWithPixelforgeApi;
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

    private static function concurrencyLimited(): PromiseInterface
    {
        return self::pixelforgeErrorFor(PixelforgeErrorCode::ConcurrencyLimitExceeded, headers: ['Retry-After' => '5']);
    }
}
