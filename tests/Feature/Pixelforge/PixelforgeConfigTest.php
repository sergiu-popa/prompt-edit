<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge;

use Tests\TestCase;

/**
 * `config/pixelforge.php` as evaluated from the environment. `.env.example`
 * lists every name empty, and an empty value must mean the default: an empty
 * timeout would otherwise wait forever.
 */
final class PixelforgeConfigTest extends TestCase
{
    private const array CLIENT_VARIABLES = [
        'PIXELFORGE_BASE_URL',
        'PIXELFORGE_API_KEY',
        'PIXELFORGE_CONNECT_TIMEOUT',
        'PIXELFORGE_REQUEST_TIMEOUT',
    ];

    /**
     * @var array{env: array<string, mixed>, server: array<string, mixed>, process: array<string, string|false>}
     */
    private array $savedVariables = ['env' => [], 'server' => [], 'process' => []];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::CLIENT_VARIABLES as $name) {
            $this->savedVariables['env'][$name] = $_ENV[$name] ?? null;
            $this->savedVariables['server'][$name] = $_SERVER[$name] ?? null;
            $this->savedVariables['process'][$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::CLIENT_VARIABLES as $name) {
            self::restore($_ENV, $name, $this->savedVariables['env'][$name]);
            self::restore($_SERVER, $name, $this->savedVariables['server'][$name]);
            $process = $this->savedVariables['process'][$name];
            putenv($process === false ? $name : "{$name}={$process}");
        }

        parent::tearDown();
    }

    public function test_empty_values_mean_the_defaults(): void
    {
        $config = $this->pixelforgeConfigWith(array_fill_keys(self::CLIENT_VARIABLES, ''));

        $this->assertSame('https://pixelforge.promptedit.dev/v1', $config['base_url']);
        $this->assertNull($config['api_key']);
        $this->assertSame(5, $config['connect_timeout_seconds']);
        $this->assertSame(30, $config['request_timeout_seconds']);
    }

    public function test_set_values_apply_and_the_timeouts_are_integers(): void
    {
        $config = $this->pixelforgeConfigWith([
            'PIXELFORGE_BASE_URL' => 'https://pixelforge.test/v1',
            'PIXELFORGE_API_KEY' => 'pf_test_config_value',
            'PIXELFORGE_CONNECT_TIMEOUT' => '3',
            'PIXELFORGE_REQUEST_TIMEOUT' => '20',
        ]);

        $this->assertSame('https://pixelforge.test/v1', $config['base_url']);
        $this->assertSame('pf_test_config_value', $config['api_key']);
        $this->assertSame(3, $config['connect_timeout_seconds']);
        $this->assertSame(20, $config['request_timeout_seconds']);
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
