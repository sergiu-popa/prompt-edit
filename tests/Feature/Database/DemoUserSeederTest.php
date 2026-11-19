<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\Concerns\InteractsWithAuthentication;
use Tests\TestCase;

final class DemoUserSeederTest extends TestCase
{
    use InteractsWithAuthentication;
    use RefreshDatabase;

    public function test_seeding_twice_leaves_one_demo_user_who_can_sign_in(): void
    {
        $this->seed();
        $this->seed();

        $demoUser = User::query()->sole();
        $this->assertSame(DemoUserSeeder::EMAIL, $demoUser->email);
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
            (new DemoUserSeeder)->run();
            $this->fail('The demo user seeder ran in production.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The demo user is local-only and is never seeded in production.', $exception->getMessage());
        }

        $this->assertSame(0, User::query()->count());
    }
}
