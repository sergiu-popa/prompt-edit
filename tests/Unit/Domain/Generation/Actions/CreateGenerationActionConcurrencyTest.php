<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Actions;

use App\Domain\Generation\DataTransferObjects\NewGenerationData;
use App\Domain\Generation\Models\Generation;
use App\Jobs\SubmitGenerationJob;
use App\Models\User;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\WaitsForDatabaseLocks;
use Tests\TestCase;

/**
 * Two requests of one user at once. The second, in its own process and
 * connection, waits on the user-row lock the first holds until it commits
 * (D-68). Of one form submission (D-38), it then finds the first's row and
 * gets it back instead of creating its own. The child must see committed rows,
 * so this file truncates instead of wrapping each test in a transaction.
 */
final class CreateGenerationActionConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use WaitsForDatabaseLocks;

    protected function setUp(): void
    {
        parent::setUp();

        // Committed rows must not leak into the RefreshDatabase tests that run after this one.
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('truncate table generations, users restart identity cascade');
        });
    }

    public function test_a_concurrent_request_of_the_same_submission_gets_the_first_generation(): void
    {
        $user = User::factory()->create();
        $token = (string) Str::uuid();
        $request = self::lighthouseRequest();
        Queue::fake([SubmitGenerationJob::class]);

        DB::beginTransaction();

        try {
            $first = $this->createGenerationFor(
                $user,
                new NewGenerationData($request->prompt, $request->size, $request->quality, $request->referenceImages, $token),
            );

            $child = $this->startChild($user->id, $token);

            $childWaiting = $this->waitUntilBackendsWaitOnALock(1);
        } finally {
            DB::commit();
        }

        $result = $this->resultOf($child);

        $this->assertTrue($childWaiting, 'The child did not wait on the uncommitted row in time.');
        $this->assertSame(['id' => $first->id, 'was_recently_created' => false, 'pushed_jobs' => 0], $result);
        $this->assertSame(1, Generation::query()->count());
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($first));
        Queue::assertPushed(SubmitGenerationJob::class, 1);
    }

    private function startChild(int $userId, string $token): InvokedProcess
    {
        return Process::path(base_path())
            ->timeout(self::CHILD_TIMEOUT_SECONDS)
            ->env($this->childDatabaseEnvironment())
            ->start([PHP_BINARY, 'tests/scripts/create-generation.php', (string) $userId, $token]);
    }
}
