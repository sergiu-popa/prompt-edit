<script setup lang="ts">
import { computed, useId } from 'vue';

import AmountRange from '@/components/estimate/AmountRange.vue';
import EstimateBreakdown from '@/components/estimate/EstimateBreakdown.vue';
import MoneyAmount from '@/components/ui/MoneyAmount.vue';
import { MARKUP_PERCENT } from '@/lib/estimate';
import { formatUsd } from '@/lib/money';
import type { GenerationCostEstimate } from '@/types/estimate';
import type { ImageQuality, ImageSize } from '@/types/pricing';

/**
 * What a generation will cost: the range and typical figure, the breakdown, and the
 * pricing copy. The copy never presents the max as a ceiling: rates can change
 * between this estimate and creation (requirements §6).
 */
const props = defineProps<{
    /** Null when the estimate cannot be computed. */
    estimate: GenerationCostEstimate | null;
    quality: ImageQuality;
    size: ImageSize;
    referenceImageCount: number;
}>();

const headingId = useId();

const UNAVAILABLE = "We couldn't calculate an estimate right now.";

/**
 * What the live region reads out when the headline changes. It is a separate, text-only
 * copy of the headline: inside a live region, opening an amount's exact-value tip would
 * itself be announced as an update.
 */
const announcement = computed(() => {
    if (!props.estimate) {
        return UNAVAILABLE;
    }

    const { min, typical, max } = props.estimate.user_nanodollars;
    const range = min === max ? formatUsd(min) : `${formatUsd(min)} to ${formatUsd(max)}`;

    return `Estimated cost ${range}, typically ${formatUsd(typical)}`;
});

/** One short statement per item; every point of PE-005 AC 6 is kept. */
const copy = [
    `You pay Pixelforge's cost + ${MARKUP_PERCENT}%.`,
    'Output, reference images and the markup are exact. Prompt text and reasoning are estimates until the image finishes.',
    '“Typically” assumes average prompt-text and reasoning tokens.',
    'The actual cost is recorded when the image finishes, from the tokens Pixelforge used.',
    'Failed generations are free.',
    "Prices are Pixelforge's published rates.",
    'Amounts are rounded to 4 decimals; hover, focus or tap one for the exact value.',
] as const;
</script>

<template>
    <!--
        Four sections, one spacing step apart: the headline, the breakdown, its totals (a
        divided group inside the breakdown's table) and the pricing footer. The footer bleeds
        to the card's edges, so the card keeps its padding as the content box amounts stay in.
    -->
    <section :aria-labelledby="headingId" class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-4 shadow-xs sm:p-6">
        <div class="flex flex-col gap-1">
            <h2 :id="headingId" class="text-sm font-medium text-zinc-600">Estimated cost</h2>

            <div data-headline class="flex flex-col gap-1">
                <template v-if="estimate">
                    <p class="text-2xl font-semibold tracking-tight text-zinc-900">
                        <AmountRange :min="estimate.user_nanodollars.min" :max="estimate.user_nanodollars.max" />
                    </p>
                    <p class="text-sm text-zinc-600">typically <MoneyAmount :nanodollars="estimate.user_nanodollars.typical" /></p>
                </template>
                <p v-else class="text-sm text-zinc-800">{{ UNAVAILABLE }}</p>
            </div>

            <!-- Only the headline is announced, so typing never reads the whole breakdown out. -->
            <p aria-live="polite" class="sr-only">{{ announcement }}</p>
        </div>

        <div v-if="estimate" class="border-t border-zinc-200 pt-3">
            <EstimateBreakdown :estimate="estimate" :quality="quality" :size="size" :reference-image-count="referenceImageCount" />
        </div>

        <footer class="-mx-4 -mb-4 rounded-b-xl border-t border-zinc-200 bg-zinc-50 px-4 py-3 sm:-mx-6 sm:-mb-6 sm:px-6 sm:py-4">
            <ul class="flex list-disc flex-col gap-1 pl-4 text-xs/5 text-zinc-600 marker:text-zinc-400">
                <li v-for="statement in copy" :key="statement">{{ statement }}</li>
            </ul>
        </footer>
    </section>
</template>
