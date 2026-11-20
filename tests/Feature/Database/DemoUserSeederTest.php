<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\Billing\Actions\GrantStarterCreditsAction;
use App\Domain\Billing\Actions\PurchaseCreditsAction;
use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\Concerns\InteractsWithAuthentication;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\TestCase;

final class DemoUserSeederTest extends TestCase
{
    use InteractsWithAuthentication;
    use InteractsWithCreditLedger;
    use RefreshDatabase;

    public function test_seeding_twice_leaves_one_demo_user_who_can_sign_in(): void
    {
        $this->seed();
        $this->seed();

        $demoUser = User::query()->where('email', DemoUserSeeder::EMAIL)->sole();
        $this->assertSame(DemoUserSeeder::NAME, $demoUser->name);

        $this->attemptSignIn(DemoUserSeeder::EMAIL, DemoUserSeeder::PASSWORD)
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($demoUser);
    }

    public function test_reseeding_restores_the_documented_name_and_password(): void
    {
        $this->seed(DemoUserSeeder::class);
        User::query()->sole()->update(['name' => 'Renamed', 'password' => 'changed-password']);

        $this->seed(DemoUserSeeder::class);

        $demoUser = User::query()->sole();
        $this->assertSame(DemoUserSeeder::NAME, $demoUser->name);
        $this->assertTrue(Hash::check(DemoUserSeeder::PASSWORD, $demoUser->password));
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        try {
            (new DemoUserSeeder)->run($this->app->make(GrantStarterCreditsAction::class), $this->app->make(PurchaseCreditsAction::class));
            $this->fail('The demo user seeder ran in production.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The demo user is local-only and is never seeded in production.', $exception->getMessage());
        }

        $this->assertSame(0, User::query()->count());
    }

    /**
     * AC 2: the seeded account has exactly one starter grant, however often it is seeded.
     */
    public function test_seeding_twice_leaves_one_starter_grant_and_one_demo_purchase(): void
    {
        $this->seed();
        $this->seed();

        $demoUser = User::query()->where('email', DemoUserSeeder::EMAIL)->sole();
        $this->assertSame([['grant', 1_000_000_000], ['purchase', 1_000_000_000]], $this->topUpsFor($demoUser));
        $this->assertSame(2_000_000_000, CreditLedgerEntry::query()->ownedBy($demoUser)->balance()->available->amount);
    }

    public function test_reseeding_grants_a_demo_account_from_before_balances(): void
    {
        $legacy = User::factory()->withoutCredits()->create(['email' => DemoUserSeeder::EMAIL]);

        $this->seed(DemoUserSeeder::class);

        $this->assertSame([['grant', 1_000_000_000], ['purchase', 1_000_000_000]], $this->topUpsFor($legacy));
    }
}
