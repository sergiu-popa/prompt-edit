import type { ComputedRef, MaybeRefOrGetter } from 'vue';
import { computed, toValue } from 'vue';

import { estimateGenerationCost } from '@/lib/estimate';
import { countedReferenceImages } from '@/lib/referenceImages';
import type { GenerationCostEstimate } from '@/types/estimate';
import type { GenerationFormInput } from '@/types/generation';
import type { PricingTable } from '@/types/pricing';

export interface LiveEstimate {
    /** Null when the estimate cannot be computed, e.g. for a malformed pricing table. */
    estimate: ComputedRef<GenerationCostEstimate | null>;
    /** The non-blank reference rows, the ones that are billed. */
    referenceImageCount: ComputedRef<number>;
    /** What one more reference adds to the user's max (every exact figure rises by it), or null. */
    perReferenceNanodollars: ComputedRef<number | null>;
}

/**
 * The Generate page's live estimate: recomputed in the browser whenever the form
 * changes, with no request. The server validated the pricing table at boot, so
 * only a malformed prop reaches the null branch; the page then shows a fallback
 * message instead of throwing.
 */
export function useEstimate(pricing: MaybeRefOrGetter<PricingTable>, form: GenerationFormInput): LiveEstimate {
    const referenceImageCount = computed(() => countedReferenceImages(form.reference_images).length);

    function estimateWith(referenceCount: number): GenerationCostEstimate | null {
        try {
            return estimateGenerationCost(toValue(pricing), {
                prompt: form.prompt,
                quality: form.quality,
                size: form.size,
                reference_image_count: referenceCount,
            });
        } catch {
            return null;
        }
    }

    const estimate = computed(() => estimateWith(referenceImageCount.value));

    const perReferenceNanodollars = computed<number | null>(() => {
        const current = estimate.value;
        const withOneMore = estimateWith(referenceImageCount.value + 1);

        return current && withOneMore ? withOneMore.user_nanodollars.max - current.user_nanodollars.max : null;
    });

    return { estimate, referenceImageCount, perReferenceNanodollars };
}
