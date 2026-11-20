<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing\Actions;

use App\Domain\Billing\Actions\CheckGenerationMoneyAction;
use App\Domain\Generation\DataTransferObjects\LocalGenerationFailureData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\TestCase;

/**
 * The read-only money check behind `app:check-generation-money`, on PE-014's red fox:
 * low, 1024x1024, no references, a 19-character prompt (text 4–6, reasoning 0–500,
 * output 280 exactly) and a hold of 21,850,000 n$.
 */
final class CheckGenerationMoneyActionTest extends TestCase
{
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use RefreshDatabase;

    /**
     * 5 × 5,000 + 280 × 40,000 + 312 × 12,500 = 15,125,000 n$, charged 18,906,250 n$.
     */
    private const array RED_FOX_USAGE = [
        'input_text_tokens' => 5,
        'input_image_tokens' => 0,
        'output_tokens' => 280,
        'reasoning_tokens' => 312,
        'total_tokens' => 597,
    ];

    public function test_a_succeeded_generation_has_no_problems(): void
    {
        $this->assertSame([], $this->check($this->givenTheSucceededRedFox()));
    }

    public function test_a_failed_generation_has_no_problems(): void
    {
        $redFox = $this->givenTheRedFoxGeneration();
        $this->transitionGeneration($redFox, self::snapshotFor($redFox, GenerationStatus::Failed));

        $this->assertSame([], $this->check($redFox));
    }

    /**
     * D-09: Pixelforge billed a generation we had failed `lost`, so it carries a
     * provider cost but no charge.
     */
    public function test_a_lost_generation_that_later_succeeded_has_no_problems(): void
    {
        $redFox = $this->givenTheRedFoxGeneration();
        $this->transitionGeneration($redFox, new LocalGenerationFailureData(LocalFailureReason::Lost, null, null, null));
        $this->transitionGeneration($redFox, self::snapshotFor($redFox, GenerationStatus::Succeeded, ['usage' => self::RED_FOX_USAGE]));

        $row = $this->reloadedGeneration($redFox);
        $this->assertSame(15_125_000, $row->provider_cost_nanodollars);
        $this->assertSame([], $this->check($row));
    }

    public function test_a_queued_generation_is_not_terminal_yet(): void
    {
        $redFox = $this->givenTheRedFoxGeneration();
        $this->transitionGeneration($redFox, self::snapshotFor($redFox, GenerationStatus::Queued));

        $this->assertSame(['not terminal yet'], $this->check($redFox));
    }

    public function test_a_charge_off_by_one_nanodollar_is_a_problem(): void
    {
        $redFox = $this->givenTheSucceededRedFox();
        $this->tamper($redFox, ['user_charge_nanodollars' => 18_906_251]);

        $this->assertSame(['the charge is 18906251 n$, expected 18906250 n$'], $this->check($redFox));
    }

    public function test_a_provider_cost_off_is_a_problem(): void
    {
        $redFox = $this->givenTheSucceededRedFox();
        $this->tamper($redFox, ['provider_cost_nanodollars' => 15_124_999]);

        $this->assertSame(['the provider cost is 15124999 n$, usage × rates gives 15125000 n$'], $this->check($redFox));
    }

    public function test_a_missing_release_is_a_problem(): void
    {
        $redFox = $this->givenTheRedFoxGeneration();
        $this->tamper($redFox, ['status' => GenerationStatus::Failed->value, 'provider_cost_nanodollars' => 0, 'user_charge_nanodollars' => 0]);

        $this->assertSame(['the ledger is [hold -21850000], expected [hold -21850000, release 21850000]'], $this->check($redFox));
    }

    public function test_output_tokens_other_than_estimated_are_a_problem(): void
    {
        $redFox = $this->givenTheSucceededRedFox();
        $this->tamper($redFox, ['output_tokens' => 281]);

        $this->assertContains('output_tokens is 281, estimated exactly 280', $this->check($redFox));
    }

    public function test_text_tokens_above_the_bound_are_a_problem(): void
    {
        $redFox = $this->givenTheSucceededRedFox();
        $this->tamper($redFox, ['input_text_tokens' => 7]);

        $this->assertContains('input_text_tokens is 7, outside 4–6', $this->check($redFox));
    }

    public function test_a_hold_that_differs_from_the_estimate_max_is_a_problem(): void
    {
        $redFox = $this->givenTheRedFoxGeneration();
        $this->tamper($redFox, ['user_estimate_max_nanodollars' => self::RED_FOX_HOLD + 1]);

        $problems = $this->check($redFox);

        $this->assertContains('the estimate max is 21850000 n$ but the row holds 21850001 n$', $problems);
        $this->assertContains('the hold is -21850000 n$, expected -21850001 n$', $problems);
    }

    private function givenTheSucceededRedFox(): Generation
    {
        $redFox = $this->givenTheRedFoxGeneration();
        $this->transitionGeneration($redFox, self::snapshotFor($redFox, GenerationStatus::Succeeded, ['usage' => self::RED_FOX_USAGE]));

        return $redFox;
    }

    /**
     * A raw write past the transition, as a bug would leave the row.
     *
     * @param  array<string, mixed>  $columns
     */
    private function tamper(Generation $generation, array $columns): void
    {
        DB::table('generations')->where('id', $generation->id)->update($columns);
    }

    /**
     * @return list<string>
     */
    private function check(Generation $generation): array
    {
        return $this->app->make(CheckGenerationMoneyAction::class)->execute($this->reloadedGeneration($generation));
    }
}
