<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Jobs\SubmitGenerationJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Generate form's POST, as the page sends it. The submit job is faked, because
 * the test `sync` queue would otherwise run it inline after the commit (PE-009).
 *
 * @phpstan-require-extends TestCase
 */
trait SubmitsGenerations
{
    /**
     * Called by Laravel's test lifecycle for every test that uses the trait.
     */
    protected function setUpSubmitsGenerations(): void
    {
        Queue::fake([SubmitGenerationJob::class]);
    }

    /**
     * The docs' Lighthouse request with a fresh form submission token: 45 code points,
     * `high`, `1536x1024` and one reference.
     *
     * @return array{prompt: string, size: string, quality: string, reference_images: list<string>, submission_token: string}
     */
    protected function lighthouseFields(): array
    {
        return [
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'submission_token' => (string) Str::uuid(),
        ];
    }

    /**
     * Posts the Lighthouse fields, changed by `$overrides`, from the Generate page as
     * `$as` (a new user when none is given). A null override drops that field.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function submitGeneration(array $overrides = [], ?User $as = null): TestResponse
    {
        $fields = array_filter([...$this->lighthouseFields(), ...$overrides], fn (mixed $value): bool => $value !== null);

        return $this->actingAs($as ?? User::factory()->create())
            ->from(route('dashboard'))
            ->post(route('generations.store'), $fields);
    }

    protected function assertNothingWasCreated(): void
    {
        $this->assertSame(0, DB::table('generations')->count(), 'A generation was created.');
        $this->assertSame(0, DB::table('credit_ledger_entries')->count(), 'A ledger entry was written.');
        Queue::assertNotPushed(SubmitGenerationJob::class);
    }
}
