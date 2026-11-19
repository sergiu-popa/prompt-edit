<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Pixelforge\DataTransferObjects;

use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Support\Pixelforge\DataTransferObjects\CreateGenerationPayloadData;
use PHPUnit\Framework\TestCase;

/**
 * The create body holds only documented fields, because Pixelforge answers an
 * unknown param with 422, and equal fields always encode to equal bytes, so a
 * retry with the same key is replayed instead of answered with 409.
 */
final class CreateGenerationPayloadDataTest extends TestCase
{
    private const string GENERATION_ID = '01JC2Z8Q4N6V0M3H7X5T9R2B1K';

    private const string LIGHTHOUSE_PROMPT = 'A lighthouse on a cliff at dawn, oil painting';

    /**
     * 45 code points plus a space and four emoji: 50 code points.
     */
    private const string UNICODE_PROMPT = 'A lighthouse on a cliff at dawn, oil painting 🎨🎨🎨🎨';

    public function test_the_lighthouse_encodes_to_exactly_the_documented_body(): void
    {
        $this->assertSame([
            'prompt' => self::LIGHTHOUSE_PROMPT,
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'metadata' => ['generation_id' => self::GENERATION_ID],
        ], self::decoded(self::lighthouse()));
    }

    public function test_no_reference_images_leaves_the_key_out(): void
    {
        $body = self::decoded(new CreateGenerationPayloadData(
            self::GENERATION_ID,
            self::LIGHTHOUSE_PROMPT,
            ImageSize::Square,
            ImageQuality::Low,
            [],
        ));

        $this->assertSame(['prompt', 'size', 'quality', 'metadata'], array_keys($body));
        $this->assertSame('1024x1024', $body['size']);
        $this->assertSame('low', $body['quality']);
        $this->assertSame(['generation_id' => self::GENERATION_ID], $body['metadata']);
    }

    public function test_a_unicode_prompt_is_sent_unchanged(): void
    {
        $body = self::decoded(new CreateGenerationPayloadData(
            self::GENERATION_ID,
            self::UNICODE_PROMPT,
            ImageSize::Landscape,
            ImageQuality::High,
            [],
        ));

        $this->assertSame(50, mb_strlen(self::UNICODE_PROMPT));
        $this->assertSame(self::UNICODE_PROMPT, $body['prompt']);
    }

    public function test_four_reference_images_keep_their_order(): void
    {
        $references = [
            'https://example.com/d.png',
            'https://example.com/a.png',
            'http://example.com/c.png',
            'https://example.com/b.png?w=1&h=2',
        ];

        $body = self::decoded(new CreateGenerationPayloadData(
            self::GENERATION_ID,
            self::LIGHTHOUSE_PROMPT,
            ImageSize::Portrait,
            ImageQuality::Medium,
            $references,
        ));

        $this->assertSame($references, $body['reference_images']);
    }

    public function test_equal_fields_encode_byte_identically(): void
    {
        $first = new CreateGenerationPayloadData(self::GENERATION_ID, self::UNICODE_PROMPT, ImageSize::Landscape, ImageQuality::High, ['https://example.com/style.png']);
        $second = new CreateGenerationPayloadData(self::GENERATION_ID, self::UNICODE_PROMPT, ImageSize::Landscape, ImageQuality::High, ['https://example.com/style.png']);

        $this->assertSame($first->toJson(), $second->toJson());
    }

    public function test_the_idempotency_key_is_the_generation_ulid(): void
    {
        $this->assertSame(self::GENERATION_ID, self::lighthouse()->idempotencyKey());
    }

    private static function lighthouse(): CreateGenerationPayloadData
    {
        return new CreateGenerationPayloadData(
            self::GENERATION_ID,
            self::LIGHTHOUSE_PROMPT,
            ImageSize::Landscape,
            ImageQuality::High,
            ['https://example.com/style.png'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decoded(CreateGenerationPayloadData $payload): array
    {
        $body = json_decode($payload->toJson(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }
}
