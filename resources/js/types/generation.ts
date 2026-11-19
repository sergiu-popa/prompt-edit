import type { ImageQuality, ImageSize } from '@/types/pricing';

/**
 * Pixelforge's create-generation input limits: the Generate page's `limits` prop
 * (App\Domain\Generation\ValueObjects\GenerationInputLimits::toArray()).
 */
export interface GenerationInputLimits {
    /** Counted in code points, like PHP's mb_strlen. */
    max_prompt_characters: number;
    max_reference_images: number;
}

/** The Generate form's state, named as PE-011 will submit it. */
export interface GenerationFormInput {
    prompt: string;
    size: ImageSize;
    quality: ImageQuality;
    /** One entry per URL row, blank rows included; only non-blank rows are counted or sent. */
    reference_images: string[];
}

/** A generation's lifecycle (App\Domain\Generation\Enums\GenerationStatus); `pending` reads as Queued. */
export type GenerationStatus = 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';

/**
 * A generation as History lists it (App\Http\Resources\GenerationResource). Money is
 * integer nanodollars, formatted only by lib/money.ts.
 */
export interface Generation {
    /** The ULID, also Pixelforge's idempotency key. */
    id: string;
    prompt: string;
    size: ImageSize;
    quality: ImageQuality;
    reference_image_count: number;
    status: GenerationStatus;
    /** The server's label for the status, such as "Queued" for both `pending` and `queued`. */
    status_label: string;
    /** The estimate the user saw before generating; the max is the hold. */
    user_estimate_nanodollars: { min: number; max: number };
    /** ISO-8601 in UTC, such as `2026-11-19T10:15:30Z`. */
    created_at: string;
}
