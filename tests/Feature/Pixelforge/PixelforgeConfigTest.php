<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `config/pixelforge.php` as evaluated from the environment. `.env.example`
 * lists every name empty, and an empty value must mean the default: an empty
 * timeout would otherwise wait forever. So must a timeout that is not a
 * positive integer.
 */
final class PixelforgeConfigTest extends TestCase
{
    private const array CLIENT_VARIABLES = [
        'PIXELFORGE_DRIVER',
        'PIXELFORGE_BASE_URL',
        'PIXELFORGE_API_KEY',
        'PIXELFORGE_CONNECT_TIMEOUT',
        'PIXELFORGE_REQUEST_TIMEOUT',
        'PIXELFORGE_RATE_LIMIT_PER_MINUTE',
        'CACHE_STORE',
        'PIXELFORGE_SUBMIT_WINDOW',
        'PIXELFORGE_OVERLOADED_DEADLINE',
        'PIXELFORGE_CONCURRENCY_MAX_WAIT',
    ];

    private const array FAKE_VARIABLES = [
        'PIXELFORGE_FAKE_WEBHOOK_URL',
        'PIXELFORGE_FAKE_QUEUE_SECONDS',
        'PIXELFORGE_FAKE_PROCESSING_SECONDS',
        'PIXELFORGE_FAKE_SLOW_SECONDS',
        'PIXELFORGE_FAKE_IMAGE_TTL_SECONDS',
        'PIXELFORGE_FAKE_EXPIRE_TTL_SECONDS',
        'PIXELFORGE_FAKE_CHAOS_PERCENT',
        'PIXELFORGE_FAKE_DROP_WEBHOOKS',
    ];

