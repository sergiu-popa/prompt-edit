<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generation as History lists it (resources/js/types/generation.ts `Generation`).
 * The fields are listed one by one: no provider cost, margin, rates or token usage
 * ever reaches the page, and money is integer nanodollars. The actual charge is
 * sent for a succeeded generation only, so a failed one never shows its stored 0.
 * Every instant is UTC to the second. It reads no relation, so a page of items
 * costs one query.
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
     *     started_at: ?string,
     *     completed_at: ?string,
     *     user_charge_nanodollars: ?int,
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
            'created_at' => self::utcIso($this->resource->created_at),
            'started_at' => self::utcIso($this->resource->started_at),
            'completed_at' => self::utcIso($this->resource->completed_at),
            'user_charge_nanodollars' => $this->resource->status === GenerationStatus::Succeeded
                ? $this->resource->user_charge_nanodollars
                : null,
        ];
    }

    /**
     * @return ($instant is null ? null : string)
     */
    private static function utcIso(?CarbonImmutable $instant): ?string
    {
        return $instant?->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
