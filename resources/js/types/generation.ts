import type { Paginated } from '@/types/pagination';
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

/** The Generate form's state, named as it is submitted. */
export interface GenerationFormInput {
    prompt: string;
    size: ImageSize;
    quality: ImageQuality;
    /** One entry per URL row, blank rows included; only non-blank rows are counted or sent. */
    reference_images: string[];
}

/**
 * What the Generate page posts to `/generations` (CreateGenerationRequest): the form with
 * only its counted references, trimmed, and the token naming this form submission (D-38).
 */
export interface GenerationSubmission extends Omit<GenerationFormInput, 'reference_images'> {
    /** Exactly the rows the estimate counted. */
    reference_images: string[];
    /** A UUID made once per visit to Generate, so a double click creates one generation. */
    submission_token: string;
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
    /** When Pixelforge started processing it, in UTC; null until then. */
    started_at: string | null;
    /** When it succeeded or failed, in UTC; null until then. */
    completed_at: string | null;
    /** What the user was actually charged; null unless it succeeded, so a failure reads as free. */
    user_charge_nanodollars: number | null;
}

/**
 * History's `generations` prop: a page of generations and the server's time when it was
 * read, ISO-8601 in UTC to the millisecond, such as `2026-11-19T23:59:58.000Z` (D-42).
 */
export type GenerationHistory = Paginated<Generation> & { server_time: string };
