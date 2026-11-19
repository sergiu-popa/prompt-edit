<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Actions;

use App\Domain\Billing\ValueObjects\PricingTable;
use App\Domain\Generation\DataTransferObjects\NewGenerationData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\GenerationTransitionOutcome;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\TestCase;

/**
 * The money side of an applied transition: a succeeded generation is charged
 * its actual cost from its own usage and rates, and its hold is released.
 */
final class TransitionGenerationActionSettlementTest extends TestCase
{
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use RefreshDatabase;

    private const array PUBLISHED_RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    public function test_queued_and_processing_snapshots_leave_the_costs_unset_and_only_the_hold(): void
    {
        $generation = $this->givenTheLighthouseGeneration();

        foreach ([GenerationStatus::Queued, GenerationStatus::Processing] as $status) {
            $outcome = $this->transitionGeneration($generation, self::snapshotFor($generation, $status));
            $generation = $this->reloadedGeneration($generation);

            $this->assertSame(GenerationTransitionOutcome::Applied, $outcome);
            $this->assertSame($status, $generation->status);
            $this->assertNull($generation->provider_cost_nanodollars);
            $this->assertNull($generation->user_charge_nanodollars);
            $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($generation));
        }
    }

    /**
     * AC5–AC8: hold, provider cost and charge are all exact n$. With today's
     * rates ×1.25 is always exact; AC7's 5.001 rate is the one row that
     * proves the ceil.
     *
     * @return array<string, array{NewGenerationData, array<string, int>, array<string, string>, int, int, int}>
     */
    public static function settlements(): array
    {
        $lighthouse = new NewGenerationData(self::LIGHTHOUSE_PROMPT, ImageSize::Landscape, ImageQuality::High, ['https://example.com/lighthouse-reference.png']);
        $docsMaximum = new NewGenerationData(str_repeat('a', 400), ImageSize::Landscape, ImageQuality::High, ['https://example.com/one.png', 'https://example.com/two.png']);
        $small = new NewGenerationData(str_repeat('b', 50), ImageSize::Square, ImageQuality::Low, []);
        $smallUsage = ['input_text_tokens' => 13, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 250, 'total_tokens' => 543];

        return [
            'AC5 the Lighthouse example' => [
                $lighthouse,
                ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6_450, 'reasoning_tokens' => 3_184, 'total_tokens' => 10_411],
                self::PUBLISHED_RATES,
                457_150_000,
                305_510_000,
                381_887_500,
            ],
            'AC6 the docs maximum, charged exactly its hold' => [
                $docsMaximum,
                ['input_text_tokens' => 115, 'input_image_tokens' => 1_530, 'output_tokens' => 6_450, 'reasoning_tokens' => 8_000, 'total_tokens' => 16_095],
                self::PUBLISHED_RATES,
                467_343_750,
                373_875_000,
                467_343_750,
            ],
            'AC7 the snapshot\'s own rates, with the markup rounded up' => [
                $small,
                $smallUsage,
                [...self::PUBLISHED_RATES, 'input_text_tokens' => '5.001'],
                21_906_250,
                14_390_013,
                17_987_517,
            ],
            'AC8 an actual above the hold' => [
                $small,
                $smallUsage,
                [...self::PUBLISHED_RATES, 'output_tokens' => '80.00'],
                21_906_250,
                25_590_000,
                31_987_500,
            ],
        ];
    }

    /**
     * @param  array<string, int>  $usage
     * @param  array<string, string>  $rates
     */
    #[DataProvider('settlements')]
    public function test_a_succeeded_snapshot_charges_its_actual_and_releases_the_hold(
        NewGenerationData $request,
        array $usage,
        array $rates,
        int $hold,
        int $providerCost,
        int $userCharge,
    ): void {
        $generation = $this->createGenerationFor(User::factory()->create(), $request);
        $this->assertSame([['hold', -$hold]], $this->ledgerEntriesFor($generation));

        $outcome = $this->transitionGeneration(
            $generation,
            self::snapshotFor($generation, GenerationStatus::Succeeded, ['usage' => $usage, 'rates' => $rates]),
        );

        $row = $this->reloadedGeneration($generation);
        $this->assertSame(GenerationTransitionOutcome::Applied, $outcome);
        $this->assertSame(GenerationStatus::Succeeded, $row->status);
        $this->assertSame($providerCost, $row->provider_cost_nanodollars);
        $this->assertSame($userCharge, $row->user_charge_nanodollars);
        $this->assertSame([['hold', -$hold], ['release', $hold], ['charge', -$userCharge]], $this->ledgerEntriesFor($generation));
        $this->assertSame(-$userCharge, $this->ledgerNetFor($generation));
    }

    /**
     * AC9: the release negates the hold entry itself, so neither the stored
     * estimate nor the pinned rates can change it.
     */
    public function test_the_release_negates_the_hold_entry_and_never_a_recomputed_estimate(): void
    {
        $generation = $this->givenTheLighthouseGeneration();
        DB::table('generations')->where('id', $generation->id)->update(['user_estimate_max_nanodollars' => 1]);
        config(['pricing.rates.output_tokens' => '80.00']);
        $this->app->forgetInstance(PricingTable::class);

        $this->transitionGeneration($generation, self::lighthouseSucceededSnapshotFor($generation));

        $this->assertSame(
            [['hold', -457_150_000], ['release', 457_150_000], ['charge', -381_887_500]],
            $this->ledgerEntriesFor($generation),
        );
        $this->assertSame(305_510_000, $this->reloadedGeneration($generation)->provider_cost_nanodollars);
    }
}
