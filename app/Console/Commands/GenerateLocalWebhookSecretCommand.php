<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Dotenv\Dotenv;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Env;

/**
 * Gives a local checkout, or the CI image, a webhook secret for the fake
 * Pixelforge to sign with (PE-010). It fills an empty or missing
 * `PIXELFORGE_WEBHOOK_SECRET` in `.env` with 64 random hex characters, which
 * can never be mistaken for a dashboard `whsec_…`. A value already there is
 * kept, so a real secret is never replaced. Neither value is ever printed.
 */
#[Signature('app:generate-local-webhook-secret')]
#[Description('Fill an empty PIXELFORGE_WEBHOOK_SECRET in .env with a random local value')]
final class GenerateLocalWebhookSecretCommand extends Command
{
    private const string VARIABLE = 'PIXELFORGE_WEBHOOK_SECRET';

    private const int SECRET_BYTES = 32;

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->components->error('Production uses its Pixelforge key\'s own whsec_… secret; set it by hand (PE-015).');

            return self::FAILURE;
        }

        $path = $this->laravel->environmentFilePath();
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            $this->components->error('There is no .env to write to; run `make env` first.');

            return self::FAILURE;
        }

        $current = Dotenv::parse($contents)[self::VARIABLE] ?? null;

        if ($current !== null && $current !== '') {
            $this->components->info(self::VARIABLE . ' is already set; kept as it is.');

            return self::SUCCESS;
        }

        Env::writeVariable(self::VARIABLE, bin2hex(random_bytes(self::SECRET_BYTES)), $path, overwrite: true);

        $this->components->info('Generated a local ' . self::VARIABLE . ' in .env. Restart the worker to use it (`make restart-worker`).');

        return self::SUCCESS;
    }
}
