<?php

declare(strict_types=1);

namespace Tests\Unit;

use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `.env.production.example` is the template for the server's `shared/.env`: every production
 * name, every non-secret value, blank secrets and production's own Pixelforge key (PE-015).
 */
final class ProductionEnvTemplateTest extends TestCase
{
    private const array NAMES = [
        'APP_NAME',
        'APP_ENV',
        'APP_KEY',
        'APP_DEBUG',
        'APP_URL',
        'APP_LOCALE',
        'APP_FALLBACK_LOCALE',
        'LOG_CHANNEL',
        'LOG_DAILY_DAYS',
        'LOG_LEVEL',
        'LOG_DEPRECATIONS_CHANNEL',
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
        'DB_BACKUP_PATH',
        'SESSION_DRIVER',
        'SESSION_LIFETIME',
        'SESSION_ENCRYPT',
        'SESSION_PATH',
        'SESSION_DOMAIN',
        'SESSION_SECURE_COOKIE',
        'SESSION_HTTP_ONLY',
        'SESSION_SAME_SITE',
        'QUEUE_CONNECTION',
        'CACHE_STORE',
        'BROADCAST_CONNECTION',
        'FILESYSTEM_DISK',
        'MAIL_MAILER',
        'INERTIA_SSR_ENABLED',
        'VITE_APP_NAME',
        'PIXELFORGE_DRIVER',
        'PIXELFORGE_API_KEY',
        'PIXELFORGE_WEBHOOK_SECRET',
    ];

    public function test_it_names_exactly_the_production_variables(): void
    {
        $this->assertSame(self::NAMES, array_keys(self::template()));
    }

    /**
     * Every other Pixelforge setting keeps its production default from config/pixelforge.php.
     */
    public function test_it_has_exactly_the_three_pixelforge_variables(): void
    {
        $pixelforge = array_values(array_filter(array_keys(self::template()), fn (string $name): bool => str_starts_with($name, 'PIXELFORGE_')));

        $this->assertSame(['PIXELFORGE_DRIVER', 'PIXELFORGE_API_KEY', 'PIXELFORGE_WEBHOOK_SECRET'], $pixelforge);
    }

    #[DataProvider('secrets')]
    public function test_secrets_are_left_blank(string $name): void
    {
        $this->assertSame('', self::template()[$name]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function secrets(): array
    {
        return [
            'app key' => ['APP_KEY'],
            'database password' => ['DB_PASSWORD'],
            'Pixelforge API key' => ['PIXELFORGE_API_KEY'],
            'Pixelforge webhook secret' => ['PIXELFORGE_WEBHOOK_SECRET'],
        ];
    }

    #[DataProvider('productionValues')]
    public function test_it_holds_the_production_value(string $name, string $value): void
    {
        $this->assertSame($value, self::template()[$name]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function productionValues(): array
    {
        return [
            'environment' => ['APP_ENV', 'production'],
            'debug off' => ['APP_DEBUG', 'false'],
            'live URL' => ['APP_URL', 'https://prompt.sergiupopa.dev'],
            'queue on the database' => ['QUEUE_CONNECTION', 'database'],
            'cache on the database' => ['CACHE_STORE', 'database'],
            'sessions on the database' => ['SESSION_DRIVER', 'database'],
            'secure session cookie' => ['SESSION_SECURE_COOKIE', 'true'],
            'http-only session cookie' => ['SESSION_HTTP_ONLY', 'true'],
            'lax session cookie' => ['SESSION_SAME_SITE', 'lax'],
            'daily log files' => ['LOG_CHANNEL', 'daily'],
            'logs kept 14 days' => ['LOG_DAILY_DAYS', '14'],
            'info lines kept' => ['LOG_LEVEL', 'info'],
            'the real Pixelforge' => ['PIXELFORGE_DRIVER', 'http'],
        ];
    }

    public function test_backups_go_to_the_domains_private_folder(): void
    {
        $this->assertStringStartsWith('/home/admin/web/prompt.sergiupopa.dev/private/', (string) self::template()['DB_BACKUP_PATH']);
    }

    /**
     * @return array<string, string|null>
     */
    private static function template(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/.env.production.example');

        self::assertIsString($contents, '.env.production.example is missing.');

        return Dotenv::parse($contents);
    }
}
