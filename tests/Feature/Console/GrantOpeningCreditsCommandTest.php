<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\TestCase;

/**
 * `app:grant-opening-credits` is a deploy step (AC 18), so every run after go-live
 * finds nobody and succeeds.
 */
final class GrantOpeningCreditsCommandTest extends TestCase
{
    use CapturesLogMessages;
    use InteractsWithCreditLedger;
    use RefreshDatabase;

    public function test_it_prints_and_logs_how_many_users_it_granted(): void
    {
        User::factory()->withoutCredits()->count(2)->create();

        [$exitCode, $output] = $this->grantOpeningCredits();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Granted opening credits to 2 users.', $output);

        $logged = array_values(array_filter(
            $this->loggedMessages(),
            fn ($message): bool => $message->message === 'Opening credits granted by app:grant-opening-credits',
        ));
        $this->assertCount(1, $logged);
        $this->assertSame('info', $logged[0]->level);
        $this->assertSame(['users_granted' => 2], $logged[0]->context);
    }

    public function test_one_user_reads_in_the_singular(): void
    {
        User::factory()->withoutCredits()->create();

        $this->assertStringContainsString('Granted opening credits to 1 user.', $this->grantOpeningCredits()[1]);
    }

    public function test_a_re_run_grants_nobody_and_still_succeeds(): void
    {
        User::factory()->withoutCredits()->create();
        $this->grantOpeningCredits();

        [$exitCode, $output] = $this->grantOpeningCredits();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Granted opening credits to 0 users.', $output);
    }

    /**
     * A user registered after the deploy already has the starter grant.
     */
    public function test_a_user_registered_after_it_keeps_their_single_starter_grant(): void
    {
        $this->grantOpeningCredits();
        $registeredLater = User::factory()->create();

        $this->grantOpeningCredits();

        $this->assertSame([['grant', 1_000_000_000]], $this->topUpsFor($registeredLater));
    }

    /**
     * @return array{int, string}
     */
    private function grantOpeningCredits(): array
    {
        $exitCode = Artisan::call('app:grant-opening-credits');

        return [$exitCode, Artisan::output()];
    }
}
