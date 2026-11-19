<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Production has no `PIXELFORGE_*` variables until PE-015, so every page and `/up` must
 * answer without them (AC14), and the webhook refuses every delivery with a 500.
 *
 * The developer's `.env` and the process env may hold them, so the app is booted with every
 * `PIXELFORGE_*` name removed after `.env` is read and before config is built. Whatever was
 * removed is put back in `tearDown()`, so later tests see the environment they expect.
 */
final class BootsWithoutPixelforgeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array{env: array<string, mixed>, server: array<string, mixed>, process: array<string, string>}
     */
    private array $removedVariables = ['env' => [], 'server' => [], 'process' => []];

    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require Application::inferBasePath() . '/bootstrap/app.php';

        $app->afterBootstrapping(LoadEnvironmentVariables::class, function (): void {
            $this->removePixelforgeVariables();
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restorePixelforgeVariables();
    }

    public function test_no_pixelforge_variable_is_visible_to_the_app(): void
    {
        $this->assertSame([], self::pixelforgeNames(getenv()), 'getenv()');
        $this->assertSame([], self::pixelforgeNames($_ENV), '$_ENV');
        $this->assertSame([], self::pixelforgeNames($_SERVER), '$_SERVER');
    }

    #[DataProvider('guestPages')]
    public function test_a_guest_page_answers(string $path): void
    {
        $this->get($path)->assertOk();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function guestPages(): array
    {
        return [
            'welcome' => ['/'],
            'sign in' => ['/login'],
            'register' => ['/register'],
            'health check' => ['/up'],
        ];
    }

    public function test_the_signed_in_home_answers(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk();
    }

    /**
     * Without a webhook secret nothing can be verified, so Pixelforge is told
     * to retry (PE-008 AC6) while every page still answers.
     */
    public function test_a_pixelforge_webhook_answers_500_without_a_secret(): void
    {
        $this->assertNull(config('pixelforge.webhook_secret'));

        $this->call('POST', '/webhooks/pixelforge', content: '{"id":"evt_1","object":"event","type":"ping","data":{}}')
            ->assertStatus(500);
    }

    private function removePixelforgeVariables(): void
    {
        foreach (self::pixelforgeNames($_ENV) as $name) {
            $this->removedVariables['env'][$name] = $_ENV[$name];
            unset($_ENV[$name]);
        }

        foreach (self::pixelforgeNames($_SERVER) as $name) {
            $this->removedVariables['server'][$name] = $_SERVER[$name];
            unset($_SERVER[$name]);
        }

        foreach (getenv() as $name => $value) {
            if (str_starts_with($name, 'PIXELFORGE_')) {
                $this->removedVariables['process'][$name] = $value;
                putenv($name);
            }
        }
    }

    private function restorePixelforgeVariables(): void
    {
        foreach ($this->removedVariables['env'] as $name => $value) {
            $_ENV[$name] = $value;
        }

        foreach ($this->removedVariables['server'] as $name => $value) {
            $_SERVER[$name] = $value;
        }

        foreach ($this->removedVariables['process'] as $name => $value) {
            putenv("{$name}={$value}");
        }

        $this->removedVariables = ['env' => [], 'server' => [], 'process' => []];
    }

    /**
     * @param  array<array-key, mixed>  $variables
     * @return list<string>
     */
    private static function pixelforgeNames(array $variables): array
    {
        return array_values(array_filter(
            array_map(strval(...), array_keys($variables)),
            fn (string $name): bool => str_starts_with($name, 'PIXELFORGE_'),
        ));
    }
}
