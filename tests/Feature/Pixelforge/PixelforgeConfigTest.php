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
        'PIXELFORGE_USAGE_TIMEOUT',
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

    private const array RECONCILE_VARIABLES = [
        'PIXELFORGE_RECONCILE_QUEUED_AFTER',
        'PIXELFORGE_RECONCILE_PROCESSING_AFTER',
        'PIXELFORGE_RECONCILE_RECHECK',
        'PIXELFORGE_RECONCILE_RECHECK_MAX',
        'PIXELFORGE_RECONCILE_BATCH',
        'PIXELFORGE_SUBMIT_RESERVE',
        'PIXELFORGE_RECONCILE_EVERY',
        'PIXELFORGE_REDISPATCH_AFTER',
        'PIXELFORGE_GIVE_UP_AFTER',
        'PIXELFORGE_STUCK_AFTER',
    ];

    /**
     * Production's reconcile bounds (D-50, D-51), the default of each.
     */
    private const array RECONCILE_DEFAULTS = [
        'queued_after_seconds' => 120,
        'processing_after_seconds' => 180,
        'recheck_seconds' => 60,
        'max_recheck_seconds' => 900,
        'batch_size' => 20,
        'redispatch_after_seconds' => 300,
        'give_up_after_seconds' => 86_400,
        'stuck_after_seconds' => 900,
    ];

    /**
     * @var array{env: array<string, mixed>, server: array<string, mixed>, process: array<string, string|false>}
     */
    private array $savedVariables = ['env' => [], 'server' => [], 'process' => []];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([...self::CLIENT_VARIABLES, ...self::FAKE_VARIABLES, ...self::RECONCILE_VARIABLES] as $name) {
            $this->savedVariables['env'][$name] = $_ENV[$name] ?? null;
            $this->savedVariables['server'][$name] = $_SERVER[$name] ?? null;
            $this->savedVariables['process'][$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ([...self::CLIENT_VARIABLES, ...self::FAKE_VARIABLES, ...self::RECONCILE_VARIABLES] as $name) {
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

    /**
     * The dev containers shorten the reconciler's thresholds in their env (D-48);
     * the suite always runs with production's.
     */
    public function test_the_suite_runs_with_the_production_reconcile_bounds(): void
    {
        $this->assertSame(self::RECONCILE_DEFAULTS, array_intersect_key((array) config('pixelforge.reconcile'), self::RECONCILE_DEFAULTS));
    }

    /**
     * D-74: the report waits on the usage call, so the suite runs with production's 5 s.
     */
    public function test_the_suite_runs_with_the_production_usage_timeout(): void
    {
        $this->assertSame(5, config('pixelforge.usage.timeout_seconds'));
    }

    public function test_the_usage_timeout_defaults_to_5_seconds(): void
    {
        $this->assertSame(5, $this->usageTimeoutWith(''));
    }

    public function test_a_set_usage_timeout_applies(): void
    {
        $this->assertSame(8, $this->usageTimeoutWith('8'));
        $this->assertSame(10, $this->usageTimeoutWith('10'));
    }

    /**
     * A page waits on it, so it may not exceed 10 s.
     */
    #[DataProvider('invalidUsageTimeouts')]
    public function test_a_usage_timeout_outside_1_to_10_means_the_default(string $value): void
    {
        $this->assertSame(5, $this->usageTimeoutWith($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUsageTimeouts(): array
    {
        return [
            'zero' => ['0'],
            'eleven' => ['11'],
            'a word' => ['abc'],
            'a fraction' => ['2.5'],
        ];
    }

    public function test_empty_reconcile_bounds_mean_the_production_defaults(): void
    {
        $this->assertSame(self::RECONCILE_DEFAULTS, $this->reconcileConfigWith(array_fill_keys(self::RECONCILE_VARIABLES, '')));
    }

    public function test_set_reconcile_bounds_apply(): void
    {
        $this->assertSame(
            ['queued_after_seconds' => 5, 'processing_after_seconds' => 6, 'recheck_seconds' => 2, 'max_recheck_seconds' => 300, 'batch_size' => 7, 'redispatch_after_seconds' => 90, 'give_up_after_seconds' => 3_600, 'stuck_after_seconds' => 600],
            $this->reconcileConfigWith([
                'PIXELFORGE_RECONCILE_QUEUED_AFTER' => '5',
                'PIXELFORGE_RECONCILE_PROCESSING_AFTER' => '6',
                'PIXELFORGE_RECONCILE_RECHECK' => '2',
                'PIXELFORGE_RECONCILE_RECHECK_MAX' => '300',
                'PIXELFORGE_RECONCILE_BATCH' => '7',
                'PIXELFORGE_REDISPATCH_AFTER' => '90',
                'PIXELFORGE_GIVE_UP_AFTER' => '3600',
                'PIXELFORGE_STUCK_AFTER' => '600',
            ]),
        );
    }

    /**
     * A threshold or recheck of 0 would poll every row on every run.
     */
    #[DataProvider('invalidTimeouts')]
    public function test_a_reconcile_bound_that_is_not_a_positive_integer_means_the_default(string $value): void
    {
        $this->assertSame(self::RECONCILE_DEFAULTS, $this->reconcileConfigWith(array_fill_keys(self::RECONCILE_VARIABLES, $value)));
    }

    public function test_the_submit_reserve_defaults_to_10_units(): void
    {
        $this->assertSame(10, config('pixelforge.reconcile.submit_reserve'));
        $this->assertSame(10, $this->submitReserveWith(''));
    }

    /**
     * 0 keeps nothing back, which is allowed.
     */
    public function test_a_set_submit_reserve_applies_including_zero(): void
    {
        $this->assertSame(15, $this->submitReserveWith('15'));
        $this->assertSame(0, $this->submitReserveWith('0'));
    }

    #[DataProvider('invalidFakeValues')]
    public function test_a_submit_reserve_that_is_not_a_whole_number_means_the_default(string $value): void
    {
        $this->assertSame(10, $this->submitReserveWith($value));
    }

    public function test_the_reconciler_runs_every_minute_by_default(): void
    {
        $this->assertSame(60, config('pixelforge.reconcile.every_seconds'));
        $this->assertSame(60, $this->reconcileEverySecondsWith(''));
    }

    #[DataProvider('reconcileFrequencies')]
    public function test_the_reconciler_frequency_is_a_laravel_frequency_or_a_minute(string $value, int $seconds): void
    {
        $this->assertSame($seconds, $this->reconcileEverySecondsWith($value));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function reconcileFrequencies(): array
    {
        return [
            'every 5 s' => ['5', 5],
            'every 30 s' => ['30', 30],
            'every second' => ['1', 1],
            'a minute' => ['60', 60],
            'not a divisor of a minute' => ['7', 60],
            'more than a minute' => ['120', 60],
            'zero' => ['0', 60],
            'a word' => ['abc', 60],
        ];
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
            ['window_seconds' => 1_800, 'overloaded_deadline_seconds' => 120, 'concurrency_max_wait_seconds' => 600, 'invalid_api_key_attempts' => 3, 'job_timeout_seconds' => 60, 'create_lock_seconds' => 90],
            $this->pixelforgeConfigWith(['PIXELFORGE_SUBMIT_WINDOW' => '', 'PIXELFORGE_OVERLOADED_DEADLINE' => '', 'PIXELFORGE_CONCURRENCY_MAX_WAIT' => ''])['submit'],
        );
    }

    public function test_set_submit_bounds_apply(): void
    {
        $this->assertSame(
            ['window_seconds' => 600, 'overloaded_deadline_seconds' => 15, 'concurrency_max_wait_seconds' => 300, 'invalid_api_key_attempts' => 3, 'job_timeout_seconds' => 60, 'create_lock_seconds' => 90],
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
            ['window_seconds' => 1_800, 'overloaded_deadline_seconds' => 120, 'concurrency_max_wait_seconds' => 600, 'invalid_api_key_attempts' => 3, 'job_timeout_seconds' => 60, 'create_lock_seconds' => 90],
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

    private function reconcileEverySecondsWith(string $value): mixed
    {
        $reconcile = $this->pixelforgeConfigWith(['PIXELFORGE_RECONCILE_EVERY' => $value])['reconcile'];
        $this->assertIsArray($reconcile);

        return $reconcile['every_seconds'] ?? null;
    }

    private function usageTimeoutWith(string $value): mixed
    {
        $usage = $this->pixelforgeConfigWith(['PIXELFORGE_USAGE_TIMEOUT' => $value])['usage'];
        $this->assertIsArray($usage);

        return $usage['timeout_seconds'] ?? null;
    }

    private function submitReserveWith(string $value): mixed
    {
        $reconcile = $this->pixelforgeConfigWith(['PIXELFORGE_SUBMIT_RESERVE' => $value])['reconcile'];
        $this->assertIsArray($reconcile);

        return $reconcile['submit_reserve'] ?? null;
    }

    /**
     * The positive-integer bounds only; the reserve, which may be 0, has its own rows.
     *
     * @param  array<string, string>  $variables
     * @return array<string, mixed>
     */
    private function reconcileConfigWith(array $variables): array
    {
        $reconcile = $this->pixelforgeConfigWith($variables)['reconcile'];
        $this->assertIsArray($reconcile);

        /** @var array<string, mixed> $reconcile */
        return array_intersect_key($reconcile, self::RECONCILE_DEFAULTS);
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
