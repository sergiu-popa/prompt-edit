<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Generation\Actions\CreateGenerationAction;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;

/**
 * When the create request leaves, the row and its hold are already committed:
 * another connection, as a worker or a webhook would use, can read them. The
 * rows must really commit, so this file truncates instead of wrapping each
 * test in a transaction.
 */
final class SubmitGenerationAfterCommitTest extends TestCase
{
    use DatabaseTruncation;
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use InteractsWithPixelforgeApi;

    private const string OBSERVER = 'observer';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.' . self::OBSERVER => config('database.connections.pgsql')]);

        // Committed rows must not leak into the RefreshDatabase tests that run after this one,
        // and neither must the rate budget's count the submit took (D-44).
        $this->beforeApplicationDestroyed(function (): void {
            DB::purge(self::OBSERVER);
            DB::statement('truncate table generations, users, cache restart identity cascade');
        });
    }

    public function test_the_row_and_its_hold_are_committed_before_the_create_request(): void
    {
        $seenByObserver = null;
        Http::fake(function () use (&$seenByObserver): PromiseInterface {
            $observer = DB::connection(self::OBSERVER);
            $row = $observer->table('generations')->first(['id', 'status', 'provider_id']);
            $this->assertNotNull($row);
            $this->assertIsString($row->id);
            $seenByObserver = [
                'status' => $row->status,
                'provider_id' => $row->provider_id,
                'ledger' => $observer->table('credit_ledger_entries')
                    ->where('generation_id', $row->id)
                    ->orderBy('id')
                    ->get(['type', 'amount_nanodollars'])
                    ->map(fn (object $entry): array => (array) $entry)
                    ->all(),
            ];

            return self::pixelforgeAccepted(self::generationObjectFor(
                Generation::query()->whereKey($row->id)->firstOrFail(),
                GenerationStatus::Queued,
            ));
        });

        $generation = $this->app->make(CreateGenerationAction::class)->execute(User::factory()->create(), self::lighthouseRequest());

        $this->assertSame([
            'status' => 'pending',
            'provider_id' => null,
            'ledger' => [['type' => 'hold', 'amount_nanodollars' => -457_150_000]],
        ], $seenByObserver);
        $this->assertSame(GenerationStatus::Queued, $this->reloadedGeneration($generation)->status);
    }
}
