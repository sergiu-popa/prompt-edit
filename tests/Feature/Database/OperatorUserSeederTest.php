<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\Reporting\Actions\GrantOperatorAccessAction;
use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Database\Seeders\OperatorUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\Concerns\InteractsWithAuthentication;
use Tests\TestCase;

final class OperatorUserSeederTest extends TestCase
{
    use InteractsWithAuthentication;
    use RefreshDatabase;

    public function test_seeding_twice_leaves_one_operator_who_can_sign_in(): void
    {
        $this->seed();
        $this->seed();

        $operator = User::query()->where('email', OperatorUserSeeder::EMAIL)->sole();
        $this->assertSame(OperatorUserSeeder::NAME, $operator->name);
        $this->assertTrue($operator->is_operator);
        $this->assertSame(2, User::query()->count());

        $this->attemptSignIn(OperatorUserSeeder::EMAIL, OperatorUserSeeder::PASSWORD)
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($operator);
    }

    public function test_the_demo_user_is_not_an_operator(): void
    {
        $this->seed();

        $this->assertFalse(User::query()->where('email', DemoUserSeeder::EMAIL)->sole()->is_operator);
    }

    public function test_reseeding_restores_the_documented_name_password_and_access(): void
    {
        $this->seed(OperatorUserSeeder::class);
        DB::table('users')->where('email', OperatorUserSeeder::EMAIL)->update([
            'name' => 'Renamed',
            'password' => Hash::make('changed-password'),
            'is_operator' => false,
        ]);

        $this->seed(OperatorUserSeeder::class);

        $operator = User::query()->sole();
        $this->assertSame(OperatorUserSeeder::NAME, $operator->name);
        $this->assertTrue(Hash::check(OperatorUserSeeder::PASSWORD, $operator->password));
        $this->assertTrue($operator->is_operator);
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        try {
            (new OperatorUserSeeder)->run(new GrantOperatorAccessAction);
            $this->fail('The operator seeder ran in production.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The local operator is local-only and is never seeded in production.', $exception->getMessage());
        }

        $this->assertSame(0, User::query()->count());
    }
}
