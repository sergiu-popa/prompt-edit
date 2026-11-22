<?php

declare(strict_types=1);

namespace Tests\Feature\Sentry;

use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Nyholm\Psr7\ServerRequest;
use RuntimeException;
use Sentry\Laravel\Http\LaravelRequestFetcher;
use Sentry\Serializer\PayloadSerializer;
use Sentry\State\HubInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\CapturesSentryEvents;
use Tests\TestCase;

/**
 * Every exception Laravel reports also reaches Sentry, redacted (D-77), through
 * `Integration::handles()` in `bootstrap/app.php`.
 */
final class ReportsExceptionsToSentryTest extends TestCase
{
    use CapturesSentryEvents;

    private const string CRASH_PATH = '/__reports-exceptions-to-sentry-test/crash';

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureSentryEvents();
    }

    public function test_a_reported_exception_reaches_sentry_redacted(): void
    {
        report(new RuntimeException('Pixelforge refused the key for demo@promptedit.test.'));

        $exception = $this->onlySentryEvent()->getExceptions()[0];
        $this->assertSame(RuntimeException::class, $exception->getType());
        $this->assertSame('Pixelforge refused the key for [Filtered].', $exception->getValue());
    }

    public function test_an_exception_that_escapes_a_request_reaches_sentry_as_unhandled(): void
    {
        Route::middleware('web')->get(self::CRASH_PATH, fn (): never => throw new RuntimeException('The crash.'));

        $this->get(self::CRASH_PATH)->assertInternalServerError();

        $exception = $this->onlySentryEvent()->getExceptions()[0];
        $this->assertSame('The crash.', $exception->getValue());
        $this->assertFalse($exception->getMechanism()?->isHandled());
    }

    /**
     * Laravel's own list decides: a 404 or a failed validation is never reported.
     */
    public function test_an_exception_laravel_does_not_report_never_reaches_sentry(): void
    {
        report(new NotFoundHttpException);
        report(ValidationException::withMessages(['prompt' => 'The prompt field is required.']));

        $this->assertSame([], $this->sentryEvents());
    }

    /**
     * What the SDK itself reads from a request, as production's proxies hand it over: none of
     * the body, the cookies, the credentials or the visitor's IP is sent.
     */
    public function test_an_event_during_a_request_carries_no_body_cookie_credential_or_ip(): void
    {
        $this->app->instance(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY, (new ServerRequest(
            'POST',
            'https://prompt.sergiupopa.dev/generations?email=demo%40promptedit.test&page=2',
            [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer tok_request1',
                'Cookie' => 'laravel_session=session-value',
                'X-Forwarded-For' => '203.0.113.7',
                'X-Real-IP' => '203.0.113.7',
                'X-XSRF-TOKEN' => 'xsrf-value',
            ],
            '{"prompt":"a red fox in fresh snow","password":"hunter2-hunter2"}',
            '1.1',
            ['REMOTE_ADDR' => '203.0.113.7'],
        ))->withCookieParams(['laravel_session' => 'session-value']));

        report(new RuntimeException('The crash.'));

        $event = $this->onlySentryEvent();
        $request = $event->getRequest();
        $client = $this->app->make(HubInterface::class)->getClient();
        $this->assertNotNull($client);
        // The stack trace's context lines are this file's source, which holds every needle.
        $event->setExceptions([]);
        $payload = (new PayloadSerializer($client->getOptions()))->serialize($event);

        $this->assertSame('POST', $request['method'] ?? null);
        $this->assertArrayNotHasKey('data', $request);
        $this->assertArrayNotHasKey('cookies', $request);
        $this->assertArrayNotHasKey('env', $request);
        $this->assertNull($event->getUser());
        foreach (['red fox', 'hunter2', 'tok_request1', 'session-value', '203.0.113.7', 'xsrf-value', 'promptedit'] as $needle) {
            $this->assertStringNotContainsString($needle, $payload);
        }
    }
}
