<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * With production's settings (APP_ENV=production, APP_DEBUG=false), an unknown URL and
 * a crash render the generic error pages, and nothing about the crash leaks: no trace,
 * SQL, file path or env value. The crashing route exists only inside this test, so no
 * debug route ships.
 */
final class ProductionErrorPagesTest extends TestCase
{
    private const string HOST = 'prompt.sergiupopa.dev';

    private const string CRASH_PATH = '/__production-error-pages-test/crash';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://' . self::HOST, 'app.debug' => false]);
        $this->app['env'] = 'production';
        Exceptions::fake();
    }

    protected function tearDown(): void
    {
        // Symfony keeps the trusted host patterns in a static, so later tests would inherit them.
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    public function test_an_unknown_url_renders_the_generic_404_page(): void
    {
        $response = $this->get('https://' . self::HOST . '/no-such-page')
            ->assertNotFound()
            ->assertSee('Not Found');

        $this->assertLeaksNothing($response, null);
    }

    public function test_a_crash_renders_the_generic_500_page(): void
    {
        $message = $this->givenACrashingRoute();

        $response = $this->get('https://' . self::HOST . self::CRASH_PATH)
            ->assertInternalServerError()
            ->assertSee('Server Error');

        $this->assertLeaksNothing($response, $message);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_crash_on_an_inertia_visit_renders_the_same_generic_page(): void
    {
        $message = $this->givenACrashingRoute();

        $response = $this->get('https://' . self::HOST . self::CRASH_PATH, [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
            'X-Inertia-Version' => (string) $this->app->make(HandleInertiaRequests::class)->version(Request::create('/')),
        ])
            ->assertInternalServerError()
            ->assertSee('Server Error');

        $this->assertLeaksNothing($response, $message);
    }

    /**
     * @return string the exception's message, which holds SQL, a file path and env values
     */
    private function givenACrashingRoute(): string
    {
        $message = sprintf(
            "SQLSTATE[42P01]: Undefined table: select * from \"users\" where \"password\" = '%s' in %s (APP_KEY=%s)",
            config('database.connections.pgsql.password'),
            base_path('vendor/laravel/framework/src/Illuminate/Database/Connection.php'),
            config('app.key'),
        );

        Route::middleware('web')->get(self::CRASH_PATH, fn (): never => throw new RuntimeException($message));

        return $message;
    }

    private function assertLeaksNothing(TestResponse $response, ?string $message): void
    {
        $body = (string) $response->getContent();

        $needles = [
            'Stack trace',
            'vendor/',
            'SQLSTATE',
            base_path(),
            $message,
            'APP_KEY',
            (string) config('app.key'),
            (string) config('database.connections.pgsql.password'),
        ];

        foreach (array_filter($needles, fn (?string $needle): bool => $needle !== null && $needle !== '') as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }
}
