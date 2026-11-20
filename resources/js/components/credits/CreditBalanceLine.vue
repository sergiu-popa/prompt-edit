<script setup lang="ts">
import { computed } from 'vue';

import MoneyAmount from '@/components/ui/MoneyAmount.vue';
import { formatCount } from '@/lib/counting';
import type { CreditBalance } from '@/types/credits';

/**
 * Generate's balance: what is available, and what the holds of running generations
 * reserve (PE-017 AC 4). The reserved part is left out while nothing runs.
 */
const props = defineProps<{
    credits: CreditBalance;
}>();

const runningLabel = computed(() => {
    const running = props.credits.running_generations;

    return `${formatCount(running)} running ${running === 1 ? 'generation' : 'generations'}`;
});
</script>

<template>
    <p data-credit-balance class="text-sm text-zinc-600">
        Available
        <MoneyAmount :nanodollars="credits.available_nanodollars" balance class="font-medium text-zinc-900" /><template
            v-if="credits.running_generations > 0"
        >
            · <MoneyAmount :nanodollars="credits.reserved_nanodollars" class="font-medium text-zinc-900" /> reserved for
            {{ runningLabel }}</template
        >
    </p>
</template>
