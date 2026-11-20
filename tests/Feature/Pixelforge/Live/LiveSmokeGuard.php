<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Live;

use App\Support\Pixelforge\Drivers\HttpPixelforgeClient;
use Dotenv\Dotenv;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;
use SensitiveParameter;

/**
 * Decides whether the opt-in live smoke may talk to the real Pixelforge (D-58).
 * It reads the dev key from `.env` itself, because `phpunit.xml` forces the
 * configured key empty, and refuses before any client exists: in production
 * (the app's or `.env`'s), in CI, then without a key. No message ever carries
 * the key, only variable names.
 */
final readonly class LiveSmokeGuard
{
    public const string PRODUCTION_MESSAGE = 'The live smoke never runs in production (APP_ENV=production): it would spend against the production key.';

    public const string CI_MESSAGE = 'The live smoke never runs in CI (the CI variable is set). Run `make live-smoke` locally.';

    public const string MISSING_KEY_MESSAGE = 'The live smoke needs a dev key: set PIXELFORGE_API_KEY in .env to a Pixelforge dev key, never the production key.';

    private const string PRODUCTION = 'production';

    /**
     * @param  array<string, ?string>  $environmentFile  `.env`'s parsed values; empty when there is no file
     * @param  ?string  $ci  the process's `CI` variable, null when unset
     */
    public function __construct(
        #[SensitiveParameter]
        private array $environmentFile,
        private ?string $ci,
        private string $appEnvironment,
    ) {}

    public static function fromRuntime(Application $app): self
    {
        $path = $app->environmentFilePath();
        $contents = is_file($path) ? file_get_contents($path) : false;
        $ci = getenv('CI');

        return new self(
            $contents === false ? [] : Dotenv::parse($contents),
            $ci === false ? null : $ci,
            $app->environment(),
        );
    }

    /**
     * The real HTTP driver with the dev key, the configured base URL and timeouts.
     *
     * @throws RuntimeException when the smoke must not run
     */
    public function client(Repository $config): HttpPixelforgeClient
    {
        if ($this->appEnvironment === self::PRODUCTION || ($this->environmentFile['APP_ENV'] ?? null) === self::PRODUCTION) {
            throw new RuntimeException(self::PRODUCTION_MESSAGE);
        }

        if ($this->ci !== null && $this->ci !== '') {
            throw new RuntimeException(self::CI_MESSAGE);
        }

        $apiKey = $this->environmentFile['PIXELFORGE_API_KEY'] ?? null;

        if ($apiKey === null || $apiKey === '') {
            throw new RuntimeException(self::MISSING_KEY_MESSAGE);
        }

        return new HttpPixelforgeClient(
            (string) $config->get('pixelforge.base_url'),
            $apiKey,
            (int) $config->get('pixelforge.connect_timeout_seconds'),
            (int) $config->get('pixelforge.request_timeout_seconds'),
        );
    }
}
