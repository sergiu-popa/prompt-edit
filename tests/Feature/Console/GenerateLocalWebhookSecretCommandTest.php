<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `make setup` and the CI image give the fake a local webhook secret: random,
 * never a dashboard `whsec_…`, never printed, and never replacing a value
 * already there. It runs against a temporary `.env`, never the developer's.
 */
final class GenerateLocalWebhookSecretCommandTest extends TestCase
{
    private const string COMMAND = 'app:generate-local-webhook-secret';

    private string $environmentDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->environmentDirectory = sys_get_temp_dir() . '/local-webhook-secret-' . Str::random(12);
        mkdir($this->environmentDirectory);
        $this->app->useEnvironmentPath($this->environmentDirectory);
    }

    protected function tearDown(): void
    {
        @unlink($this->environmentDirectory . '/.env');
        @rmdir($this->environmentDirectory);

        parent::tearDown();
    }

    public function test_an_empty_secret_is_filled_with_64_hex_characters_and_nothing_else_changes(): void
    {
        $this->givenAnEnvFile("APP_ENV=local\nPIXELFORGE_WEBHOOK_SECRET=\nPIXELFORGE_API_KEY=\n");

        $this->assertSame(0, Artisan::call(self::COMMAND));

        $secret = $this->secretInEnvFile();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $secret);
        $this->assertSame("APP_ENV=local\nPIXELFORGE_WEBHOOK_SECRET={$secret}\nPIXELFORGE_API_KEY=\n", $this->envFile());
        $this->assertStringNotContainsString($secret, Artisan::output());
    }

    public function test_a_second_run_keeps_the_generated_secret(): void
    {
        $this->givenAnEnvFile("PIXELFORGE_WEBHOOK_SECRET=\n");
        Artisan::call(self::COMMAND);
        $first = $this->secretInEnvFile();

        $this->assertSame(0, Artisan::call(self::COMMAND));

        $this->assertSame($first, $this->secretInEnvFile());
        $this->assertStringNotContainsString($first, Artisan::output());
    }

    /**
     * A developer who receives real webhooks keeps their dashboard secret;
     * the fake signs with it just as well.
     */
    public function test_an_existing_secret_is_kept(): void
    {
        $contents = "PIXELFORGE_WEBHOOK_SECRET=whsec_dashboard_value_kept_as_is\n";
        $this->givenAnEnvFile($contents);

        $this->assertSame(0, Artisan::call(self::COMMAND));

        $this->assertSame($contents, $this->envFile());
        $this->assertStringNotContainsString('whsec_dashboard_value_kept_as_is', Artisan::output());
    }

    public function test_a_missing_line_is_added(): void
    {
        $this->givenAnEnvFile("APP_ENV=local\n");

        $this->assertSame(0, Artisan::call(self::COMMAND));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $this->secretInEnvFile());
        $this->assertStringStartsWith("APP_ENV=local\n", $this->envFile());
    }

    public function test_two_fresh_files_get_different_secrets(): void
    {
        $this->givenAnEnvFile("PIXELFORGE_WEBHOOK_SECRET=\n");
        Artisan::call(self::COMMAND);
        $first = $this->secretInEnvFile();

        $this->givenAnEnvFile("PIXELFORGE_WEBHOOK_SECRET=\n");
        Artisan::call(self::COMMAND);

        $this->assertNotSame($first, $this->secretInEnvFile());
    }

    public function test_production_is_refused_and_the_file_is_untouched(): void
    {
        $contents = "PIXELFORGE_WEBHOOK_SECRET=\n";
        $this->givenAnEnvFile($contents);
        $this->app['env'] = 'production';

        $this->assertSame(1, Artisan::call(self::COMMAND));

        $this->assertSame($contents, $this->envFile());
    }

    private function givenAnEnvFile(string $contents): void
    {
        file_put_contents($this->environmentDirectory . '/.env', $contents);
    }

    private function envFile(): string
    {
        return (string) file_get_contents($this->environmentDirectory . '/.env');
    }

    private function secretInEnvFile(): string
    {
        return (string) (Dotenv::parse($this->envFile())['PIXELFORGE_WEBHOOK_SECRET'] ?? '');
    }
}
