<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\Actions;

use App\Domain\Billing\Actions\GrantOpeningCreditsAction;
use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\TestCase;

/**
 * AC 17, 18: accounts from before balances are made without the factory's grant,
 * and their generations hold and settle through the ledger as they did then.
 */
final class GrantOpeningCreditsActionTest extends TestCase
{
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use RefreshDatabase;

    public function test_a_legacy_user_is_granted_the_starter_plus_their_settled_charges(): void
    {
        $legacy = $this->givenALegacyUserCharged(18_906_250, 74_337_500);

        $this->assertSame(1, $this->grantOpeningCredits());

        $this->assertSame([['grant', 1_093_243_750]], $this->topUpsFor($legacy));
        $this->assertSame(1_000_000_000, $this->availableFor($legacy));
    }

    /**
     * The hold stays active through the grant and settles normally afterwards.
     */
    public function test_an_active_hold_still_takes_its_share_after_the_grant(): void
    {
        $legacy = $this->givenALegacyUserCharged(18_906_250, 74_337_500);
        Generation::factory()->create([
            'user_id' => $legacy->id,
            'user_estimate_min_nanodollars' => 14_000_000,
            'user_estimate_typical_nanodollars' => 17_000_000,
            'user_estimate_max_nanodollars' => 21_850_000,
        ]);

        $this->grantOpeningCredits();

        $this->assertSame([['grant', 1_093_243_750]], $this->topUpsFor($legacy));
        $this->assertSame(978_150_000, $this->availableFor($legacy));
    }

    /**
     * A failure releases its hold in full, and a local failure Pixelforge billed
     * (D-09) has a provider cost on the generation but no charge in the ledger.
     */
    public function test_failed_generations_add_nothing(): void
    {
        $legacy = User::factory()->withoutCredits()->create();
        Generation::factory()->failed()->create(['user_id' => $legacy->id]);
        $billedAfterALocalFailure = Generation::factory()->failedLocally()->create(['user_id' => $legacy->id]);
        $this->transitionGeneration($billedAfterALocalFailure, self::snapshotFor($billedAfterALocalFailure, GenerationStatus::Succeeded));

        $this->grantOpeningCredits();

        $this->assertSame([['grant', 1_000_000_000]], $this->topUpsFor($legacy));
        $this->assertSame(1_000_000_000, $this->availableFor($legacy));
    }

    public function test_a_user_who_has_a_grant_is_skipped(): void
    {
        $granted = User::factory()->withCredits(10_000_000)->create();
        Generation::factory()->succeeded()->create(['user_id' => $granted->id]);
        $legacy = User::factory()->withoutCredits()->create();

        $this->assertSame(1, $this->grantOpeningCredits());

        $this->assertSame([['grant', 10_000_000]], $this->topUpsFor($granted));
        $this->assertSame([['grant', 1_000_000_000]], $this->topUpsFor($legacy));
    }

    public function test_a_second_run_grants_nobody(): void
    {
        $legacy = $this->givenALegacyUserCharged(18_906_250);
        $this->grantOpeningCredits();

        $this->assertSame(0, $this->grantOpeningCredits());

        $this->assertSame([['grant', 1_018_906_250]], $this->topUpsFor($legacy));
    }

    public function test_the_configured_starter_is_the_base(): void
    {
        config(['credits.starter_grant_nanodollars' => 10_000_000]);
        $legacy = $this->givenALegacyUserCharged(18_906_250);

        $this->grantOpeningCredits();

        $this->assertSame([['grant', 28_906_250]], $this->topUpsFor($legacy));
    }

    public function test_it_grants_every_legacy_user_at_once(): void
    {
        $legacyUsers = User::factory()->withoutCredits()->count(3)->create();

        $this->assertSame(3, $this->grantOpeningCredits());

        foreach ($legacyUsers as $legacy) {
            $this->assertSame([['grant', 1_000_000_000]], $this->topUpsFor($legacy));
        }
    }

    private function givenALegacyUserCharged(int ...$charges): User
    {
        $legacy = User::factory()->withoutCredits()->create();

        foreach ($charges as $charge) {
            Generation::factory()->succeeded()->create(['user_id' => $legacy->id, 'user_charge_nanodollars' => $charge]);
        }

        return $legacy;
    }

    private function grantOpeningCredits(): int
    {
        return $this->app->make(GrantOpeningCreditsAction::class)->execute();
    }

    private function availableFor(User $user): int
    {
        return CreditLedgerEntry::query()->ownedBy($user)->balance()->available->amount;
    }
}
