<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Jobs\SubmitGenerationJob;
use App\Models\User;
use App\Support\Pixelforge\Contracts\PixelforgeClient;
use App\Support\Pixelforge\Drivers\HttpPixelforgeClient;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
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
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
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

    public function test_the_client_settings_are_the_defaults_with_no_key(): void
    {
        $this->assertSame('http', config('pixelforge.driver'));
        $this->assertSame('https://pixelforge.promptedit.dev/v1', config('pixelforge.base_url'));
        $this->assertNull(config('pixelforge.api_key'));
        $this->assertSame(5, config('pixelforge.connect_timeout_seconds'));
        $this->assertSame(30, config('pixelforge.request_timeout_seconds'));
    }

    public function test_the_pixelforge_client_resolves_to_the_http_driver(): void
    {
        $this->assertInstanceOf(HttpPixelforgeClient::class, $this->app->make(PixelforgeClient::class));
    }

    /**
     * Without a key nothing is submitted: the job retries, and the generation
     * waits, pending with its hold, until PE-015 sets one.
     */
    public function test_without_a_key_the_submit_job_sends_nothing_and_the_generation_waits(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake();

        try {
            $this->app->call([new SubmitGenerationJob($lighthouse->id), 'handle']);
            $this->fail('Expected the submit job to find no key.');
        } catch (PixelforgeNotConfiguredException $exception) {
            $this->assertStringContainsString('PIXELFORGE_API_KEY', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(GenerationStatus::Pending, $this->reloadedGeneration($lighthouse)->status);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($lighthouse));
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

    public function test_the_signed_in_home_answers_with_generate_closed(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can_submit', false));
    }

    public function test_a_submission_creates_nothing_and_says_generation_is_not_available(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->from('/dashboard')
            ->post('/generations', [
                'prompt' => self::LIGHTHOUSE_PROMPT,
                'size' => '1536x1024',
                'quality' => 'high',
                'reference_images' => ['https://example.com/style.png'],
                'submission_token' => (string) Str::uuid(),
            ])
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors(['submission' => "Image generation isn't available yet."]);

        $this->assertSame(0, Generation::query()->count());
        $this->assertSame(0, DB::table('credit_ledger_entries')->count());
        Queue::assertNothingPushed();
    }

    public function test_history_answers(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/generations')
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

    /**
     * The fake is not the default driver, so its image route is inert.
     */
    public function test_the_fake_image_route_is_not_found(): void
    {
        $this->get('/fake-pixelforge/images/gen_' . Str::ulid() . '.png')->assertNotFound();
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
