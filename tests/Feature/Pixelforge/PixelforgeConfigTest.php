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
    ];

    private const array FAKE_VARIABLES = [
        'PIXELFORGE_FAKE_PROCESSING_SECONDS',
        'PIXELFORGE_FAKE_IMAGE_TTL_SECONDS',
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

    public function test_empty_values_mean_the_defaults(): void
    {
        $config = $this->pixelforgeConfigWith(array_fill_keys(self::CLIENT_VARIABLES, ''));

        $this->assertSame('http', $config['driver']);
        $this->assertSame('https://pixelforge.promptedit.dev/v1', $config['base_url']);
        $this->assertNull($config['api_key']);
        $this->assertSame(5, $config['connect_timeout_seconds']);
        $this->assertSame(30, $config['request_timeout_seconds']);
    }

    public function test_set_values_apply_and_the_timeouts_are_integers(): void
    {
        $config = $this->pixelforgeConfigWith([
            'PIXELFORGE_DRIVER' => 'fake',
            'PIXELFORGE_BASE_URL' => 'https://pixelforge.test/v1',
            'PIXELFORGE_API_KEY' => 'pf_test_config_value',
            'PIXELFORGE_CONNECT_TIMEOUT' => '3',
            'PIXELFORGE_REQUEST_TIMEOUT' => '20',
        ]);

        $this->assertSame('fake', $config['driver']);
        $this->assertSame('https://pixelforge.test/v1', $config['base_url']);
        $this->assertSame('pf_test_config_value', $config['api_key']);
        $this->assertSame(3, $config['connect_timeout_seconds']);
        $this->assertSame(20, $config['request_timeout_seconds']);
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

    public function test_empty_fake_values_mean_the_defaults(): void
    {
        $fake = $this->fakeConfigWith(array_fill_keys(self::FAKE_VARIABLES, ''));

        $this->assertSame(3, $fake['processing_seconds']);
        $this->assertSame(86_400, $fake['image_ttl_seconds']);
    }

    public function test_set_fake_values_apply(): void
    {
        $fake = $this->fakeConfigWith([
            'PIXELFORGE_FAKE_PROCESSING_SECONDS' => '0',
            'PIXELFORGE_FAKE_IMAGE_TTL_SECONDS' => '60',
        ]);

        $this->assertSame(0, $fake['processing_seconds']);
        $this->assertSame(60, $fake['image_ttl_seconds']);
    }

    /**
     * A TTL of 0 would hand out image URLs that are already dead.
     */
    #[DataProvider('invalidFakeValues')]
    public function test_an_invalid_fake_value_means_the_default(string $value): void
    {
        $fake = $this->fakeConfigWith(array_fill_keys(self::FAKE_VARIABLES, $value));

        $this->assertSame(3, $fake['processing_seconds']);
        $this->assertSame(86_400, $fake['image_ttl_seconds']);
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

    public function test_a_zero_image_ttl_means_the_default(): void
    {
        $this->assertSame(86_400, $this->fakeConfigWith(['PIXELFORGE_FAKE_IMAGE_TTL_SECONDS' => '0'])['image_ttl_seconds']);
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
