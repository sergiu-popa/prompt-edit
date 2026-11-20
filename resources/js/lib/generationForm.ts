import { countCodePoints } from '@/lib/counting';
import { countedReferenceImages, isHttpImageUrl } from '@/lib/referenceImages';
import type { GenerationFormInput, GenerationInputLimits } from '@/types/generation';

/** An unpaired UTF-16 surrogate, which PHP cannot decode and so would count differently. */
const LONE_SURROGATE = /\p{Cs}/u;

/**
 * Whether Generate may submit the form: the same checks the server makes, so a request
 * it sends is one the server accepts in the normal case. The server stays the authority.
 *
 * - The prompt is not blank by JS `trim()`'s set (ASCII whitespace, the Unicode space
 *   separators and U+FEFF, as CreateGenerationRequest's rule), fits the limit in code
 *   points and holds no lone surrogate (`isWellFormed` is ES2024; the lib is ES2022).
 * - The counted (non-blank) references fit the limit and are each an http(s) URL.
 * - An estimate is available, because the user must see a price first.
 */
export function canSubmitGeneration(input: GenerationFormInput, limits: GenerationInputLimits, estimateAvailable: boolean): boolean {
    const references = countedReferenceImages(input.reference_images);

    return (
        estimateAvailable &&
        input.prompt.trim() !== '' &&
        countCodePoints(input.prompt) <= limits.max_prompt_characters &&
        !LONE_SURROGATE.test(input.prompt) &&
        references.length <= limits.max_reference_images &&
        references.every((reference) => isHttpImageUrl(reference))
    );
}
