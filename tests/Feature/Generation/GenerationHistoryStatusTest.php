<?php

declare(strict_types=1);

namespace Tests\Feature\Generation;

use App\Domain\Generation\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\Concerns\ReadsGenerationHistory;
use Tests\TestCase;

/**
 * What History's resource says about each status: the times, the actual charge,
 * and nothing of the provider's cost.
 */
final class GenerationHistoryStatusTest extends TestCase
{
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use ReadsGenerationHistory;
    use RefreshDatabase;

    /**
     * The docs' Lighthouse at the published rates: what Pixelforge bills, never sent.
     */
    private const string LIGHTHOUSE_PROVIDER_COST = '305510000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-19T23:59:58Z'));
    }

    public function test_a_processing_generation_has_started_but_not_completed_or_been_charged(): void
    {
        $user = User::factory()->create();
        $processing = Generation::factory()->processing()->create(['user_id' => $user->id]);

        $item = $this->onlyHistoryItemOf($user);

        $this->assertSame($processing->started_at?->utc()->format('Y-m-d\TH:i:s\Z'), $item['started_at']);
        $this->assertNull($item['completed_at']);
        $this->assertNull($item['user_charge_nanodollars']);
    }

    public function test_a_pending_generation_has_no_times_or_charge(): void
    {
        $user = User::factory()->create();
        $this->createGenerationFor($user, self::lighthouseRequest());

        $item = $this->onlyHistoryItemOf($user);

        $this->assertNull($item['started_at']);
        $this->assertNull($item['completed_at']);
        $this->assertNull($item['user_charge_nanodollars']);
    }

    public function test_the_succeeded_lighthouse_shows_its_actual_charge_and_times_and_never_the_provider_cost(): void
    {
        $user = User::factory()->create();
        $this->givenTheSucceededLighthouseFor($user);

        $history = $this->historyFor($user);
        $item = $history['data'][0];

        $this->assertSame(381_887_500, $item['user_charge_nanodollars']);
        $this->assertSame(['min' => 347_750_000, 'max' => 457_150_000], $item['user_estimate_nanodollars']);
        $this->assertSame('2026-11-18T23:59:42Z', $item['started_at']);
        $this->assertSame('2026-11-18T23:59:59Z', $item['completed_at']);
        $this->assertSame([], array_intersect(array_keys($item), [
            'provider_cost_nanodollars',
            'rates',
            'input_text_tokens',
            'input_image_tokens',
            'output_tokens',
            'reasoning_tokens',
            'margin',
        ]));
        $this->assertStringNotContainsString(self::LIGHTHOUSE_PROVIDER_COST, (string) json_encode($history));
    }

    public function test_a_failed_generation_is_sent_without_a_charge_though_it_stores_zero(): void
    {
        $user = User::factory()->create();
        $failed = Generation::factory()->failed()->create(['user_id' => $user->id]);

        $item = $this->onlyHistoryItemOf($user);

        $this->assertSame(0, $failed->user_charge_nanodollars);
        $this->assertNull($item['user_charge_nanodollars']);
        $this->assertSame($failed->completed_at?->utc()->format('Y-m-d\TH:i:s\Z'), $item['completed_at']);
    }

    public function test_history_carries_the_servers_time_to_the_millisecond(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19T23:59:58.250Z'));

        $this->assertSame('2026-11-19T23:59:58.250Z', $this->historyFor(User::factory()->create())['server_time']);
    }

    private function givenTheSucceededLighthouseFor(User $user): Generation
    {
        $lighthouse = $this->createGenerationFor($user, self::lighthouseRequest());
        $this->transitionGeneration($lighthouse, self::lighthouseSucceededSnapshotFor($lighthouse));

        return $this->reloadedGeneration($lighthouse);
    }
}
