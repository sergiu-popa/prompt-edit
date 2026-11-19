<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Domain\Generation\Models\Generation;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\DeliversPixelforgeWebhooks;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\WaitsForDatabaseLocks;
use Tests\TestCase;

/**
 * Two deliveries of one event race through the endpoint, each in its own
 * process and on its own database connection. The unique event id with
 * `ON CONFLICT DO NOTHING` lets exactly one apply and the other answer 2xx,
 * never 5xx.
 */
final class PixelforgeWebhookConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use DeliversPixelforgeWebhooks;
    use InteractsWithCreditLedger;
    use WaitsForDatabaseLocks;

    protected function setUp(): void
    {
        parent::setUp();

        // Committed rows must not leak into the RefreshDatabase tests that run after this one.
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('truncate table pixelforge_webhook_events, generations, users restart identity cascade');
        });
    }

    public function test_two_racing_deliveries_of_one_event_record_and_charge_once(): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $body = json_encode(self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($lighthouse)), JSON_THROW_ON_ERROR);
        $signature = $this->signedNow($body);

        DB::beginTransaction();

        try {
            Generation::query()->whereKey($lighthouse->id)->lockForUpdate()->firstOrFail();

            $children = [$this->startDelivery($signature, $body), $this->startDelivery($signature, $body)];

            $bothWaiting = $this->waitUntilBackendsWaitOnALock(2);
        } finally {
            DB::commit();
        }

        $statuses = array_map(fn (InvokedProcess $child): mixed => $this->resultOf($child)['status'], $children);

        $this->assertTrue($bothWaiting, 'Both children did not wait on the row lock in time.');
        $this->assertSame([200, 200], $statuses);
        $this->assertCount(1, $this->storedWebhookEvents());
        $this->assertSame(
            [['hold', -457_150_000], ['release', 457_150_000], ['charge', -381_887_500]],
            $this->ledgerEntriesFor($lighthouse),
        );
        $this->assertSame(-381_887_500, $this->ledgerNetFor($lighthouse));
    }

    /**
     * The first delivery rolls back while both copies wait on its event id:
     * one of them then records the event as the first, and the other is a
     * duplicate of that one.
     */
    public function test_a_duplicate_waiting_on_a_delivery_that_rolls_back_is_processed_normally(): void
    {
        $event = self::pixelforgeEvent('ping', null);
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $signature = $this->signedNow($body);

        DB::beginTransaction();

        try {
            DB::table('pixelforge_webhook_events')->insert([
                'event_id' => $event['id'],
                'type' => 'ping',
                'delivery_attempt' => 1,
                'received_at' => now(),
            ]);

            $children = [$this->startDelivery($signature, $body), $this->startDelivery($signature, $body)];

            $bothWaiting = $this->waitUntilBackendsWaitOnALock(2);
        } finally {
            DB::rollBack();
        }

        $statuses = array_map(fn (InvokedProcess $child): mixed => $this->resultOf($child)['status'], $children);

        $this->assertTrue($bothWaiting, 'Both children did not wait on the event id in time.');
        $this->assertSame([200, 200], $statuses);
        $this->assertSame([$event['id']], array_column($this->storedWebhookEvents(), 'event_id'));
    }

    /**
     * The children read the real clock, so the delivery is signed at real now.
     */
    private function signedNow(string $body): string
    {
        $this->travelBack();

        return $this->pixelforgeSignatureFor($body, now()->getTimestamp());
    }

    private function startDelivery(string $signature, string $body): InvokedProcess
    {
        return Process::path(base_path())
            ->timeout(self::CHILD_TIMEOUT_SECONDS)
            ->env([...$this->childDatabaseEnvironment(), 'PIXELFORGE_WEBHOOK_SECRET' => self::WEBHOOK_SECRET])
            ->start([PHP_BINARY, 'tests/scripts/deliver-pixelforge-webhook.php', $signature, $body]);
    }
}
