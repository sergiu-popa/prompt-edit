<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Fake;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Support\Pixelforge\Exceptions\OverloadedException;
use App\Support\Pixelforge\Fake\FakePixelforgeGeneration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Tests\Concerns\InteractsWithFakePixelforge;
use Tests\TestCase;

/**
 * Random chaos is off unless configured, and when forced each documented
 * outcome can be produced. `[succeed]` rules out only a random failure.
 */
final class FakePixelforgeChaosTest extends TestCase
{
    use InteractsWithFakePixelforge;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-19T12:00:00Z'));
    }

    public function test_with_default_settings_nothing_happens_at_random(): void
    {
        for ($create = 0; $create < 200; $create++) {
            $this->createThroughFake(self::lighthousePayload());
        }

        $this->runFakeLifecycle();

        $this->assertSame(200, FakePixelforgeGeneration::query()->count());
        $this->assertTrue(FakePixelforgeGeneration::query()->get()->every(
            fn (FakePixelforgeGeneration $generation): bool => $generation->status() === GenerationStatus::Succeeded,
        ));

        $eventIds = array_map(fn (Request $request): string => $request->header('Pixelforge-Event-Id')[0], $this->fakeWebhookRequests());
        $this->assertCount(400, $eventIds);
        $this->assertCount(400, array_unique($eventIds));
    }

    public function test_forced_overload_answers_503_and_stores_nothing(): void
    {
        config(['pixelforge.fake.chaos.overloaded_percent' => 100]);

        try {
            $this->createThroughFake(self::lighthousePayload());
            $this->fail('Expected a chaos 503.');
        } catch (OverloadedException $exception) {
            $this->assertSame(503, $exception->status());
            $this->assertSame(2, $exception->retryAfterSeconds);
        }

        $this->assertSame(0, FakePixelforgeGeneration::query()->count());
        $this->assertSame([], $this->pushedFakeLifecycleJobs());
    }

    public function test_forced_internal_error_fails_unbilled_with_the_docs_message(): void
    {
        config(['pixelforge.fake.chaos.internal_error_percent' => 100]);
        $created = $this->createThroughFake(self::lighthousePayload());

        $this->runFakeLifecycle();

        $snapshot = $this->fakeGenerationRow($created->snapshot->id)->snapshot;
        $this->assertSame('failed', $snapshot['status']);
        $this->assertSame(['code' => 'internal_error', 'message' => 'An internal error occurred. You were not charged; please retry.'], $snapshot['error']);
        $this->assertNull($snapshot['usage']);
    }

    public function test_forced_duplicates_send_every_webhook_twice(): void
    {
        config(['pixelforge.fake.chaos.duplicate_percent' => 100]);
        $this->createThroughFake(self::lighthousePayload());

        $this->runFakeLifecycle();

        $eventIds = array_map(fn (Request $request): string => $request->header('Pixelforge-Event-Id')[0], $this->fakeWebhookRequests());
        $this->assertCount(4, $eventIds);
        $this->assertSame([$eventIds[0], $eventIds[0], $eventIds[2], $eventIds[2]], $eventIds);
    }

    public function test_succeed_never_fails_at_random(): void
    {
        config(['pixelforge.fake.chaos.internal_error_percent' => 100]);
        $created = $this->createThroughFake(self::lighthousePayload(prompt: 'A lighthouse [succeed]'));

        $this->runFakeLifecycle();

        $this->assertSame(GenerationStatus::Succeeded, $this->fakeGenerationRow($created->snapshot->id)->status());
    }

    public function test_fail_still_wins_over_succeed(): void
    {
        config(['pixelforge.fake.chaos.internal_error_percent' => 100]);
        $created = $this->createThroughFake(self::lighthousePayload(prompt: 'A lighthouse [fail][succeed]'));

        $this->runFakeLifecycle();

        $snapshot = $this->fakeGenerationRow($created->snapshot->id)->snapshot;
        $this->assertSame('failed', $snapshot['status']);
        $this->assertIsArray($snapshot['error']);
        $this->assertSame('generation_failed', $snapshot['error']['code']);
    }
}
