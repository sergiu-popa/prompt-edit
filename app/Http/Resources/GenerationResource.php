<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Generation\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generation as History lists it (resources/js/types/generation.ts `Generation`).
 * The fields are listed one by one: no provider cost, charge, margin, image URL or
 * token usage ever reaches the page, and money is integer nanodollars. It reads no
 * relation, so a page of items costs one query.
 *
 * @property-read Generation $resource
 */
final class GenerationResource extends JsonResource
{
    /**
     * @return array{
     *     id: string,
     *     prompt: string,
     *     size: string,
     *     quality: string,
     *     reference_image_count: int,
     *     status: string,
     *     status_label: string,
     *     user_estimate_nanodollars: array{min: int, max: int},
     *     created_at: string,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'prompt' => $this->resource->prompt,
            'size' => $this->resource->size->value,
            'quality' => $this->resource->quality->value,
            'reference_image_count' => count($this->resource->reference_images),
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'user_estimate_nanodollars' => [
                'min' => $this->resource->user_estimate_min_nanodollars,
                'max' => $this->resource->user_estimate_max_nanodollars,
            ],
            'created_at' => $this->resource->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
