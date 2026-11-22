<?php

declare(strict_types=1);

namespace Tests\Feature\Sentry;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Sentry\Event;
use Sentry\Laravel\Features\HttpClientIntegration;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

/**
 * With a DSN, the package adds `baggage` and `sentry-trace` to every request the HTTP client
 * sends, even with tracing off, and the baggage names the project's public key and organisation.
 * Pixelforge is a third party, so `trace_propagation_targets` is empty and no request carries
 * them (D-77).
 */
final class SentryTracePropagationTest extends TestCase
{
    private const string PIXELFORGE_URL = 'https://pixelforge.invalid/v1/usage';

    public function test_the_client_propagates_traces_to_no_host(): void
    {
        $client = $this->app->make(HubInterface::class)->getClient();

        $this->assertNotNull($client);
        $this->assertSame([], $client->getOptions()->getTracePropagationTargets());
    }

    public function test_no_request_to_pixelforge_carries_sentry_headers(): void
    {
        $this->bootHttpClientFeatureWithDsn();
        Http::fake();

        Http::withToken('pf_test_0123456789abcdef')->get(self::PIXELFORGE_URL);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('baggage') && ! $request->hasHeader('sentry-trace'));
    }

    /**
     * The package's default, which the config overrides. It keeps the test above from passing
     * because the feature never booted.
     */
    public function test_the_packages_default_would_send_the_public_key_to_pixelforge(): void
    {
        config(['sentry.trace_propagation_targets' => null]);
        $this->bootHttpClientFeatureWithDsn();
        Http::fake();

        Http::get(self::PIXELFORGE_URL);

        Http::assertSent(fn (Request $request): bool => str_contains($request->header('baggage')[0] ?? '', 'sentry-public_key=public') && $request->hasHeader('sentry-trace'));
    }

    /**
     * Production boots the package's HTTP client feature only with a DSN. This one names no
     * project (`.invalid` never resolves) and the transport keeps any event in memory, so
     * nothing is ever sent.
     */
    private function bootHttpClientFeatureWithDsn(): void
    {
        $transport = new class implements TransportInterface
        {
            /**
             * @var list<Event>
             */
            public array $events = [];

            public function send(Event $event): Result
            {
                $this->events[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };

        config(['sentry.dsn' => 'https://public@sentry.invalid/1', 'sentry.transport' => $transport]);
        $this->app->forgetInstance(HubInterface::class);
        $this->app->make(HubInterface::class);
        $this->app->make(HttpClientIntegration::class)->boot();
    }
}
