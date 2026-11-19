<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\DataTransferObjects;

use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;

/**
 * The body of `POST /v1/generations`, limited to documented fields: Pixelforge
 * answers an unknown param with 422. The generation's ULID is both the
 * `Idempotency-Key` and `metadata.generation_id` (D-05), and the encoding is
 * fixed, so equal fields always give equal bytes and a retry is replayed
 * instead of answered with 409. Nothing is validated here (PE-011 validates).
 */
final readonly class CreateGenerationPayloadData
{
    /**
     * @param  list<string>  $referenceImages
     */
    public function __construct(
        public string $generationId,
        public string $prompt,
        public ImageSize $size,
        public ImageQuality $quality,
        public array $referenceImages,
    ) {}

    public function idempotencyKey(): string
    {
        return $this->generationId;
    }

    /**
     * Size and quality are always sent; `reference_images` only when there are some.
     */
    public function toJson(): string
    {
        $body = [
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
        ];

        if ($this->referenceImages !== []) {
            $body['reference_images'] = $this->referenceImages;
        }

        $body['metadata'] = ['generation_id' => $this->generationId];

        return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
