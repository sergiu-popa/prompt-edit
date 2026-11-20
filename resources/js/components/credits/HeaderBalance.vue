<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import MoneyAmount from '@/components/ui/MoneyAmount.vue';
import { paths } from '@/lib/paths';
import type { CreditBalance } from '@/types/credits';

/**
 * The header's available balance, with its exact value one hover, focus or tap away,
 * and the way to Buy credits. Below `sm` the word "Balance" is for assistive technology
 * only, so the nav row fits a phone. A negative balance is red and keeps its minus sign,
 * so colour is never the only cue.
 */
const props = defineProps<{
    credits: CreditBalance;
}>();

const isNegative = computed(() => props.credits.available_nanodollars < 0);
</script>

<template>
    <div class="flex items-center gap-2">
        <p data-balance class="flex items-center gap-1.5 text-sm whitespace-nowrap text-zinc-600">
            <span class="sr-only sm:not-sr-only">Balance</span>
            <MoneyAmount
                :nanodollars="credits.available_nanodollars"
                balance
                align="end"
                :class="['font-medium', isNegative ? 'text-red-700' : 'text-zinc-900']"
            />
        </p>
        <Link
            :href="paths.credits"
            data-buy-credits
            class="rounded-md px-2 py-1.5 text-sm font-medium whitespace-nowrap text-sky-700 transition-colors hover:bg-sky-50 hover:text-sky-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900"
            >Buy credits</Link
        >
    </div>
</template>
