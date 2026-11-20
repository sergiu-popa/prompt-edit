<?php

declare(strict_types=1);

namespace Tests\Unit;

use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `.env.example` is what `make setup` copies for dev and what the CI image
 * runs E2E with: the fake Pixelforge, posting its webhooks in-network, with
 * no committed secret or key.
 */
final class LocalEnvTemplateTest extends TestCase
{
    #[DataProvider('localValues')]
    public function test_it_holds_the_local_value(string $name, string $value): void
    {
        $this->assertSame($value, self::template()[$name] ?? null);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function localValues(): array
    {
        return [
            'local environment' => ['APP_ENV', 'local'],
            'the fake Pixelforge' => ['PIXELFORGE_DRIVER', 'fake'],
            'in-network webhooks' => ['PIXELFORGE_FAKE_WEBHOOK_URL', 'http://app:8080'],
            'a database queue the worker runs' => ['QUEUE_CONNECTION', 'database'],
            'a rate budget that never slows parallel E2E' => ['PIXELFORGE_RATE_LIMIT_PER_MINUTE', '600'],
        ];
    }

    /**
     * An existing dev `.env` lacks these names and is never edited, so the dev
     * containers get them from compose (D-48), defaulted to the template's value.
     */
    #[DataProvider('containerBounds')]
    public function test_compose_defaults_each_dev_bound_to_the_templates_value(string $name): void
    {
        $value = self::template()[$name] ?? null;
        $compose = file_get_contents(dirname(__DIR__, 2) . '/docker-compose.yml');

        $this->assertNotNull($value);
        $this->assertIsString($compose, 'docker-compose.yml is missing.');
        $this->assertStringContainsString(sprintf('%s: ${%s:-%s}', $name, $name, $value), $compose);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function containerBounds(): array
    {
        return [
            'the rate budget' => ['PIXELFORGE_RATE_LIMIT_PER_MINUTE'],
        ];
    }

    /**
     * `make setup` fills the secret locally; neither is ever committed.
     */
    #[DataProvider('secrets')]
    public function test_secrets_are_left_blank(string $name): void
    {
        $this->assertSame('', self::template()[$name] ?? null);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function secrets(): array
    {
        return [
            'webhook secret' => ['PIXELFORGE_WEBHOOK_SECRET'],
            'API key' => ['PIXELFORGE_API_KEY'],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private static function template(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/.env.example');

        self::assertIsString($contents, '.env.example is missing.');

        return Dotenv::parse($contents);
    }
}
