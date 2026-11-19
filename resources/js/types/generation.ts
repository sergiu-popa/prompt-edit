/**
 * Pixelforge's create-generation input limits: the Generate page's `limits` prop
 * (App\Domain\Generation\ValueObjects\GenerationInputLimits::toArray()).
 */
export interface GenerationInputLimits {
    /** Counted in code points, like PHP's mb_strlen. */
    max_prompt_characters: number;
    max_reference_images: number;
}
