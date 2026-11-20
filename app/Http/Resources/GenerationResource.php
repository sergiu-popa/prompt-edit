<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Generation\Enums\GenerationFailureCode;
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
 * So is the image: its URL is withheld once the server's clock reaches its expiry,
 * and whenever it is neither `https://` nor root-relative, so a page never requests
 * a dead URL and a provider URL never becomes a `javascript:` link (D-42). A
 * failed generation sends one composed message, from GenerationFailureCode, and
 * neither its raw code nor Pixelforge's text beside it (D-43). A generation still
 * `pending` sends why it is waiting, when the submit job is retrying it (D-45); any
 * other status leaves a stale note behind.
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
     *     image_url: ?string,
     *     image_url_expires_at: ?string,
     *     image_expired: bool,
     *     failure_message: ?string,
     *     status_note: ?string,
     * }
     */
    public function toArray(Request $request): array
    {
        $succeeded = $this->resource->status === GenerationStatus::Succeeded;
        $expiresAt = $succeeded ? $this->resource->image_url_expires_at : null;
        $imageExpired = $expiresAt !== null && CarbonImmutable::now()->greaterThanOrEqualTo($expiresAt);

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
            'user_charge_nanodollars' => $succeeded ? $this->resource->user_charge_nanodollars : null,
            'image_url' => $succeeded && ! $imageExpired ? self::loadableUrl($this->resource->image_url) : null,
            'image_url_expires_at' => self::utcIso($expiresAt),
            'image_expired' => $imageExpired,
            'failure_message' => $this->resource->status === GenerationStatus::Failed
                ? GenerationFailureCode::messageFor($this->resource)
                : null,
            'status_note' => $this->resource->status === GenerationStatus::Pending
                ? $this->resource->submit_retry_note?->message()
                : null,
        ];
    }

    /**
     * An `https://` URL, or a root-relative one (`/…`, the fake's form, but not
     * the protocol-relative `//…`, nor `/\…`, which browsers read the same way);
     * anything else is withheld.
     */
    private static function loadableUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $isHttps = str_starts_with($url, 'https://');
        $isRootRelative = str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_starts_with($url, '/\\');

        return $isHttps || $isRootRelative ? $url : null;
    }

    /**
     * @return ($instant is null ? null : string)
     */
    private static function utcIso(?CarbonImmutable $instant): ?string
    {
        return $instant?->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
