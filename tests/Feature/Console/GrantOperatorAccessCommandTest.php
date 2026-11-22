<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Concerns\CapturesLogMessages;
use Tests\TestCase;

/**
 * `app:grant-operator` makes an existing user an operator, by hand on production after
 * deploy (D-62). It is idempotent, and it never creates users or touches passwords.
 */
final class GrantOperatorAccessCommandTest extends TestCase
{
    use CapturesLogMessages;
    use RefreshDatabase;

    public function test_it_grants_operator_access_to_an_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        [$exitCode, $output] = $this->grant('ada@example.com');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Granted operator access to ada@example.com.', $output);
        $this->assertTrue($user->refresh()->is_operator);

        $this->assertCount(1, $this->loggedMessages());
        $this->assertSame('info', $this->loggedMessages()[0]->level);
        $this->assertSame(['email' => 'ada@example.com', 'user_id' => $user->id], $this->loggedMessages()[0]->context);
    }

    public function test_running_it_twice_succeeds_both_times_and_leaves_an_operator(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->grant('ada@example.com');
        [$exitCode, $output] = $this->grant('ada@example.com');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('ada@example.com is already an operator.', $output);
        $this->assertTrue($user->refresh()->is_operator);
        $this->assertSame(1, User::query()->count());
    }

    public function test_the_email_is_trimmed_and_lowercased(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        [$exitCode, $output] = $this->grant('  Ada@Example.COM ');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Granted operator access to ada@example.com.', $output);
        $this->assertTrue($user->refresh()->is_operator);
    }

    public function test_an_unknown_email_fails_and_changes_nothing(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        [$exitCode, $output] = $this->grant('grace@example.com');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No user has the email grace@example.com. This command never creates users: create the account first (docs/production.md, "Reviewer account").', $output);
        $this->assertSame(1, User::query()->count());
        $this->assertFalse($user->refresh()->is_operator);
        $this->assertSame([], $this->loggedMessages());
    }

    public function test_it_never_changes_the_name_or_the_password(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com', 'name' => 'Ada Lovelace']);
        $passwordHash = $user->password;

        $this->grant('ada@example.com');
        $this->grant('ada@example.com');

        $user->refresh();
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame($passwordHash, $user->password);
    }

    /**
     * @return array{int, string}
     */
    private function grant(string $email): array
    {
        $tester = new CommandTester(Artisan::all()['app:grant-operator']);

        $exitCode = $tester->execute(['email' => $email]);

        return [$exitCode, $tester->getDisplay()];
    }
}
