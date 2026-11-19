<script setup lang="ts">
import { computed, ref, useId } from 'vue';

import { formatUsd, formatUsdExact } from '@/lib/money';

/**
 * An amount shown to 4 decimals, with its exact 9-decimal value one hover, focus or
 * tap away (a toggletip). The exact value always describes the button, so it is
 * never hover-only. Hidden, the tip is `display: none`, so it never widens the page.
 */
const props = withDefaults(
    defineProps<{
        nanodollars: number;
        /** Which edge of the amount the tip lines up with. */
        align?: 'start' | 'end';
    }>(),
    { align: 'start' },
);

const tooltipId = useId();
const isRevealed = ref(false);

const displayed = computed(() => formatUsd(props.nanodollars));
const exact = computed(() => formatUsdExact(props.nanodollars));

function reveal(): void {
    isRevealed.value = true;
}

function conceal(): void {
    isRevealed.value = false;
}
</script>

<template>
    <span class="relative inline-block">
        <button
            type="button"
            :aria-describedby="tooltipId"
            class="cursor-help rounded-sm tabular-nums underline decoration-zinc-300 decoration-dotted underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900"
            @mouseenter="reveal"
            @mouseleave="conceal"
            @focus="reveal"
            @blur="conceal"
            @click="reveal"
            @keydown.escape="conceal"
        >
            {{ displayed }}
        </button>
        <span
            v-show="isRevealed"
            :id="tooltipId"
            role="tooltip"
            :class="[
                'absolute bottom-full z-10 mb-1.5 rounded-md bg-zinc-900 px-2 py-1 text-xs font-normal whitespace-nowrap text-white tabular-nums shadow-sm',
                align === 'end' ? 'right-0' : 'left-0',
            ]"
            >{{ exact }}</span
        >
    </span>
</template>
