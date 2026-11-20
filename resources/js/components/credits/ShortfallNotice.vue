<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import { formatBalance, formatUsd, formatUsdRoundedUp } from '@/lib/money';
import { paths } from '@/lib/paths';

/**
 * Why Generate is closed for want of credit (PE-017 AC 11, 14), and the way to Buy
 * credits. The max estimate is what a generation reserves, never a promised ceiling
 * (PE-005 AC 6). The shortfall is rounded up, so the amount stated is always enough.
 * Only the server's refusal is announced as an alert; the advisory, which follows the
 * inputs as they change, is read with the button it describes.
 */
const props = withDefaults(
    defineProps<{
        required: number;
        available: number;
        shortfall: number;
        /** The server refused the generation, so it is announced. */
        announce?: boolean;
    }>(),
    { announce: false },
);

const copy = computed(
    () =>
        `This generation needs ${formatUsd(props.required)} reserved (its maximum estimate), but you have ` +
        `${formatBalance(props.available)} available. You need ${formatUsdRoundedUp(props.shortfall)} more.`,
);
</script>

<template>
    <div
        data-shortfall
        :role="announce ? 'alert' : undefined"
        class="flex flex-col gap-1 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-600/25 ring-inset"
    >
        <p data-shortfall-copy>{{ copy }}</p>
        <Link
            :href="paths.credits"
            class="self-start rounded-sm font-medium text-sky-700 underline underline-offset-4 hover:text-sky-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900"
            >Buy credits</Link
        >
    </div>
</template>
