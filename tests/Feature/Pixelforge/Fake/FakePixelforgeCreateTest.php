<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge\Fake;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use App\Support\Pixelforge\Exceptions\IdempotencyKeyReusedException;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use App\Support\Pixelforge\Fake\FakePixelforgeGeneration;
use App\Support\Pixelforge\Fake\FakeUsageGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Concerns\InteractsWithFakePixelforge;
use Tests\TestCase;

/**
 * A create through the fake stores a generation shaped like the docs' object
 * and answers with it, queued. A repeated key replays it, and a reused key
 * with other params is refused.
 */
final class FakePixelforgeCreateTest extends TestCase
{
    use InteractsWithFakePixelforge;
    use RefreshDatabase;

    private const string FROZEN_NOW = '2026-11-19T12:00:00Z';

    private const string REUSED_KEY = '01kaf0reused00000000000000';

    private const array DOCS_FIELD_ORDER = [
        'id',
        'object',
        'status',
        'prompt',
        'size',
        'quality',
        'reference_images',
        'metadata',
        'image_url',
        'image_url_expires_at',
        'usage',
        'rates',
        'error',
        'created_at',
        'started_at',
        'completed_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));
    }

    public function test_a_create_stores_the_docs_generation_object_queued(): void
    {
        $payload = self::lighthousePayload();

        $created = $this->createThroughFake($payload);

        $snapshot = $this->storedSnapshotJson($created->snapshot->id);
        $this->assertSame(self::DOCS_FIELD_ORDER, array_keys(get_object_vars($snapshot)));
        $this->assertMatchesRegularExpression('/^gen_[0-9A-Z]{26}\z/', $snapshot->id);
        $this->assertSame('generation', $snapshot->object);
        $this->assertSame('queued', $snapshot->status);
        $this->assertSame(self::LIGHTHOUSE_PROMPT, $snapshot->prompt);
        $this->assertSame('1536x1024', $snapshot->size);
        $this->assertSame('high', $snapshot->quality);
        $this->assertSame(['https://example.com/style.png'], $snapshot->reference_images);
        $this->assertInstanceOf(stdClass::class, $snapshot->metadata);
        $this->assertSame(['generation_id' => $payload->generationId], get_object_vars($snapshot->metadata));
        $this->assertSame(
            ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            get_object_vars($snapshot->rates),
        );

        foreach (['image_url', 'image_url_expires_at', 'usage', 'error', 'started_at', 'completed_at'] as $field) {
            $this->assertNull($snapshot->{$field}, $field);
        }

        $this->assertSame(self::FROZEN_NOW, $snapshot->created_at);
    }

    public function test_the_answer_is_the_queued_generation_never_replayed(): void
    {
        $payload = self::lighthousePayload();

        $created = $this->createThroughFake($payload);

        $this->assertSame($this->storedSnapshotJson($created->snapshot->id)->id, $created->snapshot->id);
        $this->assertSame(GenerationStatus::Queued, $created->snapshot->status);
        $this->assertSame($payload->generationId, $created->snapshot->generationId);
        $this->assertSameInstant(self::FROZEN_NOW, $created->snapshot->createdAt);
        $this->assertFalse($created->replayed);
    }

    public function test_no_references_are_echoed_as_an_empty_list(): void
    {
        $payload = new CreateGenerationPayloadData(strtolower((string) Str::ulid()), 'a cat', ImageSize::Square, ImageQuality::Low, []);

        $created = $this->createThroughFake($payload);

        $this->assertSame([], $this->storedSnapshotJson($created->snapshot->id)->reference_images);
    }

    public function test_a_unicode_prompt_is_echoed_unchanged(): void
    {
        $prompt = 'Un phare sur une falaise à l’aube 🎨 灯台';

        $created = $this->createThroughFake(self::lighthousePayload(prompt: $prompt));

        $this->assertSame($prompt, $this->storedSnapshotJson($created->snapshot->id)->prompt);
    }

