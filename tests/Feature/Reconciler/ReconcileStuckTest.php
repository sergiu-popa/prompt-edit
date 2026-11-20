<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciler;

use App\Domain\Generation\Models\Generation;
use App\Jobs\SubmitGenerationJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\Concerns\RunsTheReconciler;
use Tests\TestCase;

/**
 * AC 19: each generation that takes longer than usual is logged once, whatever the
 * reconciler does with it otherwise (D-56).
 */
final class ReconcileStuckTest extends TestCase
{
    use CapturesLogMessages;
    use InteractsWithGenerations;
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;
    use RunsTheReconciler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-20T12:00:00Z'));
        Queue::fake([SubmitGenerationJob::class]);
        Http::fake();
    }

    public function test_a_stuck_processing_row_and_a_stuck_pending_row_are_each_warned_about_once(): void
    {
        $processing = Generation::factory()->processing()->create([
            'created_at' => CarbonImmutable::now()->subMinutes(16),
            'last_provider_update_at' => CarbonImmutable::now(),
        ]);
        $pending = Generation::factory()->create(['created_at' => CarbonImmutable::now()->subMinutes(16)]);

        for ($run = 0; $run < 3; $run++) {
            $this->runReconciler();
            $this->travel(1)->minutes();
        }

        $warnings = $this->stuckWarnings();
        $this->assertCount(2, $warnings);
        $this->assertSame(
            collect([$processing->id, $pending->id])->sort()->values()->all(),
            collect($warnings)->map(fn (MessageLogged $message): mixed => $message->context['generation_id'])->sort()->values()->all(),
        );
        $this->assertSame('warning', $warnings[0]->level);
        $this->assertSameInstant('2026-11-20T12:00:00Z', $this->reloadedGeneration($processing)->reconcile_stuck_warned_at);
    }

    public function test_young_and_terminal_rows_are_never_warned_about(): void
    {
        Generation::factory()->processing()->create([
            'created_at' => CarbonImmutable::now()->subMinutes(14),
            'last_provider_update_at' => CarbonImmutable::now(),
        ]);
        Generation::factory()->succeeded()->create(['created_at' => CarbonImmutable::now()->subDay()]);
        Generation::factory()->failed()->create(['created_at' => CarbonImmutable::now()->subDay()]);

        $this->runReconciler();

        $this->assertSame([], $this->stuckWarnings());
    }

    /**
     * @return list<MessageLogged>
     */
    private function stuckWarnings(): array
    {
        return array_values(array_filter(
            $this->loggedMessages(),
            fn (MessageLogged $message): bool => str_starts_with($message->message, 'A generation is taking longer than usual'),
        ));
    }
}
