<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Support\Health\ReconcilerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `GET /health/reconciler` (D-59): ok while the reconciler's last completed run is at
 * most 5 min old, stale otherwise, with nothing to cache and no session.
 */
final class ReconcilerHealthTest extends TestCase
{
    use RefreshDatabase;

    private const string NOW = '2026-11-20T15:10:00Z';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::NOW));
    }

    public function test_it_is_stale_before_the_first_run(): void
    {
        $this->health()
            ->assertStatus(503)
            ->assertExactJson(['status' => 'stale', 'last_succeeded_at' => null]);
    }

    public function test_it_is_ok_at_exactly_five_minutes(): void
    {
        $this->givenALastRunSecondsAgo(300);

        $this->health()
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'last_succeeded_at' => '2026-11-20T15:05:00Z']);
    }

    public function test_it_is_ok_right_after_a_run(): void
    {
        $this->givenALastRunSecondsAgo(0);

        $this->health()
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'last_succeeded_at' => self::NOW]);
    }

    public function test_it_is_stale_one_second_past_five_minutes(): void
    {
        $this->givenALastRunSecondsAgo(301);

        $this->health()
            ->assertStatus(503)
            ->assertExactJson(['status' => 'stale', 'last_succeeded_at' => '2026-11-20T15:04:59Z']);
    }

    public function test_it_is_never_cached_and_sets_no_cookie(): void
    {
        $this->givenALastRunSecondsAgo(10);

        $response = $this->health();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeaderMissing('Set-Cookie');
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_it_is_named(): void
    {
        $this->assertSame('/health/reconciler', route('health.reconciler', absolute: false));
    }

    private function givenALastRunSecondsAgo(int $seconds): void
    {
        $this->app->make(ReconcilerHeartbeat::class)->recordSuccessfulRun(CarbonImmutable::now()->subSeconds($seconds));
    }

    private function health(): TestResponse
    {
        return $this->getJson('/health/reconciler');
    }
}
