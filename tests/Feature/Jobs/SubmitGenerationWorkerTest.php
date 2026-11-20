<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Generation\Actions\CreateGenerationAction;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Models\Generation;
use App\Models\User;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;

/**
 * AC 17 on the real `database` queue, with the worker's own `--tries=3`: the job's
 * `retryUntil` outlasts any number of releases, and once it has passed, the worker
 * fails the job and `failed()` stops the generation.
 */
final class SubmitGenerationWorkerTest extends TestCase
{
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        $this->travelTo(CarbonImmutable::parse('2026-11-20T10:00:00Z'));
        Http::fake(['*' => self::pixelforgeErrorFor(PixelforgeErrorCode::Overloaded, headers: ['Retry-After' => '2'])]);
        $this->generation = $this->app->make(CreateGenerationAction::class)->execute(User::factory()->create(), self::lighthouseRequest());
    }

    public function test_the_workers_tries_never_end_a_job_that_is_waiting(): void
    {
        for ($run = 1; $run <= 5; $run++) {
            $this->runTheWorkerOnce();
            $this->travelPastTheRelease();
        }

        $this->assertCount(5, $this->recordedCreateRequests());
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(5, DB::table('jobs')->value('attempts'));
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(GenerationStatus::Pending, $this->reloadedGeneration($this->generation)->status);
    }

    public function test_past_retry_until_the_worker_fails_the_job_and_the_generation_fails_free(): void
    {
        $this->runTheWorkerOnce();
        $this->travelTo(CarbonImmutable::parse('2026-11-20T10:40:01Z'));

        $this->runTheWorkerOnce();

        $this->assertCount(1, $this->recordedCreateRequests());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $row = $this->reloadedGeneration($this->generation);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(LocalFailureReason::ProviderOverloaded, $row->local_failure_reason);
        $this->assertSame([['hold', -457_150_000], ['release', 457_150_000]], $this->ledgerEntriesFor($this->generation));
    }

    private function runTheWorkerOnce(): void
    {
        $this->assertSame(0, Artisan::call('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--tries' => 3,
            '--sleep' => 0,
        ]));
    }

    private function travelPastTheRelease(): void
    {
        $availableAt = DB::table('jobs')->value('available_at');
        $this->assertIsInt($availableAt);

        $this->travelTo(CarbonImmutable::createFromTimestamp($availableAt));
    }
}
