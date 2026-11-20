<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Live;

use App\Support\Pixelforge\Drivers\HttpPixelforgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionAttribute;
use ReflectionClass;
use RuntimeException;
use SimpleXMLElement;
use Tests\TestCase;

/**
 * The default suite's proof that the live smoke stays opt-in (D-58): it is
 * excluded from every default run, and its guard refuses in production, in CI
 * and without a key before anything is sent.
 */
final class LiveSmokeGuardTest extends TestCase
{
    /**
     * Shaped like a key, but no real one.
     */
    private const string SENTINEL_KEY = 'pf_test_live_smoke_sentinel_9d2b7f4a1c6e3b8d';

    private const string BASE_URL = 'https://pixelforge.promptedit.dev/v1';

    private ?string $environmentDirectory = null;

    private string|false $originalCi = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalCi = getenv('CI');
        config(['pixelforge.base_url' => self::BASE_URL]);
        Http::fake();
    }

    protected function tearDown(): void
    {
        putenv($this->originalCi === false ? 'CI' : 'CI=' . $this->originalCi);

        if ($this->environmentDirectory !== null) {
            @unlink($this->environmentDirectory . '/.env');
            @rmdir($this->environmentDirectory);
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, ?string>  $environmentFile
     */
    #[DataProvider('refusals')]
    public function test_it_refuses_and_sends_nothing(array $environmentFile, ?string $ci, string $appEnvironment, string $message): void
    {
        $guard = new LiveSmokeGuard($environmentFile, $ci, $appEnvironment);

        try {
            $guard->client(config());
            $this->fail('The guard built a client.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
            $this->assertStringNotContainsString(self::SENTINEL_KEY, $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{array<string, ?string>, ?string, string, string}>
     */
    public static function refusals(): array
    {
        $key = ['PIXELFORGE_API_KEY' => self::SENTINEL_KEY];

        return [
            'the app runs as production' => [$key, null, 'production', LiveSmokeGuard::PRODUCTION_MESSAGE],
            '.env says production' => [['APP_ENV' => 'production', ...$key], null, 'local', LiveSmokeGuard::PRODUCTION_MESSAGE],
            'production wins over CI and a key' => [['APP_ENV' => 'production', ...$key], 'true', 'production', LiveSmokeGuard::PRODUCTION_MESSAGE],
            'CI is set' => [$key, 'true', 'local', LiveSmokeGuard::CI_MESSAGE],
            'CI is set to anything' => [$key, '1', 'testing', LiveSmokeGuard::CI_MESSAGE],
            'CI wins over a missing key' => [[], 'true', 'local', LiveSmokeGuard::CI_MESSAGE],
            'no key in .env' => [['APP_ENV' => 'local'], null, 'local', LiveSmokeGuard::MISSING_KEY_MESSAGE],
            'an empty key' => [['PIXELFORGE_API_KEY' => ''], null, 'local', LiveSmokeGuard::MISSING_KEY_MESSAGE],
            'a key with no value' => [['PIXELFORGE_API_KEY' => null], null, 'local', LiveSmokeGuard::MISSING_KEY_MESSAGE],
            'no .env at all' => [[], null, 'local', LiveSmokeGuard::MISSING_KEY_MESSAGE],
        ];
    }

    public function test_an_empty_ci_variable_does_not_count_as_ci(): void
    {
        $guard = new LiveSmokeGuard(['PIXELFORGE_API_KEY' => self::SENTINEL_KEY], '', 'local');

        $this->assertTrue($guard->client(config())->isConfigured());
        Http::assertNothingSent();
    }

    public function test_a_local_env_with_a_key_builds_the_http_client_for_the_configured_base_url(): void
    {
        Http::fake(['*' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'not_found', 'message' => 'No such generation.']], 404)]);

        $client = (new LiveSmokeGuard(['APP_ENV' => 'local', 'PIXELFORGE_API_KEY' => self::SENTINEL_KEY], null, 'testing'))->client(config());

        $this->assertInstanceOf(HttpPixelforgeClient::class, $client);
        $this->assertTrue($client->isConfigured());

        try {
            $client->retrieveGeneration('gen_missing');
        } catch (RuntimeException) {
            // The answer does not matter, only where the request went.
        }

        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE_URL . '/generations/gen_missing'
            && $request->header('Authorization') === ['Bearer ' . self::SENTINEL_KEY]);
    }

    public function test_from_runtime_reads_the_key_from_the_env_file_and_ci_from_the_process(): void
    {
        $this->givenAnEnvironmentFile("APP_ENV=local\nPIXELFORGE_API_KEY=" . self::SENTINEL_KEY . "\n");
        putenv('CI');

        $this->assertTrue(LiveSmokeGuard::fromRuntime($this->app)->client(config())->isConfigured());

        putenv('CI=true');

        $this->expectExceptionMessage(LiveSmokeGuard::CI_MESSAGE);

        LiveSmokeGuard::fromRuntime($this->app)->client(config());
    }

    public function test_from_runtime_refuses_an_env_file_that_says_production(): void
    {
        $this->givenAnEnvironmentFile("APP_ENV=production\nPIXELFORGE_API_KEY=" . self::SENTINEL_KEY . "\n");
        putenv('CI');

        $this->expectExceptionMessage(LiveSmokeGuard::PRODUCTION_MESSAGE);

        LiveSmokeGuard::fromRuntime($this->app)->client(config());
    }

    public function test_from_runtime_without_an_env_file_has_no_key(): void
    {
        $this->environmentDirectory = $this->temporaryDirectory();
        $this->app->useEnvironmentPath($this->environmentDirectory);
        putenv('CI');

        $this->expectExceptionMessage(LiveSmokeGuard::MISSING_KEY_MESSAGE);

        LiveSmokeGuard::fromRuntime($this->app)->client(config());
    }

    public function test_phpunit_excludes_the_live_group_and_includes_no_group(): void
    {
        $configuration = new SimpleXMLElement((string) file_get_contents(base_path('phpunit.xml')));

        $excluded = array_map(strval(...), $configuration->xpath('/phpunit/groups/exclude/group') ?: []);
        $included = $configuration->xpath('/phpunit/groups/include/group') ?: [];

        $this->assertSame(['live'], $excluded);
        $this->assertSame([], $included);
    }

    /**
     * `docker exec` passes no host variable into the container, so the guard
     * only sees the host's `CI` when the target forwards it.
     */
    public function test_the_make_target_forwards_ci_into_the_container(): void
    {
        $makefile = (string) file_get_contents(base_path('Makefile'));

        preg_match('/^live-smoke:\n\t@?(?<recipe>[^\n]+)/m', $makefile, $matches);

        $this->assertMatchesRegularExpression('/^docker exec -e CI prompt-edit-app php artisan test .*--group=live/', $matches['recipe'] ?? '');
    }

    public function test_the_live_smoke_carries_the_live_group_on_the_class(): void
    {
        $groups = array_map(
            fn (ReflectionAttribute $attribute): string => $attribute->newInstance()->name(),
            (new ReflectionClass(LivePixelforgeSmokeTest::class))->getAttributes(Group::class),
        );

        $this->assertSame(['live'], $groups);
    }

    private function givenAnEnvironmentFile(string $contents): void
    {
        $this->environmentDirectory = $this->temporaryDirectory();
        file_put_contents($this->environmentDirectory . '/.env', $contents);
        $this->app->useEnvironmentPath($this->environmentDirectory);
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/live-smoke-guard-' . bin2hex(random_bytes(6));
        mkdir($directory);

        return $directory;
    }
}
