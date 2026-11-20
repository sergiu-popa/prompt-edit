<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Exceptions\InsufficientCreditsException;
use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Domain\Billing\ValueObjects\CreditBalance;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Models\User;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\TestCase;

/**
 * AC 6, 7, 8 and 13: PE-017's worked example from a $1.0000 balance, through the
 * create action and the one transition action, as Pixelforge's answers settle it.
 */
final class CreditBalanceSettlementTest extends TestCase
{
    use CapturesLogMessages;
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use RefreshDatabase;

    public function test_creating_the_worked_example_holds_its_max(): void
    {
        $user = User::factory()->create();

        $generation = $this->createGenerationFor($user, self::duskRequest());

        $this->assertSame(
            [58_162_500, 72_237_500, self::DUSK_HOLD],
            [$generation->user_estimate_min_nanodollars, $generation->user_estimate_typical_nanodollars, $generation->user_estimate_max_nanodollars],
        );
        $this->assertBalance(913_687_500, self::DUSK_HOLD, 1, $this->balanceOf($user));
    }

    public function test_a_success_releases_the_hold_and_charges_the_actual(): void
    {
        $user = User::factory()->create();
        $generation = $this->createGenerationFor($user, self::duskRequest());

        $this->transitionGeneration($generation, self::succeededWithUsage($generation, [8, 0, 1_100, 1_234]));

        $settled = $this->reloadedGeneration($generation);
        $this->assertSame(59_465_000, $settled->provider_cost_nanodollars);
        $this->assertSame(74_331_250, $settled->user_charge_nanodollars);
        $this->assertBalance(925_668_750, 0, 0, $this->balanceOf($user));
    }

    public function test_a_failure_returns_the_balance_to_exactly_what_it_was(): void
    {
        $user = User::factory()->create();
        $generation = $this->createGenerationFor($user, self::duskRequest());

        $this->transitionGeneration($generation, self::snapshotFor($generation, GenerationStatus::Failed));

        $this->assertBalance(1_000_000_000, 0, 0, $this->balanceOf($user));
    }

    /**
     * Requirements §6: the text rate rose to $85.00 per 1M between estimate and
     * creation, so the actual, 87,312,500 n$, exceeds the 86,312,500 n$ hold. It is
     * charged in full, because we owe Pixelforge, and the balance goes negative.
     */
    public function test_a_charge_above_the_hold_goes_negative_is_logged_and_blocks_the_next_create(): void
    {
        $user = User::factory()->withCredits(self::DUSK_HOLD)->create();
        $generation = $this->createGenerationFor($user, self::duskRequest());
        $this->assertBalance(0, self::DUSK_HOLD, 1, $this->balanceOf($user));

        $this->transitionGeneration($generation, self::succeededWithUsage($generation, [10, 0, 1_100, 2_000], textRate: '85.00'));

        $this->assertSame(87_312_500, $this->reloadedGeneration($generation)->user_charge_nanodollars);
        $this->assertBalance(-1_000_000, 0, 0, $this->balanceOf($user));
        $warnings = array_values(array_filter(
            $this->loggedMessages(),
            fn ($message): bool => $message->level === 'warning' && str_contains($message->message, 'exceeded its hold'),
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame(
            ['generation_id' => $generation->id, 'hold_nanodollars' => self::DUSK_HOLD, 'charge_nanodollars' => 87_312_500],
            $warnings[0]->context,
        );

        try {
            $this->createGenerationFor($user, self::duskRequest());
            $this->fail('A create went through on a negative balance.');
        } catch (InsufficientCreditsException $exception) {
            $this->assertSame(87_312_500, $exception->shortfall()->amount);
        }
    }

    /**
     * @param  array{int, int, int, int}  $usage  input text, input image, output and reasoning tokens
     */
    private static function succeededWithUsage(Generation $generation, array $usage, string $textRate = '5.00'): GenerationSnapshot
    {
        [$text, $image, $output, $reasoning] = $usage;

        return self::snapshotFor($generation, GenerationStatus::Succeeded, [
            'usage' => [
                'input_text_tokens' => $text,
                'input_image_tokens' => $image,
                'output_tokens' => $output,
                'reasoning_tokens' => $reasoning,
                'total_tokens' => $text + $image + $output + $reasoning,
            ],
            'rates' => [
                'input_text_tokens' => $textRate,
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ]);
    }

    private function balanceOf(User $user): CreditBalance
    {
        return CreditLedgerEntry::query()->ownedBy($user)->balance();
    }

    private function assertBalance(int $available, int $reserved, int $running, CreditBalance $balance): void
    {
        $this->assertSame(
            ['available_nanodollars' => $available, 'reserved_nanodollars' => $reserved, 'running_generations' => $running],
            $balance->toArray(),
        );
    }
}
