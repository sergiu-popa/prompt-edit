<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge;

use App\Support\Pixelforge\Drivers\HttpPixelforgeClient;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use App\Support\Pixelforge\Exceptions\AmbiguousPixelforgeOutcomeException;
use App\Support\Pixelforge\Exceptions\DateRangeTooLongException;
use App\Support\Pixelforge\Exceptions\InvalidApiKeyException;
use App\Support\Pixelforge\Exceptions\InvalidUsageReportException;
use App\Support\Pixelforge\Exceptions\OverloadedException;
use App\Support\Pixelforge\Exceptions\PixelforgeApiException;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use App\Support\Pixelforge\Exceptions\RateLimitExceededException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;

/**
 * `GET /v1/usage` through the HTTP driver against `Http::fake` (AC 1): what it sends, and
 * how every answer becomes a usage report, a typed error or an ambiguous one.
 */
final class HttpPixelforgeUsageTest extends TestCase
{
    use CapturesLogMessages;
    use InteractsWithGenerationSnapshots;
    use InteractsWithPixelforgeApi;

    private const string USAGE_URL = 'https://pixelforge.promptedit.dev/v1/usage?start_date=2026-11-15&end_date=2026-11-18';

    public function test_it_is_a_bare_get_of_the_range_with_the_usage_timeout(): void
    {
        $options = [];
        Http::fake(function (Request $request, array $requestOptions) use (&$options): PromiseInterface {
            $options = $requestOptions;

            return self::fourDays();
        });

        $this->client()->retrieveUsage('2026-11-15', '2026-11-18');

        $requests = $this->recordedUsageRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('GET', $requests[0]->method());
        $this->assertSame(self::USAGE_URL, $requests[0]->url());
        $this->assertSame(['Bearer ' . self::PIXELFORGE_API_KEY], $requests[0]->header('Authorization'));
        $this->assertSame(['application/json'], $requests[0]->header('Accept'));
        $this->assertSame('', $requests[0]->body());
        $this->assertSame(5, $options['connect_timeout'] ?? null);
        $this->assertSame(5, $options['timeout'] ?? null);
    }

    public function test_a_configured_usage_timeout_replaces_the_request_timeout(): void
    {
        config(['pixelforge.usage.timeout_seconds' => 8]);
        $options = [];
        Http::fake(function (Request $request, array $requestOptions) use (&$options): PromiseInterface {
            $options = $requestOptions;

            return self::fourDays();
        });

        $this->client()->retrieveUsage('2026-11-15', '2026-11-18');

        $this->assertSame(8, $options['timeout'] ?? null);
    }

    public function test_the_docs_example_maps_into_exact_integers(): void
    {
        Http::fake(['*' => Http::response(json_encode(self::pixelforgeFixture('usage'), JSON_THROW_ON_ERROR), 200)]);

        $usage = $this->client()->retrieveUsage('2026-11-01', '2026-11-02');

        $this->assertSame(['2026-11-01', '2026-11-02'], array_keys($usage->days));
        $this->assertSame(169_080_000, $usage->days['2026-11-01']->cost->amount);
        $this->assertSame(41, $usage->days['2026-11-01']->tokens->inputTextTokens);
        $this->assertSame(169_080_000, $usage->total->cost->amount);
        $this->assertSame(3, $usage->total->generations);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  class-string<PixelforgeApiException>  $class
     */
    #[DataProvider('documentedErrors')]
    public function test_a_documented_error_becomes_its_typed_error(PixelforgeErrorCode $code, array $headers, string $class, ?int $retryAfterSeconds): void
    {
        Http::fake(['*' => self::pixelforgeErrorFor($code, headers: $headers)]);

        try {
            $this->client()->retrieveUsage('2026-11-15', '2026-11-18');
            $this->fail('Expected a typed Pixelforge error.');
        } catch (PixelforgeApiException $exception) {
            $this->assertInstanceOf($class, $exception);
            $this->assertSame($retryAfterSeconds, $exception->retryAfterSeconds);
        }
    }

    /**
     * @return array<string, array{PixelforgeErrorCode, array<string, string>, class-string<PixelforgeApiException>, ?int}>
     */
    public static function documentedErrors(): array
    {
        return [
            '422 date range too long' => [PixelforgeErrorCode::DateRangeTooLong, [], DateRangeTooLongException::class, null],
            '401 invalid api key' => [PixelforgeErrorCode::InvalidApiKey, [], InvalidApiKeyException::class, null],
            '429 rate limit exceeded, Retry-After 17' => [PixelforgeErrorCode::RateLimitExceeded, ['Retry-After' => '17'], RateLimitExceededException::class, 17],
            '503 overloaded' => [PixelforgeErrorCode::Overloaded, ['Retry-After' => '2'], OverloadedException::class, 2],
        ];
    }

    public function test_a_bare_429_is_a_rate_limit(): void
    {
        Http::fake(['*' => Http::response('Too Many Requests', 429, ['Retry-After' => '9'])]);

        try {
            $this->client()->retrieveUsage('2026-11-15', '2026-11-18');
            $this->fail('Expected a rate limit.');
        } catch (RateLimitExceededException $exception) {
            $this->assertSame(9, $exception->retryAfterSeconds);
        }
    }

    #[DataProvider('ambiguousAnswers')]
    public function test_an_answer_that_is_no_report_or_typed_error_is_ambiguous(string $answer, ?int $status): void
    {
        Http::fake(['*' => match ($answer) {
            'html 500' => Http::response('<html>Internal Server Error</html>', 500, ['Content-Type' => 'text/html']),
            '302' => Http::response('', 302, ['Location' => 'https://elsewhere.example/usage']),
            'timeout' => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received'),
            default => $this->fail(sprintf('Unknown answer %s.', $answer)),
        }]);

        try {
            $this->client()->retrieveUsage('2026-11-15', '2026-11-18');
            $this->fail('Expected an ambiguous outcome.');
        } catch (AmbiguousPixelforgeOutcomeException $exception) {
            $this->assertSame($status, $exception->status);
        }

        Http::assertSentCount(1);
    }

    /**
     * @return array<string, array{string, ?int}>
     */
    public static function ambiguousAnswers(): array
    {
        return [
            'an HTML 500' => ['html 500', 500],
            'a 302, not followed' => ['302', 302],
            'a read timeout' => ['timeout', null],
        ];
    }

    /**
     * @param  class-string  $cause
     */
    #[DataProvider('unreadableAnswers')]
    public function test_a_200_that_is_no_report_for_the_range_is_unreadable_with_its_cause(string $body, ?string $cause): void
    {
        Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);

        try {
            $this->client()->retrieveUsage('2026-11-15', '2026-11-18');
            $this->fail('Expected an unreadable usage report.');
        } catch (AmbiguousPixelforgeOutcomeException $exception) {
            $this->assertSame('Pixelforge answered HTTP 200 without a readable usage report.', $exception->getMessage());
            $this->assertSame(200, $exception->status);
            $this->assertSame($cause, $exception->getPrevious() === null ? null : $exception->getPrevious()::class);
        }
    }

