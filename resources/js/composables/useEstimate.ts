import type { ComputedRef, MaybeRefOrGetter } from 'vue';
import { computed, toValue } from 'vue';

import { estimateGenerationCost } from '@/lib/estimate';
import type { GenerationCostEstimate } from '@/types/estimate';
import type { GenerationFormInput } from '@/types/generation';
import type { PricingTable } from '@/types/pricing';

export interface LiveEstimate {
    /** Null when the estimate cannot be computed, e.g. for a malformed pricing table. */
    estimate: ComputedRef<GenerationCostEstimate | null>;
}

/**
 * The Generate page's live estimate: recomputed in the browser whenever the form
 * changes, with no request. The server validated the pricing table at boot, so
 * only a malformed prop reaches the null branch; the page then shows a fallback
 * message instead of throwing.
 */
export function useEstimate(pricing: MaybeRefOrGetter<PricingTable>, form: GenerationFormInput): LiveEstimate {
    const estimate = computed<GenerationCostEstimate | null>(() => {
        try {
            return estimateGenerationCost(toValue(pricing), {
                prompt: form.prompt,
                quality: form.quality,
                size: form.size,
                reference_image_count: 0,
            });
        } catch {
            return null;
        }
    });

    return { estimate };
}
