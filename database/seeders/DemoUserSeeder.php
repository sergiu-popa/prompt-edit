<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The local demo account, which E2E also signs in as. Its credentials are documented in
 * the README and are local-only: the seeder refuses to run in production, where PE-004
 * creates the reviewer account with its own command.
 */
final class DemoUserSeeder extends Seeder
{
    public const string EMAIL = 'demo@promptedit.test';

    public const string NAME = 'Demo User';

    public const string PASSWORD = 'promptedit-demo';

    /**
     * Re-seeding resets the documented name and password, so it never fails or duplicates.
     *
     * @throws RuntimeException
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo user is local-only and is never seeded in production.');
        }

        User::query()->updateOrCreate(
            ['email' => self::EMAIL],
            ['name' => self::NAME, 'password' => self::PASSWORD],
        );
    }
}
