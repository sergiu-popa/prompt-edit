<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\Actions;

use App\Domain\Billing\Actions\HoldGenerationCreditsAction;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Domain\Generation\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\TestCase;

/**
 * The hold negates through Nanodollars, like the settlement, so an amount
 * whose negation leaves the signed 64-bit range throws instead of becoming a
 * float.
 */
final class HoldGenerationCreditsActionTest extends TestCase
{
    use InteractsWithCreditLedger;
    use RefreshDatabase;

    public function test_a_max_whose_negation_overflows_throws_and_writes_nothing(): void
    {
        $generation = Generation::factory()->create();
        $before = $this->ledgerEntriesFor($generation);

        try {
            $this->app->make(HoldGenerationCreditsAction::class)->execute(
                $generation->user_id,
                $generation->id,
                new Nanodollars(PHP_INT_MIN),
            );
            $this->fail('A hold whose negation overflows was written.');
        } catch (NanodollarOverflowException) {
            $this->assertSame($before, $this->ledgerEntriesFor($generation));
        }
    }
}
