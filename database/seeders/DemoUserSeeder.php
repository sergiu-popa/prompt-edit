<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Billing\Actions\GrantStarterCreditsAction;
use App\Domain\Billing\Actions\PurchaseCreditsAction;
use App\Domain\Billing\ValueObjects\Nanodollars;
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
     * The demo account's one $1.00 demo purchase (D-71), fixed so re-seeding records no second.
     */
    public const string DEMO_PURCHASE_TOKEN = '5f0c2d1e-7a4b-4c8e-9b3d-2e6f1a0c9d47';

    private const int DEMO_PURCHASE_NANODOLLARS = 1_000_000_000;

    /**
     * Re-seeding resets the documented name and password, so it never fails or duplicates.
     * The starter grant is given on every run (D-70): the action adds nothing to an
     * account that has one, and a demo account from before balances gets its grant.
     * Dev and E2E start accounts at $0.0100, so the demo account also gets one $1.00 demo
     * purchase under a fixed token, which a re-seed never repeats.
     *
     * @throws RuntimeException
     */
    public function run(GrantStarterCreditsAction $grantStarterCredits, PurchaseCreditsAction $purchaseCredits): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo user is local-only and is never seeded in production.');
        }

        $user = User::query()->updateOrCreate(
            ['email' => self::EMAIL],
            ['name' => self::NAME, 'password' => self::PASSWORD],
        );

        $grantStarterCredits->execute($user);
        $purchaseCredits->execute($user, new Nanodollars(self::DEMO_PURCHASE_NANODOLLARS), self::DEMO_PURCHASE_TOKEN);
    }
}
