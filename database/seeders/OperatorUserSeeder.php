<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Billing\Actions\GrantStarterCreditsAction;
use App\Domain\Reporting\Actions\GrantOperatorAccessAction;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The local operator, which E2E signs in as to open the report. Like the demo user, its
 * credentials are documented in docs/development.md and are local-only: the seeder refuses
 * to run in production, where `app:grant-operator` grants access to existing accounts (D-62).
 */
final class OperatorUserSeeder extends Seeder
{
    public const string EMAIL = 'operator@promptedit.test';

    public const string NAME = 'Operator';

    public const string PASSWORD = 'promptedit-operator';

    /**
     * Re-seeding resets the documented name, password and access, so it never fails or
     * duplicates. The starter grant is given on every run (D-70): the action adds nothing to
     * an account that has one, and an operator from before balances gets its grant.
     *
     * @throws RuntimeException
     */
    public function run(GrantOperatorAccessAction $grantOperatorAccess, GrantStarterCreditsAction $grantStarterCredits): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The local operator is local-only and is never seeded in production.');
        }

        $operator = User::query()->updateOrCreate(
            ['email' => self::EMAIL],
            ['name' => self::NAME, 'password' => self::PASSWORD],
        );

        $grantOperatorAccess->execute($operator);
        $grantStarterCredits->execute($operator);
    }
}
