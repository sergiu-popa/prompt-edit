<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\ReconcileGenerationsCommand;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Support\Health\ReconcilerHeartbeat;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;

/**
 * `app:reconcile-generations` runs the reconciler and records each completed run
 * (D-59), and the schedule runs it every minute on one server without overlapping (D-54).
 */
final class ReconcileGenerationsCommandTest extends TestCase
{
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;

    public function test_it_polls_a_stale_generation_and_succeeds(): void
    {
        $generation = Generation::factory()->processing()->create(['last_provider_update_at' => CarbonImmutable::now()->subMinutes(5)]);
        Http::fake(['*' => self::pixelforgeRetrieved(self::generationObjectFor($generation, GenerationStatus::Succeeded))]);

        $this->reconcile()->assertSuccessful();

        $this->assertSame(GenerationStatus::Succeeded, $this->reloadedGeneration($generation)->status);
    }

    /**
     * AC 4: a row that throws is logged inside the run, and the run still succeeds.
     */
    public function test_it_succeeds_when_one_generation_throws(): void
    {
        $failing = Generation::factory()->processing()->create(['last_provider_update_at' => CarbonImmutable::now()->subMinutes(9)]);
        $settling = Generation::factory()->processing()->create(['last_provider_update_at' => CarbonImmutable::now()->subMinutes(5)]);
        Http::fake(function (Request $request) use ($failing, $settling): PromiseInterface {
            if (str_ends_with($request->url(), (string) $failing->provider_id)) {
                return self::pixelforgeRetrieved(self::generationObjectFor($failing, GenerationStatus::Succeeded, ['rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.5005',
                ]]));
            }

            return self::pixelforgeRetrieved(self::generationObjectFor($settling, GenerationStatus::Succeeded));
        });

        $this->reconcile()->assertSuccessful();

        $this->assertSame(GenerationStatus::Processing, $this->reloadedGeneration($failing)->status);
        $this->assertSame(GenerationStatus::Succeeded, $this->reloadedGeneration($settling)->status);
    }

    public function test_a_completed_run_records_now_in_whole_seconds(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20T15:04:14.750Z'));

        $this->reconcile()->assertSuccessful();

        $this->assertEquals(CarbonImmutable::parse('2026-11-20T15:04:14Z'), $this->heartbeat()->lastSuccessfulRunAt());
    }

    public function test_a_run_where_one_generation_throws_still_counts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20T15:04:14Z'));
        $generation = Generation::factory()->processing()->create(['last_provider_update_at' => CarbonImmutable::now()->subMinutes(5)]);
        Http::fake(['*' => self::pixelforgeRetrieved(self::generationObjectFor($generation, GenerationStatus::Succeeded, ['rates' => ['input_text_tokens' => '5.0005']]))]);

        $this->reconcile()->assertSuccessful();

        $this->assertSame(GenerationStatus::Processing, $this->reloadedGeneration($generation)->status);
        $this->assertEquals(CarbonImmutable::now(), $this->heartbeat()->lastSuccessfulRunAt());
    }

    /**
     * The action itself throwing, here on its first query, records nothing, and
     * the last completed run's instant stays.
     */
    public function test_a_run_whose_action_throws_records_nothing(): void
    {
        $earlier = CarbonImmutable::parse('2026-11-20T15:00:00Z');
        $this->heartbeat()->recordSuccessfulRun($earlier);
        $this->travelTo($earlier->addMinutes(4));

        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_contains($query, '"generations"')) {
                throw new RuntimeException('The generations table is unavailable.');
            }
        });

        try {
            $this->reconcile()->run();
            $this->fail('The run did not throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The generations table is unavailable.', $exception->getMessage());
        }

        $this->assertEquals($earlier, $this->heartbeat()->lastSuccessfulRunAt());
    }

    /**
     * AC 4: the suite runs with production's frequency, so this is the production entry.
     */
    public function test_the_schedule_runs_it_every_minute_on_one_server_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->sole(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'app:reconcile-generations'));

        $this->assertSame('* * * * *', $event->expression);
        $this->assertFalse($event->isRepeatable());
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertSame(15, $event->expiresAt, 'The lock expires in minutes, past the worst run.');
    }

    public function test_a_sub_minute_frequency_repeats_inside_the_minute(): void
    {
        $event = ReconcileGenerationsCommand::scheduleEvery($this->freshEvent(), 5);

        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->isRepeatable());
        $this->assertSame(5, $event->repeatSeconds);
    }

    public function test_a_minute_or_anything_else_runs_every_minute(): void
    {
        foreach ([60, 7, 0] as $seconds) {
            $event = ReconcileGenerationsCommand::scheduleEvery($this->freshEvent(), $seconds);

            $this->assertSame('* * * * *', $event->expression);
            $this->assertFalse($event->isRepeatable(), sprintf('%d s repeats.', $seconds));
        }
    }

    private function heartbeat(): ReconcilerHeartbeat
    {
        return $this->app->make(ReconcilerHeartbeat::class);
    }

    private function reconcile(): PendingCommand
    {
        $command = $this->artisan('app:reconcile-generations');
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    /**
     * Outside the app's schedule, so the sole-event assertion above stays true.
     */
    private function freshEvent(): ScheduledEvent
    {
        return new ScheduledEvent($this->app->make(CacheEventMutex::class), 'php artisan app:reconcile-generations');
    }
}
