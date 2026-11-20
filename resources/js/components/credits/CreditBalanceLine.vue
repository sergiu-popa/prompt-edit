<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import MoneyAmount from '@/components/ui/MoneyAmount.vue';
import { formatCount } from '@/lib/counting';
import { paths } from '@/lib/paths';
import type { CreditBalance } from '@/types/credits';

/**
 * Generate's balance: what is available, and what the holds of running generations
 * reserve (PE-017 AC 4). The reserved part is left out while nothing runs. A negative
 * balance, after a charge above its hold (AC 13), says in words that a top-up is
 * needed and links to Buy credits, so it never relies on colour alone.
 */
const props = defineProps<{
    credits: CreditBalance;
}>();

const isNegative = computed(() => props.credits.available_nanodollars < 0);

const runningLabel = computed(() => {
    const running = props.credits.running_generations;

    return `${formatCount(running)} running ${running === 1 ? 'generation' : 'generations'}`;
});
</script>

<template>
    <p v-if="isNegative" data-credit-balance class="text-sm text-zinc-600">
        Balance <MoneyAmount :nanodollars="credits.available_nanodollars" balance class="font-medium text-red-700" /> · Top up to keep
        generating.
        <Link
            :href="paths.credits"
            class="rounded-sm font-medium text-sky-700 underline underline-offset-4 hover:text-sky-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900"
            >Buy credits</Link
        >
    </p>
    <p v-else data-credit-balance class="text-sm text-zinc-600">
        Available
        <MoneyAmount :nanodollars="credits.available_nanodollars" balance class="font-medium text-zinc-900" /><template
            v-if="credits.running_generations > 0"
        >
            · <MoneyAmount :nanodollars="credits.reserved_nanodollars" class="font-medium text-zinc-900" /> reserved for
            {{ runningLabel }}</template
        >
    </p>
</template>
