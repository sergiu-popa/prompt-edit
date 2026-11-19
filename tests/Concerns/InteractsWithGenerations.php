<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Generation\Actions\TransitionGenerationAction;
use App\Domain\Generation\DataTransferObjects\LocalGenerationFailureData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\GenerationTransitionOutcome;
use App\Domain\Generation\Models\Generation;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Generation rows in a given status, the transition applied to them, and the
 * row as stored. Pixelforge objects live in InteractsWithGenerationSnapshots.
 *
 * @phpstan-require-extends TestCase
 */
trait InteractsWithGenerations
{
    use AssertsInstants;

    /**
     * A persisted row from the factory state for the status.
     */
    protected function givenAGenerationIn(GenerationStatus $status): Generation
    {
        $factory = Generation::factory();

        return match ($status) {
            GenerationStatus::Pending => $factory->create(),
            GenerationStatus::Queued => $factory->queued()->create(),
            GenerationStatus::Processing => $factory->processing()->create(),
            GenerationStatus::Succeeded => $factory->succeeded()->create(),
            GenerationStatus::Failed => $factory->failed()->create(),
        };
    }

    protected function transitionGeneration(
        Generation $generation,
        GenerationSnapshot|LocalGenerationFailureData $change,
    ): GenerationTransitionOutcome {
        return $this->app->make(TransitionGenerationAction::class)->execute($generation, $change);
    }

    /**
     * Every column as the database returns it, without casts, so an
     * untouched row compares equal down to `updated_at`.
     *
     * @return array<string, mixed>
     */
    protected function rawGenerationRow(Generation $generation): array
    {
        return (array) DB::table('generations')->where('id', $generation->getKey())->first();
    }

    protected function reloadedGeneration(Generation $generation): Generation
    {
        return Generation::query()->whereKey($generation->getKey())->firstOrFail();
    }
}
