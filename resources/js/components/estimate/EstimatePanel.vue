<script setup lang="ts">
import { useId } from 'vue';

import AmountRange from '@/components/estimate/AmountRange.vue';
import EstimateBreakdown from '@/components/estimate/EstimateBreakdown.vue';
import MoneyAmount from '@/components/ui/MoneyAmount.vue';
import type { GenerationCostEstimate } from '@/types/estimate';
import type { ImageQuality, ImageSize } from '@/types/pricing';

/**
 * What a generation will cost: the range and typical figure, the breakdown, and the
 * pricing copy. The copy never presents the max as a ceiling: rates can change
 * between this estimate and creation (requirements §6).
 */
defineProps<{
    /** Null when the estimate cannot be computed. */
    estimate: GenerationCostEstimate | null;
    quality: ImageQuality;
    size: ImageSize;
}>();

const headingId = useId();

const copy = [
    "You pay Pixelforge's cost + 25%.",
    'Output, reference images and the markup are exact. Prompt text and reasoning are estimated until the image finishes.',
    '“Typically” assumes the average prompt-text and reasoning tokens.',
    'The actual cost is recorded when the generation finishes, from the tokens Pixelforge actually used.',
    'Failed generations are free.',
    "The estimate uses Pixelforge's published prices.",
    'Amounts are rounded to 4 decimals. Hover, focus or tap one for the exact value.',
] as const;
</script>

<template>
    <section :aria-labelledby="headingId" class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-white p-4 shadow-xs sm:p-6">
        <div class="flex flex-col gap-1">
            <h2 :id="headingId" class="text-sm font-medium text-zinc-600">Estimated cost</h2>

            <!-- Only the headline is live, so typing never reads the whole breakdown out. -->
            <div aria-live="polite" class="flex flex-col gap-1">
                <template v-if="estimate">
                    <p class="text-2xl font-semibold tracking-tight text-zinc-900">
                        <AmountRange :min="estimate.user_nanodollars.min" :max="estimate.user_nanodollars.max" />
                    </p>
                    <p class="text-sm text-zinc-600">typically <MoneyAmount :nanodollars="estimate.user_nanodollars.typical" /></p>
                </template>
                <p v-else class="text-sm text-zinc-800">We couldn't calculate an estimate right now.</p>
            </div>
        </div>

        <EstimateBreakdown v-if="estimate" :estimate="estimate" :quality="quality" :size="size" />

        <ul class="flex flex-col gap-1.5 border-t border-zinc-200 pt-4 text-sm text-zinc-600">
            <li v-for="statement in copy" :key="statement">{{ statement }}</li>
        </ul>
    </section>
</template>