    /**
     * @var array{env: array<string, mixed>, server: array<string, mixed>, process: array<string, string|false>}
     */
    private array $savedVariables = ['env' => [], 'server' => [], 'process' => []];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([...self::CLIENT_VARIABLES, ...self::FAKE_VARIABLES] as $name) {
            $this->savedVariables['env'][$name] = $_ENV[$name] ?? null;
            $this->savedVariables['server'][$name] = $_SERVER[$name] ?? null;
            $this->savedVariables['process'][$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ([...self::CLIENT_VARIABLES, ...self::FAKE_VARIABLES] as $name) {
            self::restore($_ENV, $name, $this->savedVariables['env'][$name]);
            self::restore($_SERVER, $name, $this->savedVariables['server'][$name]);
            $process = $this->savedVariables['process'][$name];
            putenv($process === false ? $name : "{$name}={$process}");
        }

        parent::tearDown();
    }

    /**
     * `phpunit.xml` forces the key empty, so a developer's real key in `.env`
     * never reaches a test. Tests that need one set a sentinel. A failure
     * names no value, so it can never print a real key.
     */
    public function test_the_suite_never_sees_a_developer_key(): void
    {
        $this->assertTrue(getenv('PIXELFORGE_API_KEY') === '', 'PIXELFORGE_API_KEY must be forced empty in phpunit.xml.');
        $this->assertTrue(config('pixelforge.api_key') === null, 'pixelforge.api_key must be null in the test suite.');
    }

    /**
     * A developer's `.env` may select the fake or hold a real `whsec_…`;
     * neither reaches a test, which sets what it needs.
     */
    public function test_the_suite_never_sees_a_developer_driver_or_secret(): void
    {
        $this->assertTrue(getenv('PIXELFORGE_DRIVER') === 'http', 'PIXELFORGE_DRIVER must be forced to http in phpunit.xml.');
        $this->assertTrue(getenv('PIXELFORGE_WEBHOOK_SECRET') === '', 'PIXELFORGE_WEBHOOK_SECRET must be forced empty in phpunit.xml.');
        $this->assertTrue(in_array(config('pixelforge.webhook_secret'), [null, ''], true), 'pixelforge.webhook_secret must be empty in the test suite.');
    }

    /**
     * The dev containers set a high budget and a short overload deadline in their env
     * (D-48); the suite always runs with production's bounds.
     */
    public function test_the_suite_runs_with_the_production_rate_budget_and_overload_deadline(): void
    {
        $this->assertSame(50, config('pixelforge.rate_limit_per_minute'));
        $this->assertSame(120, config('pixelforge.submit.overloaded_deadline_seconds'));
    }

    public function test_empty_values_mean_the_defaults(): void
    {
        $config = $this->pixelforgeConfigWith(array_fill_keys(self::CLIENT_VARIABLES, ''));

        $this->assertSame('http', $config['driver']);
        $this->assertSame('https://pixelforge.promptedit.dev/v1', $config['base_url']);
        $this->assertNull($config['api_key']);
        $this->assertSame(5, $config['connect_timeout_seconds']);
        $this->assertSame(30, $config['request_timeout_seconds']);
        $this->assertSame(50, $config['rate_limit_per_minute']);
    }

    public function test_set_values_apply_and_the_timeouts_are_integers(): void
    {
        $config = $this->pixelforgeConfigWith([
            'PIXELFORGE_DRIVER' => 'fake',
            'PIXELFORGE_BASE_URL' => 'https://pixelforge.test/v1',
            'PIXELFORGE_API_KEY' => 'pf_test_config_value',
            'PIXELFORGE_CONNECT_TIMEOUT' => '3',
            'PIXELFORGE_REQUEST_TIMEOUT' => '20',
            'PIXELFORGE_RATE_LIMIT_PER_MINUTE' => '600',
        ]);

        $this->assertSame('fake', $config['driver']);
        $this->assertSame('https://pixelforge.test/v1', $config['base_url']);
        $this->assertSame('pf_test_config_value', $config['api_key']);
        $this->assertSame(3, $config['connect_timeout_seconds']);
        $this->assertSame(20, $config['request_timeout_seconds']);
        $this->assertSame(600, $config['rate_limit_per_minute']);
    }

    /**
     * Guzzle reads a timeout of 0 as "wait forever", so a typo must never
     * become one.
     */
    #[DataProvider('invalidTimeouts')]
    public function test_a_timeout_that_is_not_a_positive_integer_means_the_default(string $value): void
    {
        $config = $this->pixelforgeConfigWith([
            'PIXELFORGE_CONNECT_TIMEOUT' => $value,
            'PIXELFORGE_REQUEST_TIMEOUT' => $value,
        ]);

        $this->assertSame(5, $config['connect_timeout_seconds']);
        $this->assertSame(30, $config['request_timeout_seconds']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidTimeouts(): array
    {
        return [
            'a word' => ['thirty'],
            'a unit suffix' => ['30s'],
            'zero' => ['0'],
            'negative' => ['-5'],
            'a fraction' => ['2.5'],
        ];
    }

    /**
     * AC 19: a request that could outlast the submit job's timeout would let the worker
     * kill the job mid-request, so such a value means the default too.
     */
    #[DataProvider('requestTimeoutsNotBelowTheJobTimeout')]
    public function test_a_request_timeout_not_below_the_job_timeout_means_the_default(string $value): void
    {
        $this->assertSame(30, $this->pixelforgeConfigWith(['PIXELFORGE_REQUEST_TIMEOUT' => $value])['request_timeout_seconds']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requestTimeoutsNotBelowTheJobTimeout(): array
    {
        return [
            'the job timeout' => ['60'],
            'just above it' => ['61'],
            'far above it' => ['600'],
        ];
    }

    public function test_a_request_timeout_just_below_the_job_timeout_applies(): void
    {
        $config = $this->pixelforgeConfigWith(['PIXELFORGE_REQUEST_TIMEOUT' => '59']);

        $this->assertSame(59, $config['request_timeout_seconds']);
        $this->assertIsArray($config['submit']);
        $this->assertLessThan($config['submit']['job_timeout_seconds'], $config['request_timeout_seconds']);
    }

    /**
     * A budget of 0 would never send anything, and a typo must never lift it.
     */
    #[DataProvider('invalidTimeouts')]
    public function test_a_rate_limit_that_is_not_a_positive_integer_means_the_default(string $value): void
    {
        $this->assertSame(50, $this->pixelforgeConfigWith(['PIXELFORGE_RATE_LIMIT_PER_MINUTE' => $value])['rate_limit_per_minute']);
    }

    public function test_empty_submit_bounds_mean_the_production_defaults(): void
    {
        $this->assertSame(
            ['window_seconds' => 1_800, 'overloaded_deadline_seconds' => 120, 'concurrency_max_wait_seconds' => 600, 'invalid_api_key_attempts' => 3, 'job_timeout_seconds' => 60],
            $this->pixelforgeConfigWith(['PIXELFORGE_SUBMIT_WINDOW' => '', 'PIXELFORGE_OVERLOADED_DEADLINE' => '', 'PIXELFORGE_CONCURRENCY_MAX_WAIT' => ''])['submit'],
        );
    }

    public function test_set_submit_bounds_apply(): void
    {
        $this->assertSame(
            ['window_seconds' => 600, 'overloaded_deadline_seconds' => 15, 'concurrency_max_wait_seconds' => 300, 'invalid_api_key_attempts' => 3, 'job_timeout_seconds' => 60],
            $this->pixelforgeConfigWith(['PIXELFORGE_SUBMIT_WINDOW' => '600', 'PIXELFORGE_OVERLOADED_DEADLINE' => '15', 'PIXELFORGE_CONCURRENCY_MAX_WAIT' => '300'])['submit'],
        );
    }

    /**
     * A bound of 0 would fail every generation at its first wait.
     */
    #[DataProvider('invalidTimeouts')]
    public function test_a_submit_bound_that_is_not_a_positive_integer_means_the_default(string $value): void
    {
        $this->assertSame(
            ['window_seconds' => 1_800, 'overloaded_deadline_seconds' => 120, 'concurrency_max_wait_seconds' => 600, 'invalid_api_key_attempts' => 3, 'job_timeout_seconds' => 60],
            $this->pixelforgeConfigWith(['PIXELFORGE_SUBMIT_WINDOW' => $value, 'PIXELFORGE_OVERLOADED_DEADLINE' => $value, 'PIXELFORGE_CONCURRENCY_MAX_WAIT' => $value])['submit'],
        );
    }

    /**
     * Every process must count in one store (D-44), whatever the app's cache is.
     */
    #[DataProvider('cacheStores')]
    public function test_the_rate_budget_is_always_counted_in_the_database_store(string $cacheStore): void
    {
        $this->assertSame('database', $this->pixelforgeConfigWith(['CACHE_STORE' => $cacheStore])['rate_limit_store']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cacheStores(): array
    {
        return [
            'array' => ['array'],
            'file' => ['file'],
            'empty' => [''],
        ];
    }

    public function test_empty_fake_values_mean_the_defaults(): void
    {
        $fake = $this->fakeConfigWith(array_fill_keys(self::FAKE_VARIABLES, ''));

        $this->assertSame('http://app:8080', $fake['webhook_base_url']);
        $this->assertSame(1, $fake['queue_seconds']);
        $this->assertSame(3, $fake['processing_seconds']);
        $this->assertSame(10, $fake['slow_processing_seconds']);
        $this->assertSame(86_400, $fake['image_ttl_seconds']);
        $this->assertSame(5, $fake['expire_ttl_seconds']);
    }

    /**
     * `[slow]` must be visibly slower than a normal generation.
     */
    public function test_the_default_slow_duration_exceeds_normal_processing(): void
    {
        $fake = $this->fakeConfigWith(array_fill_keys(self::FAKE_VARIABLES, ''));

        $this->assertGreaterThan($fake['processing_seconds'], $fake['slow_processing_seconds']);
    }

    public function test_set_fake_values_apply(): void
    {
        $fake = $this->fakeConfigWith([
            'PIXELFORGE_FAKE_WEBHOOK_URL' => 'http://127.0.0.1:8080',
            'PIXELFORGE_FAKE_QUEUE_SECONDS' => '0',
            'PIXELFORGE_FAKE_PROCESSING_SECONDS' => '0',
            'PIXELFORGE_FAKE_SLOW_SECONDS' => '4',
            'PIXELFORGE_FAKE_IMAGE_TTL_SECONDS' => '60',
            'PIXELFORGE_FAKE_EXPIRE_TTL_SECONDS' => '2',
        ]);

        $this->assertSame('http://127.0.0.1:8080', $fake['webhook_base_url']);
        $this->assertSame(0, $fake['queue_seconds']);
        $this->assertSame(0, $fake['processing_seconds']);
        $this->assertSame(4, $fake['slow_processing_seconds']);
        $this->assertSame(60, $fake['image_ttl_seconds']);
        $this->assertSame(2, $fake['expire_ttl_seconds']);
    }

    /**
     * A TTL of 0 would hand out image URLs that are already dead.
     */
    #[DataProvider('invalidFakeValues')]
    public function test_an_invalid_fake_value_means_the_default(string $value): void
    {
        $fake = $this->fakeConfigWith(array_fill_keys(array_diff(self::FAKE_VARIABLES, ['PIXELFORGE_FAKE_WEBHOOK_URL']), $value));

        $this->assertSame(1, $fake['queue_seconds']);
        $this->assertSame(3, $fake['processing_seconds']);
        $this->assertSame(10, $fake['slow_processing_seconds']);
        $this->assertSame(86_400, $fake['image_ttl_seconds']);
        $this->assertSame(5, $fake['expire_ttl_seconds']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidFakeValues(): array
    {
        return [
            'a word' => ['abc'],
            'negative' => ['-1'],
            'a fraction' => ['2.5'],
        ];
    }

    public function test_chaos_is_off_by_default(): void
    {
        $fake = $this->fakeConfigWith(array_fill_keys(self::FAKE_VARIABLES, ''));

        $this->assertSame(['overloaded_percent' => 0, 'internal_error_percent' => 0, 'duplicate_percent' => 0], $fake['chaos']);
    }

    public function test_one_chaos_percent_feeds_every_chaos_outcome(): void
    {
        $fake = $this->fakeConfigWith(['PIXELFORGE_FAKE_CHAOS_PERCENT' => '25']);

        $this->assertSame(['overloaded_percent' => 25, 'internal_error_percent' => 25, 'duplicate_percent' => 25], $fake['chaos']);
    }

    #[DataProvider('invalidChaosPercents')]
    public function test_a_chaos_percent_outside_0_to_100_means_off(string $value): void
    {
        $fake = $this->fakeConfigWith(['PIXELFORGE_FAKE_CHAOS_PERCENT' => $value]);

        $this->assertSame(['overloaded_percent' => 0, 'internal_error_percent' => 0, 'duplicate_percent' => 0], $fake['chaos']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidChaosPercents(): array
    {
        return [
            'above 100' => ['101'],
            'negative' => ['-1'],
            'a fraction' => ['2.5'],
            'a word' => ['abc'],
        ];
    }

    public function test_webhooks_are_never_dropped_by_default(): void
    {
        $this->assertFalse($this->fakeConfigWith(['PIXELFORGE_FAKE_DROP_WEBHOOKS' => ''])['drop_webhooks']);
        $this->assertFalse(config('pixelforge.fake.drop_webhooks'));
    }

    #[DataProvider('dropWebhooksValues')]
    public function test_the_drop_switch_is_a_boolean(string $value, bool $expected): void
    {
        $this->assertSame($expected, $this->fakeConfigWith(['PIXELFORGE_FAKE_DROP_WEBHOOKS' => $value])['drop_webhooks']);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function dropWebhooksValues(): array
    {
        return [
            'the word true' => ['true', true],
            'one' => ['1', true],
            'the word on' => ['on', true],
            'the word false' => ['false', false],
            'zero' => ['0', false],
            'any other word' => ['abc', false],
        ];
    }

    public function test_a_zero_ttl_means_the_default(): void
    {
        $fake = $this->fakeConfigWith(['PIXELFORGE_FAKE_IMAGE_TTL_SECONDS' => '0', 'PIXELFORGE_FAKE_EXPIRE_TTL_SECONDS' => '0']);

        $this->assertSame(86_400, $fake['image_ttl_seconds']);
        $this->assertSame(5, $fake['expire_ttl_seconds']);
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, mixed>
     */
    private function fakeConfigWith(array $variables): array
    {
        $fake = $this->pixelforgeConfigWith($variables)['fake'];
        $this->assertIsArray($fake);

        /** @var array<string, mixed> $fake */
        return $fake;
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, mixed>
     */
    private function pixelforgeConfigWith(array $variables): array
    {
        foreach ($variables as $name => $value) {
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }

        /** @var array<string, mixed> $config */
        $config = require config_path('pixelforge.php');

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
