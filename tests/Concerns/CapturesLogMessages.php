<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Every message logged during the test, in order, with its level and context.
 *
 * @phpstan-require-extends TestCase
 */
trait CapturesLogMessages
{
    /**
     * @var list<MessageLogged>
     */
    private array $capturedLogMessages = [];

    /**
     * Called by Laravel's test lifecycle for every test that uses the trait.
     */
    protected function setUpCapturesLogMessages(): void
    {
        Event::listen(MessageLogged::class, function (MessageLogged $message): void {
            $this->capturedLogMessages[] = $message;
        });
    }

    /**
     * @return list<MessageLogged>
     */
    protected function loggedMessages(): array
    {
        return $this->capturedLogMessages;
    }
}
