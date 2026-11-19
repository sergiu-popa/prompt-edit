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