    /**
     * @return array<string, array{string, ?class-string}>
     */
    public static function unreadableAnswers(): array
    {
        $fourDays = [self::usageRow('2026-11-15'), self::usageRow('2026-11-16'), self::usageRow('2026-11-17'), self::usageRow('2026-11-18')];
        $report = fn (array $rows, array $overrides = []): string => json_encode([
            'object' => 'usage',
            'start_date' => $rows[0]['date'],
            'end_date' => $rows[array_key_last($rows)]['date'],
            'data' => $rows,
            'total' => self::usageTotalOf($fourDays),
            ...$overrides,
        ], JSON_THROW_ON_ERROR);
        $badCost = $fourDays;
        $badCost[1]['cost_usd'] = '0.0745950001';

        return [
            'not JSON' => ['<html>OK</html>', JsonException::class],
            'a JSON string' => ['"usage"', null],
            'another range' => [$report(array_slice($fourDays, 1)), InvalidUsageReportException::class],
            'a cost with ten decimals' => [$report($badCost), InvalidUsageReportException::class],
            'a missing day' => [$report($fourDays, ['data' => array_slice($fourDays, 1)]), InvalidUsageReportException::class],
        ];
    }

    #[DataProvider('missingKeys')]
    public function test_without_a_key_it_raises_not_configured_and_sends_nothing(?string $key): void
    {
        config(['pixelforge.api_key' => $key]);
        Http::fake();

        $this->expectException(PixelforgeNotConfiguredException::class);

        try {
            $this->client()->retrieveUsage('2026-11-15', '2026-11-18');
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function missingKeys(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
        ];
    }

    #[DataProvider('keyHygieneAnswers')]
    public function test_the_api_key_appears_in_no_log_or_exception(string $answer): void
    {
        Http::fake(['*' => match ($answer) {
            '200' => self::fourDays(),
            '401' => self::pixelforgeErrorFor(PixelforgeErrorCode::InvalidApiKey),
            'timeout' => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received'),
            'unreadable 200' => Http::response('{"object":"usage"}', 200),
            default => $this->fail(sprintf('Unknown answer %s.', $answer)),
        }]);

        $thrown = null;

        try {
            $this->client()->retrieveUsage('2026-11-15', '2026-11-18');
        } catch (PixelforgeApiException|AmbiguousPixelforgeOutcomeException $exception) {
            $thrown = $exception;
        }

        $this->assertSame($answer === '200', $thrown === null);
        $this->assertStringNotContainsString(self::PIXELFORGE_API_KEY, (string) $thrown);

        foreach ($this->loggedMessages() as $logged) {
            $this->assertStringNotContainsString(self::PIXELFORGE_API_KEY, $logged->message);
            $this->assertStringNotContainsString(self::PIXELFORGE_API_KEY, (string) json_encode($logged->context, JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function keyHygieneAnswers(): array
    {
        return [
            'a 200' => ['200'],
            'a 401' => ['401'],
            'a read timeout' => ['timeout'],
            'an unreadable 200' => ['unreadable 200'],
        ];
    }

    private static function fourDays(): PromiseInterface
    {
        return self::pixelforgeUsageAnswered([
            self::usageRow('2026-11-15'),
            self::usageRow('2026-11-16', '0.074595000', 14, 0, 1_380, 1_546, 2),
            self::usageRow('2026-11-17', '0.017480000', 6, 0, 280, 500, 1),
            self::usageRow('2026-11-18'),
        ]);
    }

    /**
     * The driver itself, not the binding, so no budget rows are left behind.
     */
    private function client(): HttpPixelforgeClient
    {
        return $this->app->make(HttpPixelforgeClient::class);
    }
}
