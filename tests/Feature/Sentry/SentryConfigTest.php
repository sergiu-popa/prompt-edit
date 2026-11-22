<?php

declare(strict_types=1);

namespace Tests\Feature\Sentry;

use PHPUnit\Framework\Attributes\DataProvider;
use Sentry\DataCollection\HttpMessageType;
use Sentry\Options;
use Sentry\State\HubInterface;
use Tests\TestCase;

/**
 * `config/sentry.php` as the SDK resolves it (D-77). The SDK ignores an invalid option and
 * falls back to its default, which collects more, so these read the client's own options.
 */
final class SentryConfigTest extends TestCase
{
    private const array VARIABLES = [
        'APP_ENV',
        'SENTRY_LARAVEL_DSN',
        'SENTRY_RELEASE',
        'SENTRY_TRACES_SAMPLE_RATE',
        'SENTRY_PROFILES_SAMPLE_RATE',
    ];

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

    /**
     * `phpunit.xml` forces the DSN empty, so a developer's DSN never reaches a test; the tests
     * that capture events use a fake transport. A failure names no value.
     */
    public function test_the_suite_never_sees_a_developer_dsn(): void
    {
        $this->assertTrue(getenv('SENTRY_LARAVEL_DSN') === '', 'SENTRY_LARAVEL_DSN must be forced empty in phpunit.xml.');
        $this->assertTrue(($_SERVER['SENTRY_LARAVEL_DSN'] ?? '') === '', 'SENTRY_LARAVEL_DSN must be cleared in phpunit.xml\'s <server> too.');
        $this->assertTrue($this->clientOptions()->getDsn() === null, 'The suite\'s Sentry client must have no DSN.');
    }

    public function test_the_client_collects_no_user_cookies_bodies_bindings_or_arguments(): void
    {
        $options = $this->clientOptions();
        $dataCollection = $options->getDataCollection();

        $this->assertFalse($options->shouldSendDefaultPii());
        $this->assertSame('never', $options->getMaxRequestBodySize());
        $this->assertNotNull($dataCollection, 'data_collection must resolve; an invalid value falls back to the legacy defaults.');
        $this->assertFalse($dataCollection->shouldCollectUserInfo());
        $this->assertTrue($dataCollection->getCookies()->isOff());
        $this->assertSame([], $dataCollection->getHttpBodies());
        $this->assertFalse($dataCollection->shouldCollectHttpBody(HttpMessageType::incomingRequest()));
        $this->assertFalse($dataCollection->shouldCollectHttpBody(HttpMessageType::incomingResponse()));
        $this->assertFalse($dataCollection->shouldCollectDatabaseQueryData());
        $this->assertFalse($dataCollection->shouldCollectQueues());
        $this->assertTrue($dataCollection->getStackFrameVariables()->isOff());
        $this->assertSame(['inputs' => false, 'outputs' => false], $dataCollection->getGenAi());
    }

    /**
     * Production's proxies pass the visitor's IP in these headers (D-21).
     */
    public function test_the_headers_that_carry_the_visitors_ip_are_filtered(): void
    {
        $dataCollection = $this->clientOptions()->getDataCollection();

        $this->assertNotNull($dataCollection);
        $request = $dataCollection->getHttpHeaders()['request'];
        $this->assertSame('denyList', $request->getMode());
        $this->assertSame(['forwarded', 'real-ip', 'client-ip', 'connecting-ip'], $request->getTerms());
    }

    /**
     * The sign-in throttle's cache key is `email|ip`, so neither breadcrumbs nor spans name keys.
     */
    public function test_breadcrumbs_and_spans_carry_no_bindings_or_cache_keys(): void
    {
        $this->assertFalse(config('sentry.breadcrumbs.sql_bindings'));
        $this->assertFalse(config('sentry.tracing.sql_bindings'));
        $this->assertFalse(config('sentry.breadcrumbs.cache'));
        $this->assertFalse(config('sentry.tracing.cache'));
    }

    public function test_tracing_profiling_logs_and_spotlight_are_off(): void
    {
        $options = $this->clientOptions();

        $this->assertNull($options->getTracesSampleRate());
        $this->assertNull($options->getProfilesSampleRate());
        $this->assertFalse($options->getEnableLogs());
        $this->assertFalse($options->getEnableMetrics());
        $this->assertFalse($options->isSpotlightEnabled());
    }

    #[DataProvider('sampleRates')]
    public function test_a_sample_rate_from_0_to_1_turns_tracing_on_and_anything_else_means_off(string $value, ?float $rate): void
    {
        $this->assertSame($rate, $this->sentryConfigWith(['SENTRY_TRACES_SAMPLE_RATE' => $value])['traces_sample_rate']);
        $this->assertSame($rate, $this->sentryConfigWith(['SENTRY_PROFILES_SAMPLE_RATE' => $value])['profiles_sample_rate']);
    }

    /**
     * @return array<string, array{string, ?float}>
     */
    public static function sampleRates(): array
    {
        return [
            'empty' => ['', null],
            'a quarter' => ['0.25', 0.25],
            'zero' => ['0', 0.0],
            'all' => ['1', 1.0],
            'above 1' => ['2', null],
            'negative' => ['-0.5', null],
            'a word' => ['abc', null],
        ];
    }

    public function test_the_environment_comes_from_app_env(): void
    {
        $this->assertSame('production', $this->sentryConfigWith(['APP_ENV' => 'production'])['environment']);
        $this->assertSame('testing', $this->clientOptions()->getEnvironment());
    }

    public function test_the_release_comes_from_its_variable_when_set(): void
    {
        $this->assertNull($this->sentryConfigWith(['SENTRY_RELEASE' => ''])['release']);
        $this->assertSame('bd04c1a', $this->sentryConfigWith(['SENTRY_RELEASE' => 'bd04c1a'])['release']);
    }

    public function test_an_empty_dsn_means_none(): void
    {
        $this->assertNull($this->sentryConfigWith(['SENTRY_LARAVEL_DSN' => ''])['dsn']);
    }

    /**
     * Production runs `config:cache`, which cannot store a closure or any other object.
     */
    public function test_the_config_holds_no_object_so_config_cache_keeps_it(): void
    {
        $sentry = config('sentry');
        $objects = [];

        $this->assertIsArray($sentry);
        array_walk_recursive($sentry, function (mixed $value, int|string $key) use (&$objects): void {
            if (is_object($value)) {
                $objects[] = $key;
            }
        });
        $this->assertSame([], $objects);
    }

    private function clientOptions(): Options
    {
        $client = $this->app->make(HubInterface::class)->getClient();

        $this->assertNotNull($client);

        return $client->getOptions();
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, mixed>
     */
    private function sentryConfigWith(array $variables): array
    {
        foreach ($variables as $name => $value) {
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }

        /** @var array<string, mixed> $config */
        $config = require config_path('sentry.php');

        return $config;
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
