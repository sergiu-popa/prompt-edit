<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\Actions;

use App\Domain\Billing\Actions\LockCreditBalanceAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * RefreshDatabase would wrap every test in a transaction, so this file manages its
 * own: the lock has to be refused outside one. The concurrency test proves another
 * connection waits on it.
 */
final class LockCreditBalanceActionTest extends TestCase
{
    public function test_it_refuses_to_run_outside_a_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The credit balance can only be locked inside a transaction.');

        $this->app->make(LockCreditBalanceAction::class)->execute(new User);
    }

    public function test_inside_a_transaction_it_reads_the_balance_under_the_lock(): void
    {
        DB::beginTransaction();

        try {
            $user = User::factory()->withCredits(86_312_500)->create();

            $balance = $this->app->make(LockCreditBalanceAction::class)->execute($user);

            $this->assertSame(
                ['available_nanodollars' => 86_312_500, 'reserved_nanodollars' => 0, 'running_generations' => 0],
                $balance->toArray(),
            );
        } finally {
            DB::rollBack();
        }
    }
}
