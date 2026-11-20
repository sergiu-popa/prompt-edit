<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\QueryBuilders;

use App\Domain\Billing\Enums\CreditLedgerEntryType;
use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Domain\Billing\QueryBuilders\CreditLedgerEntryQueryBuilder;
use App\Domain\Billing\ValueObjects\CreditBalance;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\TestCase;

/**
 * AC 1: a balance is read from the ledger alone. The factory's generations hold
 * 123,387,500 n$ each, and a succeeded one is charged 109,312,500 n$.
 */
final class CreditLedgerEntryQueryBuilderTest extends TestCase
{
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use RefreshDatabase;

    public function test_the_model_queries_through_the_credit_ledger_entry_query_builder(): void
    {
        $this->assertInstanceOf(CreditLedgerEntryQueryBuilder::class, CreditLedgerEntry::query());
    }

    public function test_the_balance_sums_top_ups_active_holds_and_charges_but_not_released_holds(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $this->givenATopUp($user, CreditLedgerEntryType::Grant, 1_000_000_000);
        $this->givenATopUp($user, CreditLedgerEntryType::Purchase, 5_000_000_000);
        Generation::factory()->create(['user_id' => $user->id]);
        Generation::factory()->succeeded()->create(['user_id' => $user->id]);
        Generation::factory()->failed()->create(['user_id' => $user->id]);
        $someoneElse = User::factory()->withoutCredits()->create();
        $this->givenATopUp($someoneElse, CreditLedgerEntryType::Grant, 7);
        Generation::factory()->count(2)->create(['user_id' => $someoneElse->id]);

        $balance = CreditLedgerEntry::query()->ownedBy($user)->balance();

        $this->assertBalance(6_000_000_000 - 123_387_500 - 109_312_500, 123_387_500, 1, $balance);
    }

    public function test_owned_by_accepts_a_user_id(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $this->givenATopUp($user, CreditLedgerEntryType::Purchase, 1_000_000_000);

        $this->assertBalance(1_000_000_000, 0, 0, CreditLedgerEntry::query()->ownedBy($user->id)->balance());
    }

    public function test_a_user_without_entries_has_nothing(): void
    {
        $user = User::factory()->withoutCredits()->create();

        $this->assertBalance(0, 0, 0, CreditLedgerEntry::query()->ownedBy($user)->balance());
    }

    /**
     * D-09: Pixelforge bills a generation we failed locally. Its provider cost is
     * stored on the generation, never in the ledger, so the balance is unchanged.
     */
    public function test_a_local_failure_that_succeeded_at_pixelforge_changes_nothing(): void
    {
        $generation = Generation::factory()->failedLocally()->create();
        $before = CreditLedgerEntry::query()->ownedBy($generation->user_id)->balance();

        $this->transitionGeneration($generation, self::snapshotFor($generation, GenerationStatus::Succeeded));

        $this->assertSame(87_450_000, $this->reloadedGeneration($generation)->provider_cost_nanodollars);
        $this->assertEquals($before, CreditLedgerEntry::query()->ownedBy($generation->user_id)->balance());
    }

    private function givenATopUp(User $user, CreditLedgerEntryType $type, int $amount): void
    {
        CreditLedgerEntry::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'amount_nanodollars' => $amount,
            'purchase_token' => $type === CreditLedgerEntryType::Purchase ? fake()->uuid() : null,
        ]);
    }

    /**
     * Strict comparisons, so a sum that came back as a string or a float fails.
     */
    private function assertBalance(int $available, int $reserved, int $running, CreditBalance $balance): void
    {
        $this->assertSame(
            ['available_nanodollars' => $available, 'reserved_nanodollars' => $reserved, 'running_generations' => $running],
            $balance->toArray(),
        );
    }
}
