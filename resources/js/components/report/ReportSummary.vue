<script setup lang="ts">
import ReportAmount from '@/components/report/ReportAmount.vue';
import { formatCount } from '@/lib/counting';
import type { ReportFigures } from '@/types/report';

/**
 * The range's totals at a glance: 2 × 2 on a phone, 4 across from `sm`.
 */
defineProps<{
    totals: ReportFigures;
}>();
</script>

<template>
    <dl class="grid grid-cols-2 gap-4 rounded-xl border border-zinc-200 bg-white p-4 shadow-xs sm:grid-cols-4 sm:p-5">
        <div class="flex flex-col gap-1">
            <dt class="text-sm text-zinc-600">Succeeded</dt>
            <dd data-succeeded class="text-lg font-semibold text-zinc-900 tabular-nums">{{ formatCount(totals.succeeded) }}</dd>
        </div>
        <div class="flex flex-col gap-1">
            <dt class="text-sm text-zinc-600">Spend</dt>
            <dd data-spend class="text-lg font-semibold text-zinc-900"><ReportAmount :nanodollars="totals.spend_nanodollars" /></dd>
        </div>
        <div class="flex flex-col gap-1">
            <dt class="text-sm text-zinc-600">Provider cost</dt>
            <dd data-provider-cost class="text-lg font-semibold text-zinc-900">
                <ReportAmount :nanodollars="totals.provider_cost_nanodollars" />
            </dd>
        </div>
        <div class="flex flex-col gap-1">
            <dt class="text-sm text-zinc-600">Profit</dt>
            <dd data-profit class="text-lg font-semibold text-zinc-900"><ReportAmount :nanodollars="totals.profit_nanodollars" /></dd>
        </div>
    </dl>
</template>
