<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Log;
use Sentry\Event;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

/**
 * Rebuilds the Sentry hub the way production builds it, from `config/sentry.php`, with a DSN
 * that names no project (`.invalid` never resolves) and a transport that keeps each event in
 * memory. Nothing is ever sent.
 *
 * @phpstan-require-extends TestCase
 */
trait CapturesSentryEvents
{
    private ?TransportInterface $sentryTransport = null;

    protected function captureSentryEvents(): void
    {
        $this->sentryTransport = new class implements TransportInterface
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

        config(['sentry.dsn' => 'https://public@sentry.invalid/1', 'sentry.transport' => $this->sentryTransport]);

        $this->app->forgetInstance(HubInterface::class);
        $this->app->make(HubInterface::class);

        // A channel resolves the hub once, when it is first used.
        Log::forgetChannel('sentry');
        Log::forgetChannel('stack');
    }

    /**
     * @return list<Event>
     */
    protected function sentryEvents(): array
    {
        $this->assertNotNull($this->sentryTransport, 'Call captureSentryEvents() first.');
        $this->assertObjectHasProperty('events', $this->sentryTransport);

        /** @var list<Event> */
        return $this->sentryTransport->events;
    }

    protected function onlySentryEvent(): Event
    {
        $events = $this->sentryEvents();

        $this->assertCount(1, $events);

        return $events[0];
    }
}
