<?php

declare(strict_types=1);

namespace Tests\Feature\Generation;

use App\Http\Controllers\Generation\CreateGenerationController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\SubmitsGenerations;
use Tests\TestCase;

/**
 * AC 9, 11: the server refuses a generation the balance can't hold, as a field
 * error on Generate with the amounts in integer n$. The request is PE-017's worked
 * example, whose max is 86,312,500 n$.
 */
final class CreateGenerationAffordabilityTest extends TestCase
{
    use InteractsWithGenerations;
    use RefreshDatabase;
    use SubmitsGenerations;

    private const array SHORTFALL = [
        'required_nanodollars' => 86_312_500,
        'available_nanodollars' => 6_312_500,
        'shortfall_nanodollars' => 80_000_000,
    ];

    public function test_an_unaffordable_generation_goes_back_to_generate_with_the_balance_error_and_the_amounts(): void
    {
        $user = User::factory()->withCredits(6_312_500)->create();

        $this->submitDusk($user)
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors(['balance' => CreateGenerationController::INSUFFICIENT_CREDITS_MESSAGE])
            ->assertInertiaFlash('credit_shortfall', self::SHORTFALL);

        $this->assertNothingWasCreated();
    }

    public function test_followed_the_page_carries_the_shortfall_and_the_unchanged_balance(): void
    {
        $user = User::factory()->withCredits(6_312_500)->create();
        $this->submitDusk($user);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->component('Generations/Create')
            ->hasFlash('credit_shortfall', self::SHORTFALL)
            ->where('errors.balance', CreateGenerationController::INSUFFICIENT_CREDITS_MESSAGE)
            ->where('credits.available_nanodollars', 6_312_500));
    }

    public function test_an_equal_balance_still_lands_on_history(): void
    {
        $user = User::factory()->withCredits(self::DUSK_HOLD)->create();

        $this->submitDusk($user)
            ->assertRedirect(route('generations.index'))
            ->assertSessionHasNoErrors();

        $this->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page->where('credits.available_nanodollars', 0));
    }

    private function submitDusk(User $user): TestResponse
    {
        return $this->submitGeneration([
            'prompt' => self::DUSK_PROMPT,
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
        ], $user);
    }
}
