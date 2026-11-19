<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use RuntimeException;

/**
 * Pixelforge generation objects, from the docs' examples or shaped for a row.
 */
trait InteractsWithGenerationSnapshots
{
    /**
     * A JSON example copied verbatim from the Pixelforge docs, in tests/fixtures/pixelforge.
     *
     * @return array<string, mixed>
     */
    protected static function pixelforgeFixture(string $name): array
    {
        $fixture = json_decode(
            (string) file_get_contents(sprintf('%s/fixtures/pixelforge/%s.json', dirname(__DIR__), $name)),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($fixture)) {
            throw new RuntimeException(sprintf('tests/fixtures/pixelforge/%s.json must decode to an object.', $name));
        }

        /** @var array<string, mixed> $fixture */
        return $fixture;
    }

    /**
     * A docs-shaped generation object for the row: it echoes the request, carries
     * the row's ULID as `metadata.generation_id` and fills what the status needs.
     * Successive objects for one row share an id: the stored provider id, or
     * `gen_` and the upper-cased ULID while the row has none.
     *
     * @param  array<string, mixed>  $overrides  applied with array_replace
     * @return array<string, mixed>
     */
    protected static function generationObjectFor(Generation $generation, GenerationStatus $status, array $overrides = []): array
    {
        $id = $generation->provider_id ?? 'gen_' . strtoupper($generation->id);
        $isTerminal = $status->isTerminal();
        $hasStarted = in_array($status, [GenerationStatus::Processing, GenerationStatus::Succeeded, GenerationStatus::Failed], true);
        $succeeded = $status === GenerationStatus::Succeeded;

        return array_replace([
            'id' => $id,
            'object' => 'generation',
            'status' => $status->value,
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images,
            'metadata' => ['generation_id' => $generation->id],
            'image_url' => $succeeded ? sprintf('https://pixelforge.promptedit.dev/images/%s.png?expires=1763596799&signature=test', $id) : null,
            'image_url_expires_at' => $succeeded ? '2026-11-19T23:59:59Z' : null,
            'usage' => $succeeded ? [
                'input_text_tokens' => 10,
                'input_image_tokens' => 765,
                'output_tokens' => 1_650,
                'reasoning_tokens' => 1_100,
                'total_tokens' => 3_525,
            ] : null,
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'error' => $status === GenerationStatus::Failed
                ? ['code' => 'generation_failed', 'message' => 'The model could not produce an image for this prompt.']
                : null,
            'created_at' => '2026-11-18T23:59:40Z',
            'started_at' => $hasStarted ? '2026-11-18T23:59:42Z' : null,
            'completed_at' => $isTerminal ? '2026-11-18T23:59:59Z' : null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected static function snapshotFor(Generation $generation, GenerationStatus $status, array $overrides = []): GenerationSnapshot
    {
        return GenerationSnapshot::from(self::generationObjectFor($generation, $status, $overrides));
    }

    /**
     * The docs' succeeded Lighthouse object, shaped for the row: usage
     * 12 / 765 / 6,450 / 3,184 at the published rates.
     */
    protected static function lighthouseSucceededSnapshotFor(Generation $generation): GenerationSnapshot
    {
        return self::snapshotFor($generation, GenerationStatus::Succeeded, [
            'usage' => [
                'input_text_tokens' => 12,
                'input_image_tokens' => 765,
                'output_tokens' => 6_450,
                'reasoning_tokens' => 3_184,
                'total_tokens' => 10_411,
            ],
        ]);
    }
}
