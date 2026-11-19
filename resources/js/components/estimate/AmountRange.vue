<script setup lang="ts">
import MoneyAmount from '@/components/ui/MoneyAmount.vue';

/** "$0.0125 – $0.1000", or one amount when both bounds are equal. */
withDefaults(defineProps<{ min: number; max: number; align?: 'start' | 'end' }>(), { align: 'start' });
</script>

<template>
    <span
        ><MoneyAmount :nanodollars="min" :align="align" /><template v-if="max !== min">
            – <MoneyAmount :nanodollars="max" :align="align" /></template
    ></span>
</template>
