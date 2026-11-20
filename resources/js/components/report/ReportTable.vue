<script setup lang="ts">
import ReportAmount from '@/components/report/ReportAmount.vue';
import { formatCount } from '@/lib/counting';
import type { ReportFigures, ReportTableRow } from '@/types/report';

/**
 * A captioned table of report figures, one row per day or user, with an optional totals
 * footer. It scrolls sideways inside its card on a narrow screen, so the page never does.
 * The header row sits above the first body row, so that row's exact-value tip stays inside
 * the scroll box.
 */
defineProps<{
    caption: string;
    labelHeader: string;
    rows: ReportTableRow[];
    totals?: ReportFigures;
    note?: string;
    emptyText?: string;
}>();

const cell = 'px-4 py-2.5 text-right whitespace-nowrap tabular-nums';
</script>

<template>
    <section class="rounded-xl border border-zinc-200 bg-white shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-zinc-900">
                <caption class="px-4 pt-4 pb-2 text-left text-base font-semibold">
                    {{
                        caption
                    }}
                </caption>
                <thead>
                    <tr class="border-b border-zinc-200 text-zinc-600">
                        <th scope="col" class="px-4 py-2 text-left font-medium whitespace-nowrap">{{ labelHeader }}</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium whitespace-nowrap">Succeeded</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium whitespace-nowrap">Spend</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium whitespace-nowrap">Provider cost</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium whitespace-nowrap">Profit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    <tr v-for="row in rows" :key="row.key" data-row>
                        <th scope="row" :title="row.label" class="max-w-56 truncate px-4 py-2.5 text-left font-normal tabular-nums">
                            {{ row.label }}
                        </th>
                        <td data-succeeded :class="cell">{{ formatCount(row.figures.succeeded) }}</td>
                        <td data-spend :class="cell"><ReportAmount :nanodollars="row.figures.spend_nanodollars" /></td>
                        <td data-provider-cost :class="cell"><ReportAmount :nanodollars="row.figures.provider_cost_nanodollars" /></td>
                        <td data-profit :class="cell"><ReportAmount :nanodollars="row.figures.profit_nanodollars" /></td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td colspan="5" class="px-4 py-8 text-center text-zinc-600">{{ emptyText }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="totals">
                    <tr data-totals class="border-t border-zinc-200 font-semibold">
                        <th scope="row" class="px-4 py-2.5 text-left">Total</th>
                        <td data-succeeded :class="cell">{{ formatCount(totals.succeeded) }}</td>
                        <td data-spend :class="cell"><ReportAmount :nanodollars="totals.spend_nanodollars" /></td>
                        <td data-provider-cost :class="cell"><ReportAmount :nanodollars="totals.provider_cost_nanodollars" /></td>
                        <td data-profit :class="cell"><ReportAmount :nanodollars="totals.profit_nanodollars" /></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p v-if="note" class="border-t border-zinc-100 px-4 py-3 text-xs text-zinc-500">{{ note }}</p>
    </section>
</template>
