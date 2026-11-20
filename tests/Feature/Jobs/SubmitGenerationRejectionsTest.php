<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Generation\DataTransferObjects\NewGenerationData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Models\Generation;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Jobs\SubmitGenerationJob;
use App\Models\User;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use App\Support\Pixelforge\Exceptions\AmbiguousPixelforgeOutcomeException;
use App\Support\Pixelforge\Exceptions\OverloadedException;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ResponseTransferException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;
use Throwable;

/**
 * Pixelforge's answers that are not a generation. Only a definitive rejection,
 * where Pixelforge created nothing, fails the generation here, at no charge.
 */
final class SubmitGenerationRejectionsTest extends TestCase
{
    use CapturesLogMessages;
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $error
     */
    #[DataProvider('definitiveRejections')]
    public function test_a_definitive_rejection_fails_the_generation_at_no_charge(
        PixelforgeErrorCode $code,
        array $error,
        LocalFailureReason $reason,
        ?string $param,
        int $loggedErrors,
    ): void {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => self::pixelforgeErrorFor($code, $error)]);

        $this->runSubmitJob($lighthouse);

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame($reason, $row->local_failure_reason);
        $this->assertSame($code->value, $row->error_code);
        $this->assertSame(sprintf('Pixelforge says %s.', str_replace('_', ' ', $code->value)), $row->error_message);
        $this->assertSame($param, $row->error_param);
        $this->assertNull($row->provider_id);
        $this->assertSame(0, $row->provider_cost_nanodollars);
        $this->assertSame(0, $row->user_charge_nanodollars);
        $this->assertSame([['hold', -457_150_000], ['release', 457_150_000]], $this->ledgerEntriesFor($lighthouse));
        $this->assertSame(0, $this->ledgerNetFor($lighthouse));
        $this->assertCount($loggedErrors, array_filter(
            $this->loggedMessages(),
            fn (MessageLogged $logged): bool => $logged->level === 'error',
        ));
    }

    /**
     * @return array<string, array{PixelforgeErrorCode, array<string, mixed>, LocalFailureReason, ?string, int}>
     */
    public static function definitiveRejections(): array
    {
        return [
            '400 content policy violation' => [PixelforgeErrorCode::ContentPolicyViolation, [], LocalFailureReason::ContentPolicyViolation, null, 0],
            '422 validation failed' => [
                PixelforgeErrorCode::ValidationFailed,
                ['param' => 'size', 'errors' => ['size' => ['The selected size is invalid.']]],
                LocalFailureReason::ValidationFailed,
                'size',
                1,
            ],
            '409 idempotency key reused' => [PixelforgeErrorCode::IdempotencyKeyReused, [], LocalFailureReason::IdempotencyKeyReused, null, 1],
        ];
    }

    #[DataProvider('releasedFailures')]
    public function test_every_other_failure_releases_the_job_and_the_generation_keeps_its_hold(string $answer, int $delay): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        $this->fakePixelforgeAnswering($answer);
        $before = [$this->rawGenerationRowWithoutSubmitBookkeeping($lighthouse), $this->ledgerEntriesFor($lighthouse)];

        $this->runSubmitJob($lighthouse)->assertReleased($delay);

        $this->assertSame($before, [$this->rawGenerationRowWithoutSubmitBookkeeping($lighthouse), $this->ledgerEntriesFor($lighthouse)]);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($lighthouse));
        $this->assertSame(GenerationStatus::Pending, $this->reloadedGeneration($lighthouse)->status);
    }

    /**
     * Until the 401 rule lands, a refused key waits like a rate limit. The daily
     * limit fails at once (SubmitGenerationLimitsTest).
     *
     * @return array<string, array{string, int}>
     */
    public static function releasedFailures(): array
    {
        return [
            '401 invalid api key' => ['401', 60],
            '404 not found, ambiguous at create' => ['404', 5],
            '429 rate limit exceeded, Retry-After 17' => ['429 rate', 17],
            '429 concurrency limit exceeded, Retry-After 5' => ['429 concurrency', 5],
            '503 overloaded, Retry-After 2' => ['503', 2],
            'no key' => ['no key', 60],
            'a read timeout' => ['read timeout', 5],
            'a 422 cut off mid-body' => ['422 cut off', 5],
            'a 502 HTML page' => ['502 html', 5],
            'a 2xx that is not a generation' => ['202 not a generation', 5],
            'a 400 whose message holds a NUL byte' => ['400 nul', 5],
        ];
    }

    /**
     * AC 11 and AC 15: `A lighthouse at dusk [overloaded]` (33 code points, medium,
     * 1024x1024, no references) holds 86,318,750 n$. Still overloaded 2 min after the
     * first 503, it fails free with one release and no charge.
     */
    public function test_an_overload_past_its_deadline_fails_free_with_one_release(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20T10:00:00Z'));
        $generation = $this->createGenerationFor(User::factory()->create(), new NewGenerationData('A lighthouse at dusk [overloaded]', ImageSize::Square, ImageQuality::Medium, []));
        Http::fake(['*' => self::pixelforgeErrorFor(PixelforgeErrorCode::Overloaded, headers: ['Retry-After' => '2'])]);

        $attempts = $this->runSubmitJobUntilItIsDone($generation);

        $row = $this->reloadedGeneration($generation);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(LocalFailureReason::ProviderOverloaded, $row->local_failure_reason);
        $this->assertSame('overloaded', $row->error_code);
        $this->assertNull($row->provider_id);
        $this->assertSame(0, $row->provider_cost_nanodollars);
        $this->assertSame(0, $row->user_charge_nanodollars);
        $this->assertSame([['hold', -86_318_750], ['release', 86_318_750]], $this->ledgerEntriesFor($generation));
        $this->assertSame(0, $this->ledgerNetFor($generation));
        $this->assertSameInstant('2026-11-20T10:02:00Z', CarbonImmutable::now());
        $this->assertCount($attempts, $this->recordedCreateRequests());
        $this->assertSame(9, $attempts);
    }

    /**
     * AC 17: a retry whose next attempt would start past the window is a stop, which
     * fails a generation that was never ambiguous free by its last outcome.
     */
    public function test_a_retry_that_would_start_past_the_window_stops_the_job(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20T10:00:00Z'));
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => self::pixelforgeErrorFor(PixelforgeErrorCode::RateLimitExceeded, headers: ['Retry-After' => '17'])]);
        $this->runSubmitJob($lighthouse)->assertReleased(17);
        $this->travel(1_790)->seconds();

        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertNotReleased();

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(LocalFailureReason::ProviderBusy, $row->local_failure_reason);
        $this->assertSame([['hold', -457_150_000], ['release', 457_150_000]], $this->ledgerEntriesFor($lighthouse));
    }

    /**
     * A run that starts after the window sends nothing and stops.
     */
    public function test_a_run_after_the_window_sends_nothing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20T10:00:00Z'));
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => self::pixelforgeErrorFor(PixelforgeErrorCode::RateLimitExceeded, headers: ['Retry-After' => '17'])]);
        $this->runSubmitJob($lighthouse);
        $this->travel(1_800)->seconds();

        $this->runSubmitJob($this->reloadedGeneration($lighthouse))->assertNotReleased();

        $this->assertCount(1, $this->recordedCreateRequests());
        $this->assertSame(LocalFailureReason::ProviderBusy, $this->reloadedGeneration($lighthouse)->local_failure_reason);
    }

    /**
     * @return iterable<string, array{Throwable, string}>
     */
    public static function exhaustedFailures(): iterable
    {
        yield 'overloaded' => [new OverloadedException('api_error', 'Pixelforge is overloaded.', retryAfterSeconds: 2), 'overloaded'];
        yield 'ambiguous' => [AmbiguousPixelforgeOutcomeException::transportFailed(new ConnectionException('cURL error 28')), 'ambiguous'];
        yield 'not configured' => [PixelforgeNotConfiguredException::missingApiKey(), 'not_configured'];
    }

    #[DataProvider('exhaustedFailures')]
    public function test_when_the_queue_gives_up_the_failure_is_logged_and_the_generation_stopped(Throwable $exception, string $errorCode): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();

        (new SubmitGenerationJob($lighthouse->id))->failed($exception);

        $errors = array_values(array_filter(
            $this->loggedMessages(),
            fn (MessageLogged $logged): bool => $logged->level === 'error',
        ));
        $this->assertCount(1, $errors);
        $this->assertSame($lighthouse->id, $errors[0]->context['generation_id'] ?? null);
        $this->assertSame($errorCode, $errors[0]->context['error_code'] ?? null);
        $this->assertSame($exception::class, $errors[0]->context['exception'] ?? null);
        $this->assertSame(LocalFailureReason::ServiceUnavailable, $this->reloadedGeneration($lighthouse)->local_failure_reason);
        $this->assertSame([['hold', -457_150_000], ['release', 457_150_000]], $this->ledgerEntriesFor($lighthouse));
    }

    /**
     * The queue passes no exception when a job is failed by hand (`fail()`).
     */
    public function test_a_give_up_without_an_exception_is_still_logged_and_stops_the_generation(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();

        (new SubmitGenerationJob($lighthouse->id))->failed(null);

        $errors = array_values(array_filter(
            $this->loggedMessages(),
            fn (MessageLogged $logged): bool => $logged->level === 'error',
        ));
        $this->assertCount(1, $errors);
        $this->assertSame(['generation_id' => $lighthouse->id, 'exception' => null], $errors[0]->context);
        $this->assertSame(GenerationStatus::Failed, $this->reloadedGeneration($lighthouse)->status);
    }

    /**
     * Pixelforge may have it, so a give-up after an ambiguous attempt hands it over.
     */
    public function test_a_give_up_after_an_ambiguous_attempt_hands_the_generation_over(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        $this->fakePixelforgeAnswering('read timeout');
        $this->runSubmitJob($lighthouse);

        (new SubmitGenerationJob($lighthouse->id))->failed(null);

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Pending, $row->status);
        $this->assertNotNull($row->submit_handed_over_at);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($lighthouse));
    }

    /**
     * Runs the job, travelling past each release, until it is not released.
     *
     * @return int how many runs it took
     */
    private function runSubmitJobUntilItIsDone(Generation $generation): int
    {
        for ($runs = 1; $runs <= 100; $runs++) {
            $job = $this->runSubmitJob($this->reloadedGeneration($generation));

            if (! $job->job instanceof FakeJob || ! $job->job->isReleased()) {
                return $runs;
            }

            $this->travel((int) $job->job->releaseDelay)->seconds();
        }

        $this->fail('The submit job was still being released after 100 runs.');
    }

    private function fakePixelforgeAnswering(string $answer): void
    {
        if ($answer === 'no key') {
            config(['pixelforge.api_key' => '']);
            Http::fake();

            return;
        }

        Http::fake(['*' => match ($answer) {
            '401' => self::pixelforgeErrorFor(PixelforgeErrorCode::InvalidApiKey),
            '404' => self::pixelforgeErrorFor(PixelforgeErrorCode::NotFound),
            '429 rate' => self::pixelforgeErrorFor(PixelforgeErrorCode::RateLimitExceeded, headers: ['Retry-After' => '17']),
            '429 concurrency' => self::pixelforgeErrorFor(PixelforgeErrorCode::ConcurrencyLimitExceeded, headers: ['Retry-After' => '5']),
            '429 daily' => self::pixelforgeErrorFor(PixelforgeErrorCode::DailyLimitExceeded),
            '503' => self::pixelforgeErrorFor(PixelforgeErrorCode::Overloaded, headers: ['Retry-After' => '2']),
            'read timeout' => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'),
            '422 cut off' => self::pixelforgeCutOff(ResponseTransferException::class, 422, '{"error":{"type":"invalid_request_error"'),
            '502 html' => Http::response('<html><body><h1>502 Bad Gateway</h1></body></html>', 502, ['Content-Type' => 'text/html']),
            '202 not a generation' => Http::response('{"status":"accepted"}', 202, ['Content-Type' => 'application/json']),
            '400 nul' => self::pixelforgeErrorFor(PixelforgeErrorCode::ContentPolicyViolation, ['message' => "Rejected\0 here."]),
            default => $this->fail(sprintf('Unknown answer %s.', $answer)),
        }]);
    }
}
