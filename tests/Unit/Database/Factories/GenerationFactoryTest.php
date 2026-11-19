<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Factories;

use App\Domain\Billing\Actions\CalculateProviderCostAction;
use App\Domain\Billing\Actions\CalculateUserChargeAction;
use App\Domain\Billing\ValueObjects\TokenRates;
use App\Domain\Billing\ValueObjects\TokenUsage;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithGenerations;
use Tests\TestCase;

final class GenerationFactoryTest extends TestCase
{
    use InteractsWithGenerations;
    use RefreshDatabase;

    public function test_the_definition_is_a_pending_row_with_no_provider_fields(): void
    {
        $generation = $this->reloadedGeneration(Generation::factory()->create());

        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertNull($generation->provider_id);
        $this->assertNull($generation->provider_created_at);
        $this->assertNull($generation->started_at);
        $this->assertNull($generation->completed_at);
        $this->assertNull($generation->rates);
        $this->assertNull($generation->error_code);
        $this->assertNull($generation->local_failure_reason);
    }

    public function test_a_queued_row_has_a_unique_provider_id_and_its_creation_time(): void
    {
        [$first, $second] = Generation::factory()->count(2)->queued()->create()->all();
        $first = $this->reloadedGeneration($first);

        $this->assertSame(GenerationStatus::Queued, $first->status);
        $this->assertMatchesRegularExpression('/^gen_[0-9A-Z]{26}$/', (string) $first->provider_id);
        $this->assertNotSame($first->provider_id, $second->provider_id);
        $this->assertNotNull($first->provider_created_at);
        $this->assertNull($first->started_at);
    }

    public function test_a_processing_row_has_started_after_it_was_created(): void
    {
        $generation = $this->reloadedGeneration(Generation::factory()->processing()->create());

        $this->assertSame(GenerationStatus::Processing, $generation->status);
        $this->assertNotNull($generation->provider_id);
        $this->assertNotNull($generation->started_at);
        $this->assertNotNull($generation->provider_created_at);
        $this->assertTrue($generation->started_at->greaterThan($generation->provider_created_at));
        $this->assertNull($generation->completed_at);
    }

    public function test_a_succeeded_row_has_its_usage_rates_image_and_completion(): void
    {
        $generation = $this->reloadedGeneration(Generation::factory()->succeeded()->create());

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertNotNull($generation->provider_id);
        $this->assertNotNull($generation->image_url);
        $this->assertNotNull($generation->completed_at);
        $this->assertNotNull($generation->image_url_expires_at);
        $this->assertTrue($generation->image_url_expires_at->equalTo($generation->completed_at->addDay()));
        $this->assertNotNull($generation->started_at);
        $this->assertTrue($generation->completed_at->greaterThan($generation->started_at));
        $this->assertNull($generation->error_code);

        $usage = TokenUsage::from([
            'input_text_tokens' => $generation->input_text_tokens,
            'input_image_tokens' => $generation->input_image_tokens,
            'output_tokens' => $generation->output_tokens,
            'reasoning_tokens' => $generation->reasoning_tokens,
        ]);
        $charge = $this->app->make(CalculateUserChargeAction::class)->execute(
            $this->app->make(CalculateProviderCostAction::class)->execute($usage, TokenRates::from((array) $generation->rates)),
        );

        $this->assertLessThanOrEqual($generation->user_estimate_max_nanodollars, $charge->amount);
        $this->assertGreaterThanOrEqual($generation->user_estimate_min_nanodollars, $charge->amount);
    }

    public function test_a_failed_row_has_its_error_and_completion_but_no_result(): void
    {
        $generation = $this->reloadedGeneration(Generation::factory()->failed()->create());

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertNotEmpty($generation->error_message);
        $this->assertNotNull($generation->completed_at);
        $this->assertNull($generation->image_url);
        $this->assertNull($generation->output_tokens);
        $this->assertNull($generation->rates);
    }
}
