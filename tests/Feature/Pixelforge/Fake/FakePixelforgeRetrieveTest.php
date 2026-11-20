<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Fake;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use App\Support\Pixelforge\Drivers\FakePixelforgeClient;
use App\Support\Pixelforge\Exceptions\NotFoundException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithFakePixelforge;
use Tests\TestCase;

/**
 * A retrieve through the fake answers its own row as it is now, at every
 * step of the lifecycle, and an id it never created is not found.
 */
final class FakePixelforgeRetrieveTest extends TestCase
{
    use InteractsWithFakePixelforge;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-19T12:00:00Z'));
    }

    public function test_after_create_it_answers_the_queued_generation(): void
    {
        $payload = self::lighthousePayload();
        $created = $this->createThroughFake($payload);

        $retrieved = $this->retrieve($created->snapshot->id);

        $this->assertSame(GenerationStatus::Queued, $retrieved->status);
        $this->assertSame($payload->generationId, $retrieved->generationId);
        $this->assertEquals($created->snapshot, $retrieved);
    }

    public function test_after_each_lifecycle_step_it_answers_the_stored_state(): void
    {
        $created = $this->createThroughFake(self::lighthousePayload());

        $this->runNextFakeLifecycleJob();
        $this->assertSnapshotMatchesFakeRow($created->snapshot->id, GenerationStatus::Processing);

        $this->runNextFakeLifecycleJob();
        $retrieved = $this->assertSnapshotMatchesFakeRow($created->snapshot->id, GenerationStatus::Succeeded);
        $usage = $this->fakeGenerationRow($created->snapshot->id)->usage;
        $this->assertSame($usage['reasoning_tokens'], $retrieved->usage?->reasoningTokens);
        $this->assertSame($usage['input_text_tokens'], $retrieved->usage->inputTextTokens);
        $this->assertSame('12.50', $retrieved->rates['reasoning_tokens'] ?? null);
        $this->assertNull($retrieved->errorCode);
    }

    public function test_a_fail_generation_answers_its_error_and_no_usage(): void
    {
        $created = $this->createThroughFake(self::lighthousePayload(prompt: 'A lighthouse [fail]'));
        $this->runFakeLifecycle();

        $retrieved = $this->assertSnapshotMatchesFakeRow($created->snapshot->id, GenerationStatus::Failed);

        $this->assertSame('generation_failed', $retrieved->errorCode);
        $this->assertNull($retrieved->usage);
    }

    public function test_it_needs_no_webhook_secret_to_answer(): void
    {
        $created = $this->createThroughFake(self::lighthousePayload());
        config(['pixelforge.webhook_secret' => null]);

        $this->assertSame(GenerationStatus::Queued, $this->retrieve($created->snapshot->id)->status);
    }

    public function test_an_unknown_id_is_not_found(): void
    {
        $this->expectException(NotFoundException::class);

        $this->retrieve('gen_01KAF0UNKNOWN0000000000000');
    }

    private function retrieve(string $providerId): GenerationSnapshot
    {
        return $this->app->make(FakePixelforgeClient::class)->retrieveGeneration($providerId);
    }

    private function assertSnapshotMatchesFakeRow(string $providerId, GenerationStatus $status): GenerationSnapshot
    {
        $retrieved = $this->retrieve($providerId);

        $this->assertSame($status, $retrieved->status);
        $this->assertEquals(GenerationSnapshot::from($this->fakeGenerationRow($providerId)->snapshot), $retrieved);

        return $retrieved;
    }
}
