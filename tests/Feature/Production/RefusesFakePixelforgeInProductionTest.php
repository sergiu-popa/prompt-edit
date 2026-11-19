<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The fake can never run in production: the app refuses to boot, before any
 * request or command runs. Each case boots a real `php artisan` in its own
 * process, so no half-booted app leaks into this one.
 */
final class RefusesFakePixelforgeInProductionTest extends TestCase
{
    public function test_production_with_the_fake_driver_refuses_to_boot(): void
    {
        $result = $this->artisanListWith('production', 'fake');

        $this->assertFalse($result->successful());
        $this->assertStringContainsString('PIXELFORGE_DRIVER', $result->output() . $result->errorOutput());
    }

    #[DataProvider('allowedSettings')]
    public function test_any_other_combination_boots(string $environment, string $driver): void
    {
        $result = $this->artisanListWith($environment, $driver);

        $this->assertTrue($result->successful(), $result->errorOutput());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allowedSettings(): array
    {
        return [
            'production with the real API' => ['production', 'http'],
            'local with the fake' => ['local', 'fake'],
        ];
    }

    private function artisanListWith(string $environment, string $driver): ProcessResult
    {
        return Process::path(base_path())
            ->env(['APP_ENV' => $environment, 'PIXELFORGE_DRIVER' => $driver])
            ->timeout(60)
            ->run(['php', 'artisan', 'list']);
    }
}
