<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Generation\Actions\CreateGenerationAction;
use App\Domain\Generation\Actions\TransitionGenerationAction;
use App\Domain\Generation\DataTransferObjects\LocalGenerationFailureData;
use App\Domain\Generation\DataTransferObjects\NewGenerationData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\GenerationTransitionOutcome;
use App\Domain\Generation\Models\Generation;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Jobs\SubmitGenerationJob;
use App\Models\User;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
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
     * The Pixelforge docs' pricing example: 45 code points, so e = 12.
     */
    protected const string LIGHTHOUSE_PROMPT = 'A lighthouse on a cliff at dawn, oil painting';

    /**
     * PE-014's worked example: 19 code points, so e = 5 and the text bounds are 4 to 6.
     */
    protected const string RED_FOX_PROMPT = '[succeed] a red fox';

    /**
     * The red fox's hold: ceil(17,480,000 × 125 / 100) n$.
     */
    protected const int RED_FOX_HOLD = 21_850_000;

    /**
     * PE-017's worked example: 30 code points, medium, 1024x1024 and no references.
     */
    protected const string DUSK_PROMPT = 'A lighthouse at dusk [succeed]';

    /**
     * The dusk request's hold, its max user price: ceil(69,050,000 × 125 / 100) n$.
     */
    protected const int DUSK_HOLD = 86_312_500;

    /**
     * The submit job is held back, so the row stays pending with its hold, as
     * a test of anything after creation expects; on the test's `sync` queue
     * it would otherwise run inline. Tests of the dispatch itself call
     * CreateGenerationAction directly.
     */
    protected function createGenerationFor(User $user, NewGenerationData $data): Generation
    {
        if (! Queue::getFacadeRoot() instanceof QueueFake) {
            Queue::fake([SubmitGenerationJob::class]);
        }

        return $this->app->make(CreateGenerationAction::class)->execute($user, $data);
    }

    /**
     * The docs' example request: high, 1536x1024 and the docs' one reference image.
     * Static, so data providers can build it too.
     */
    protected static function lighthouseRequest(): NewGenerationData
    {
        return new NewGenerationData(
            self::LIGHTHOUSE_PROMPT,
            ImageSize::Landscape,
            ImageQuality::High,
            ['https://example.com/style.png'],
        );
    }

    /**
     * PE-017's worked example, from the Generate form when it carries a token.
     */
    protected static function duskRequest(?string $submissionToken = null): NewGenerationData
    {
        return new NewGenerationData(self::DUSK_PROMPT, ImageSize::Square, ImageQuality::Medium, [], $submissionToken);
    }

    /**
     * The docs' example, created through the action for a new user.
     */
    protected function givenTheLighthouseGeneration(): Generation
    {
        return $this->createGenerationFor(User::factory()->create(), self::lighthouseRequest());
    }

    /**
     * PE-014's worked example, created through the action for a new user: low,
     * 1024x1024 and no references, holding 21,850,000 n$.
     */
    protected function givenTheRedFoxGeneration(): Generation
    {
        return $this->createGenerationFor(
            User::factory()->create(),
            new NewGenerationData(self::RED_FOX_PROMPT, ImageSize::Square, ImageQuality::Low, []),
        );
    }

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

    /**
     * The raw row without the `submit_*` columns, which every submit attempt writes
     * by design (D-45): equal when status, costs, provider fields and times are.
     *
     * @return array<string, mixed>
     */
    protected function rawGenerationRowWithoutSubmitBookkeeping(Generation $generation): array
    {
        return array_filter(
            $this->rawGenerationRow($generation),
            fn (string $column): bool => ! str_starts_with($column, 'submit_') || $column === 'submission_token',
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The raw row without the submit job's and the reconciler's own record (D-45,
     * PE-014): equal when nothing Pixelforge said, and no money, changed.
     *
     * @return array<string, mixed>
     */
    protected function rawGenerationRowWithoutBookkeeping(Generation $generation): array
    {
        return array_filter(
            $this->rawGenerationRowWithoutSubmitBookkeeping($generation),
            fn (string $column): bool => ! str_starts_with($column, 'reconcile_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    protected function reloadedGeneration(Generation $generation): Generation
    {
        return Generation::query()->whereKey($generation->getKey())->firstOrFail();
    }
}
