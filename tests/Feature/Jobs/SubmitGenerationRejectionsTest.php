<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Jobs\SubmitGenerationJob;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use App\Support\Pixelforge\Exceptions\AmbiguousPixelforgeOutcomeException;
use App\Support\Pixelforge\Exceptions\ConcurrencyLimitExceededException;
use App\Support\Pixelforge\Exceptions\DailyLimitExceededException;
use App\Support\Pixelforge\Exceptions\InvalidApiKeyException;
use App\Support\Pixelforge\Exceptions\NotFoundException;
use App\Support\Pixelforge\Exceptions\OverloadedException;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use App\Support\Pixelforge\Exceptions\RateLimitExceededException;
use GuzzleHttp\Exception\ResponseTransferException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
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

    /**
     * @param  class-string<Throwable>  $expected
     */
    #[DataProvider('retriedFailures')]
    public function test_every_other_failure_is_rethrown_and_the_generation_keeps_its_hold(string $answer, string $expected): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        $this->fakePixelforgeAnswering($answer);
        $before = [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)];

        try {
            $this->runSubmitJob($lighthouse);
            $this->fail(sprintf('Expected %s to be rethrown.', $expected));
        } catch (Throwable $exception) {
            $this->assertInstanceOf($expected, $exception);
        }

        $this->assertSame($before, [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)]);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($lighthouse));
    }

    /**
     * @return array<string, array{string, class-string<Throwable>}>
     */
    public static function retriedFailures(): array
    {
        return [
            '401 invalid api key' => ['401', InvalidApiKeyException::class],
            '404 not found' => ['404', NotFoundException::class],
            '429 rate limit exceeded' => ['429 rate', RateLimitExceededException::class],
            '429 concurrency limit exceeded' => ['429 concurrency', ConcurrencyLimitExceededException::class],
            '429 daily limit exceeded' => ['429 daily', DailyLimitExceededException::class],
            '503 overloaded' => ['503', OverloadedException::class],
            'no key' => ['no key', PixelforgeNotConfiguredException::class],
            'a read timeout' => ['read timeout', AmbiguousPixelforgeOutcomeException::class],
            'a 422 cut off mid-body' => ['422 cut off', AmbiguousPixelforgeOutcomeException::class],
            'a 502 HTML page' => ['502 html', AmbiguousPixelforgeOutcomeException::class],
            'a 2xx that is not a generation' => ['202 not a generation', AmbiguousPixelforgeOutcomeException::class],
            'a 400 whose message holds a NUL byte' => ['400 nul', AmbiguousPixelforgeOutcomeException::class],
        ];
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
    public function test_running_out_of_attempts_logs_the_failure_and_leaves_the_generation_waiting(Throwable $exception, string $errorCode): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        $before = [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)];

        (new SubmitGenerationJob($lighthouse->id))->failed($exception);

        $errors = array_values(array_filter(
            $this->loggedMessages(),
            fn (MessageLogged $logged): bool => $logged->level === 'error',
        ));
        $this->assertCount(1, $errors);
        $this->assertSame($lighthouse->id, $errors[0]->context['generation_id'] ?? null);
        $this->assertSame($errorCode, $errors[0]->context['error_code'] ?? null);
        $this->assertSame($exception::class, $errors[0]->context['exception'] ?? null);
        $this->assertSame($before, [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)]);
    }

    /**
     * The queue passes no exception when a job is failed by hand (`fail()`).
     */
    public function test_a_give_up_without_an_exception_is_still_logged_and_leaves_the_generation_waiting(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        $before = [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)];

        (new SubmitGenerationJob($lighthouse->id))->failed(null);

        $errors = array_values(array_filter(
            $this->loggedMessages(),
            fn (MessageLogged $logged): bool => $logged->level === 'error',
        ));
        $this->assertCount(1, $errors);
        $this->assertSame(['generation_id' => $lighthouse->id, 'exception' => null], $errors[0]->context);
        $this->assertSame($before, [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)]);
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
