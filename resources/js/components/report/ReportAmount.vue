<script setup lang="ts">
import { computed } from 'vue';

import MoneyAmount from '@/components/ui/MoneyAmount.vue';

/**
 * A report amount, right-aligned with its exact value on hover or focus. A negative amount,
 * a loss, keeps money.ts's minus sign and is also set in medium weight and red, so it never
 * relies on colour alone (AC 15).
 */
const props = defineProps<{
    nanodollars: number;
}>();

const isNegative = computed(() => props.nanodollars < 0);
</script>

<template>
    <span :data-negative="isNegative ? '' : undefined" :class="isNegative ? 'font-medium text-red-700' : undefined">
        <MoneyAmount :nanodollars="nanodollars" align="end" />
    </span>
</template>
