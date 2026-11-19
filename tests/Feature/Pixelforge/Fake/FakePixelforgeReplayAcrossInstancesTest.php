<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Fake;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithFakePixelforge;
use Tests\TestCase;

/**
 * The fake's state is in the database, so the web, worker and E2E processes
 * share it: a replay handled by a fresh app instance sees the generation the
 * first one created. The rows are committed, so this test truncates rather
 * than rolls back.
 */
final class FakePixelforgeReplayAcrossInstancesTest extends TestCase
{
    use DatabaseTruncation;
    use InteractsWithFakePixelforge;

    protected function setUp(): void
    {
        parent::setUp();

        // Committed rows must not leak into the RefreshDatabase tests that run after this one.
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('truncate table fake_pixelforge_generations');
        });
    }

    public function test_a_fresh_app_instance_replays_the_generation_another_one_created(): void
    {
        $payload = self::lighthousePayload();
        $first = $this->createThroughFake($payload);

        $this->refreshApplication();
        $this->setUpInteractsWithFakePixelforge();
        Http::fake();

        $replay = $this->createThroughFake($payload);

        $this->assertSame($first->snapshot->id, $replay->snapshot->id);
        $this->assertTrue($replay->replayed);
        Http::assertNothingSent();
    }
}