    public function test_the_planned_usage_is_the_generators(): void
    {
        $payload = self::lighthousePayload();

        $created = $this->createThroughFake($payload);

        $this->assertSame(
            $this->app->make(FakeUsageGenerator::class)->generate($payload),
            $this->fakeGenerationRow($created->snapshot->id)->usage,
        );
    }

    public function test_the_same_key_and_body_replays_the_stored_generation(): void
    {
        $payload = self::lighthousePayload();
        $first = $this->createThroughFake($payload);
        $stored = $this->rawFakeRow($first->snapshot->id);

        $replay = $this->createThroughFake($payload);

        $this->assertSame($first->snapshot->id, $replay->snapshot->id);
        $this->assertTrue($replay->replayed);
        $this->assertEquals($first->snapshot, $replay->snapshot);
        $this->assertSame(1, FakePixelforgeGeneration::query()->count());
        $this->assertSame($stored, $this->rawFakeRow($first->snapshot->id));
    }

    #[DataProvider('changedParams')]
    public function test_the_same_key_with_other_params_is_refused(CreateGenerationPayloadData $changed): void
    {
        $original = self::lighthousePayload(self::REUSED_KEY);
        $first = $this->createThroughFake($original);
        $stored = $this->rawFakeRow($first->snapshot->id);

        try {
            $this->createThroughFake($changed);
            $this->fail('Expected the fake to refuse a reused key.');
        } catch (IdempotencyKeyReusedException $exception) {
            $this->assertSame(PixelforgeErrorCode::IdempotencyKeyReused, $exception->errorCode());
            $this->assertSame(409, $exception->status());
            $this->assertSame('idempotency_error', $exception->type);
        }

        $this->assertSame(1, FakePixelforgeGeneration::query()->count());
        $this->assertSame($stored, $this->rawFakeRow($first->snapshot->id));
    }

    /**
     * @return array<string, array{CreateGenerationPayloadData}>
     */
    public static function changedParams(): array
    {
        $lighthouse = self::lighthousePayload(self::REUSED_KEY);

        return [
            'prompt' => [self::lighthousePayload(self::REUSED_KEY, 'A lighthouse at night')],
            'size' => [new CreateGenerationPayloadData(self::REUSED_KEY, $lighthouse->prompt, ImageSize::Square, $lighthouse->quality, $lighthouse->referenceImages)],
            'quality' => [new CreateGenerationPayloadData(self::REUSED_KEY, $lighthouse->prompt, $lighthouse->size, ImageQuality::Low, $lighthouse->referenceImages)],
            'references' => [new CreateGenerationPayloadData(self::REUSED_KEY, $lighthouse->prompt, $lighthouse->size, $lighthouse->quality, [])],
        ];
    }

    #[DataProvider('missingSecrets')]
    public function test_without_a_webhook_secret_nothing_is_created(?string $secret): void
    {
        config(['pixelforge.webhook_secret' => $secret]);
        Http::fake();

        try {
            $this->createThroughFake(self::lighthousePayload());
            $this->fail('Expected the fake to refuse a create without a webhook secret.');
        } catch (PixelforgeNotConfiguredException $exception) {
            $this->assertStringContainsString('PIXELFORGE_WEBHOOK_SECRET', $exception->getMessage());
        }

        $this->assertSame(0, FakePixelforgeGeneration::query()->count());
        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function missingSecrets(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rawFakeRow(string $providerId): array
    {
        return (array) DB::table('fake_pixelforge_generations')->where('id', $providerId)->first();
    }

    /**
     * The snapshot column as the database stores it, decoded to objects so
     * the key order and `{}` versus `[]` are both visible.
     */
    private function storedSnapshotJson(string $providerId): stdClass
    {
        $json = DB::table('fake_pixelforge_generations')->where('id', $providerId)->value('snapshot');
        $this->assertIsString($json);

        $snapshot = json_decode($json, flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(stdClass::class, $snapshot);

        return $snapshot;
    }
}
