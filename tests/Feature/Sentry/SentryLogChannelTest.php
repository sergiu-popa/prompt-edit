<?php

declare(strict_types=1);

namespace Tests\Feature\Sentry;

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Sentry\Severity;
use Tests\Concerns\CapturesSentryEvents;
use Tests\TestCase;

/**
 * The app logs what an operator must act on instead of throwing ("no webhook secret is
 * configured", the reconciler's warnings). With `sentry` in the stack, as production's
 * `LOG_STACK=daily,sentry`, those lines reach Sentry as events too (D-77).
 */
final class SentryLogChannelTest extends TestCase
{
    use CapturesSentryEvents;

    private const array VARIABLES = ['LOG_SENTRY_LEVEL'];

    /**
     * @var array{env: array<string, mixed>, server: array<string, mixed>, process: array<string, string|false>}
     */
    private array $savedVariables = ['env' => [], 'server' => [], 'process' => []];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::VARIABLES as $name) {
            $this->savedVariables['env'][$name] = $_ENV[$name] ?? null;
            $this->savedVariables['server'][$name] = $_SERVER[$name] ?? null;
            $this->savedVariables['process'][$name] = getenv($name);
        }

        config([
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['file', 'sentry'],
            'logging.channels.file' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        ]);
        Log::forgetChannel('file');
        $this->captureSentryEvents();
    }

    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $name) {
            self::restore($_ENV, $name, $this->savedVariables['env'][$name]);
            self::restore($_SERVER, $name, $this->savedVariables['server'][$name]);
            $process = $this->savedVariables['process'][$name];
            putenv($process === false ? $name : "{$name}={$process}");
        }

        parent::tearDown();
    }

    public function test_an_error_line_reaches_sentry_with_its_context_redacted(): void
    {
        Log::error('Refused a Pixelforge webhook: no webhook secret is configured.', ['email' => 'demo@promptedit.test', 'status' => 500]);

        $event = $this->onlySentryEvent();
        $this->assertSame('Refused a Pixelforge webhook: no webhook secret is configured.', $event->getMessage());
        $this->assertEquals(Severity::error(), $event->getLevel());
        $this->assertSame(['log_context' => ['email' => '[Filtered]', 'status' => 500]], $event->getExtra());
    }

    public function test_lines_below_error_stay_out_of_sentry_by_default(): void
    {
        Log::warning('The rate limit stopped this run\'s polls; the rest wait for the next run.');
        Log::info('Applied a polled Pixelforge snapshot to a generation.');
        Log::critical('Pixelforge keeps refusing our API key; this generation will not be sent with it.');

        $this->assertSame('Pixelforge keeps refusing our API key; this generation will not be sent with it.', $this->onlySentryEvent()->getMessage());
    }

    public function test_a_lower_configured_level_sends_warnings_too(): void
    {
        config(['logging.channels.sentry.level' => 'warning']);
        Log::forgetChannel('sentry');
        Log::forgetChannel('stack');

        Log::warning('The rate limit stopped this run\'s polls; the rest wait for the next run.');

        $this->assertEquals(Severity::warning(), $this->onlySentryEvent()->getLevel());
    }

    /**
     * Laravel logs every exception it reports. `Integration::handles()` has already sent it, so
     * the channel skips the record and the exception arrives once.
     */
    public function test_a_reported_exception_reaches_sentry_once(): void
    {
        report(new RuntimeException('The crash.'));

        $this->assertSame(RuntimeException::class, $this->onlySentryEvent()->getExceptions()[0]->getType());
        $this->assertTrue($this->fileHandler()->hasErrorThatContains('The crash.'));
    }

    /**
     * The channel only adds: every other channel in the stack keeps every line it logged before.
     */
    public function test_the_other_channels_in_the_stack_log_as_before(): void
    {
        Log::info('Applied a polled Pixelforge snapshot to a generation.');
        Log::error('Gave up on a generation Pixelforge never confirmed; it is failed as lost and not charged.');

        $this->assertTrue($this->fileHandler()->hasInfoThatContains('Applied a polled Pixelforge snapshot'));
        $this->assertTrue($this->fileHandler()->hasErrorThatContains('Gave up on a generation'));
        $this->assertCount(1, $this->sentryEvents());
    }

    public function test_the_channel_is_the_packages_and_skips_records_that_carry_an_exception(): void
    {
        $this->assertSame('sentry', config('logging.channels.sentry.driver'));
        $this->assertFalse(config('logging.channels.sentry.report_exceptions'));
        $this->assertTrue(config('logging.channels.sentry.bubble'));
    }

    #[DataProvider('levels')]
    public function test_the_level_comes_from_log_sentry_level(string $value, string $level): void
    {
        $this->assertSame($level, $this->sentryChannelWith($value)['level'] ?? null);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function levels(): array
    {
        return [
            'empty means error' => ['', 'error'],
            'warning' => ['warning', 'warning'],
            'critical' => ['critical', 'critical'],
            'any case' => ['WARNING', 'warning'],
            'an unknown level means error' => ['loud', 'error'],
        ];
    }

    private function fileHandler(): TestHandler
    {
        $channel = Log::channel('file');
        $this->assertInstanceOf(Logger::class, $channel);
        $logger = $channel->getLogger();
        $this->assertInstanceOf(MonologLogger::class, $logger);
        $handler = $logger->getHandlers()[0] ?? null;

        $this->assertInstanceOf(TestHandler::class, $handler);
        $this->assertSame(Level::Debug, $handler->getLevel());

        return $handler;
    }

    /**
     * @return array<string, mixed>
     */
    private function sentryChannelWith(string $value): array
    {
        $_ENV['LOG_SENTRY_LEVEL'] = $value;
        $_SERVER['LOG_SENTRY_LEVEL'] = $value;
        putenv("LOG_SENTRY_LEVEL={$value}");

        /** @var array{channels: array{sentry?: array<string, mixed>}} $config */
        $config = require config_path('logging.php');

        return $config['channels']['sentry'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private static function restore(array &$variables, string $name, mixed $value): void
    {
        if ($value === null) {
            unset($variables[$name]);

            return;
        }

        $variables[$name] = $value;
    }
}
