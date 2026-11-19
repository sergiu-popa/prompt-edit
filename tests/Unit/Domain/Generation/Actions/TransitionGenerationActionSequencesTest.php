<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Actions;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\GenerationTransitionOutcome;
use App\Domain\Generation\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\TestCase;

/**
 * Pixelforge delivers at least once, in any order, sends no `queued` event, and
 * a webhook can land before our create call returns (requirements §2.5).
 */
final class TransitionGenerationActionSequencesTest extends TestCase
{
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use RefreshDatabase;

    public function test_a_succeeded_webhook_before_the_create_response_wins_and_the_response_changes_nothing(): void
    {
        $generation = Generation::factory()->create();
        $createResponse = self::snapshotFor($generation, GenerationStatus::Queued);
        $webhook = self::snapshotFor($generation, GenerationStatus::Succeeded);

        $this->assertSame(GenerationTransitionOutcome::Applied, $this->transitionGeneration($generation, $webhook));
        $afterWebhook = $this->rawGenerationRow($generation);
        $this->travel(1)->minutes();

        $this->assertSame(GenerationTransitionOutcome::IgnoredTerminal, $this->transitionGeneration($generation, $createResponse));

        $this->assertSame($afterWebhook, $this->rawGenerationRow($generation));
        $this->assertSame('succeeded', $afterWebhook['status']);
        $this->assertSame('gen_' . strtoupper($generation->id), $afterWebhook['provider_id']);
        $this->assertSame(1_650, $afterWebhook['output_tokens']);
        $this->assertNotNull($afterWebhook['image_url']);
        $this->assertNotNull($afterWebhook['completed_at']);
    }

    public function test_a_processing_webhook_before_the_create_response_makes_the_response_stale(): void
    {
        $generation = Generation::factory()->create();
        $createResponse = self::snapshotFor($generation, GenerationStatus::Queued);

        $this->assertSame(
            GenerationTransitionOutcome::Applied,
            $this->transitionGeneration($generation, self::snapshotFor($generation, GenerationStatus::Processing)),
        );
        $afterWebhook = $this->rawGenerationRow($generation);
        $this->travel(1)->minutes();

        $this->assertSame(GenerationTransitionOutcome::IgnoredStale, $this->transitionGeneration($generation, $createResponse));

        $this->assertSame($afterWebhook, $this->rawGenerationRow($generation));
        $this->assertSame('processing', $afterWebhook['status']);
    }

    public function test_a_retried_processing_webhook_after_succeeded_is_ignored(): void
    {
        $generation = Generation::factory()->queued()->create();
        $processing = self::snapshotFor($generation, GenerationStatus::Processing);

        $this->transitionGeneration($generation, self::snapshotFor($generation, GenerationStatus::Succeeded));
        $afterSuccess = $this->rawGenerationRow($generation);
        $this->travel(1)->minutes();

        $this->assertSame(GenerationTransitionOutcome::IgnoredTerminal, $this->transitionGeneration($generation, $processing));
        $this->assertSame($afterSuccess, $this->rawGenerationRow($generation));
    }

    public function test_succeeded_never_flips_to_failed(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->transitionGeneration($generation, self::snapshotFor($generation, GenerationStatus::Succeeded));
        $afterSuccess = $this->rawGenerationRow($generation);
        $this->travel(1)->minutes();

        $this->assertSame(
            GenerationTransitionOutcome::IgnoredConflict,
            $this->transitionGeneration($generation, self::snapshotFor($generation, GenerationStatus::Failed)),
        );
        $this->assertSame($afterSuccess, $this->rawGenerationRow($generation));
    }

    public function test_failed_never_flips_to_succeeded(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->transitionGeneration($generation, self::snapshotFor($generation, GenerationStatus::Failed));
        $afterFailure = $this->rawGenerationRow($generation);
        $this->travel(1)->minutes();

        $this->assertSame(
            GenerationTransitionOutcome::IgnoredConflict,
            $this->transitionGeneration($generation, self::snapshotFor($generation, GenerationStatus::Succeeded)),
        );
        $this->assertSame($afterFailure, $this->rawGenerationRow($generation));
    }

    public function test_replaying_one_snapshot_applies_it_once(): void
    {
        $generation = Generation::factory()->processing()->create();
        $snapshot = self::snapshotFor($generation, GenerationStatus::Succeeded);

        $outcomes = [$this->transitionGeneration($generation, $snapshot)];
        $afterFirst = $this->rawGenerationRow($generation);

        for ($replay = 0; $replay < 4; $replay++) {
            $this->travel(1)->minutes();
            $outcomes[] = $this->transitionGeneration($generation, $snapshot);
        }

        $this->assertSame(
            [
                GenerationTransitionOutcome::Applied,
                GenerationTransitionOutcome::IgnoredDuplicate,
                GenerationTransitionOutcome::IgnoredDuplicate,
                GenerationTransitionOutcome::IgnoredDuplicate,
                GenerationTransitionOutcome::IgnoredDuplicate,
            ],
            $outcomes,
        );
        $this->assertSame($afterFirst, $this->rawGenerationRow($generation));
    }
}
