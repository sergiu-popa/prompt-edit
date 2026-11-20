<script setup lang="ts">
import { computed } from 'vue';

import MoneyAmount from '@/components/ui/MoneyAmount.vue';
import { formatCount } from '@/lib/counting';

/**
 * One reconciliation delta, Pixelforge − ours. Zero reads a muted `0`. Any other value is
 * marked `data-nonzero` in medium-weight red, and its sign and digits differ from `0`, so
 * colour is never the only cue: a positive value gets a `+`, a negative one keeps money.ts's
 * or formatCount()'s own minus sign.
 */
const props = defineProps<{
    value: number;
    kind: 'usd' | 'count';
}>();

const isZero = computed(() => props.value === 0);
</script>

<template>
    <span v-if="isZero" class="text-zinc-400">0</span>
    <span v-else data-nonzero class="font-medium text-red-700"
        ><template v-if="value > 0">+</template><MoneyAmount v-if="kind === 'usd'" :nanodollars="value" align="end" /><template v-else>{{
            formatCount(value)
        }}</template></span
    >
</template>
