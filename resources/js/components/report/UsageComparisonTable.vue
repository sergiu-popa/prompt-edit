<script setup lang="ts">
import { computed, useId } from 'vue';

import UsageComparisonCells from '@/components/report/UsageComparisonCells.vue';
import Badge from '@/components/ui/Badge.vue';
import type { UsageComparison } from '@/types/usage';

/**
 * The reconciliation with Pixelforge's `/v1/usage` (D-72): per UTC day and in total,
 * Pixelforge's figures beside ours, the deltas and an exact Match or Mismatch, with a notice
 * while Pixelforge's figures are missing. Like ReportTable, the table scrolls sideways
 * inside its card, so the page never does.
 */
const props = defineProps<{
    usage: UsageComparison;
}>();

const NOTICES = {
    unavailable:
        'Pixelforge usage is unavailable right now. Our recorded figures are shown; the comparison will return when Pixelforge responds.',
    range_too_long: "Pixelforge refused this range as too long, so it isn't compared. Our recorded figures are shown.",
} as const;

const titleId = useId();

const notice = computed(() => (props.usage.availability === 'available' ? null : NOTICES[props.usage.availability]));
const hasDayInProgress = computed(() => props.usage.days.some((day) => day.in_progress));

const subHeader = 'px-3 py-2 text-right font-medium whitespace-nowrap';
</script>

<template>
    <section class="rounded-xl border border-zinc-200 bg-white shadow-xs">
        <div class="flex flex-col gap-3 px-4 pt-4 pb-3">
            <h2 :id="titleId" class="text-base font-semibold text-zinc-900">Pixelforge reconciliation (per UTC day)</h2>
            <p class="text-sm text-zinc-600">
                Pixelforge's own usage report for this API key beside what we recorded. A day is a Match only when its provider cost and all
                four token counts are exactly equal; Δ is Pixelforge − ours.
            </p>
            <p v-if="notice" data-usage-notice role="status" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                {{ notice }}
            </p>
            <p v-if="usage.has_mismatch" data-mismatch-explanation class="rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900">
                Pixelforge counts every generation made with this API key. A mismatch usually means the key was used outside this app (for
                example a local test), generations were recorded before a database reset, or a generation finished at Pixelforge that we
                haven't recorded yet (it catches up within minutes). Our recorded figures come from each generation's own usage and rates.
            </p>
        </div>
        <div class="overflow-x-auto">
            <table :aria-labelledby="titleId" class="w-full text-sm text-zinc-900">
                <thead class="text-zinc-600">
                    <tr>
                        <th scope="col" rowspan="2" class="px-4 py-2 text-left align-bottom font-medium whitespace-nowrap">Date</th>
                        <th scope="colgroup" colspan="2" class="px-3 pt-2 text-right font-medium whitespace-nowrap">Generations</th>
                        <th scope="colgroup" colspan="3" class="px-3 pt-2 text-right font-medium whitespace-nowrap">Provider cost</th>
                        <th scope="colgroup" colspan="3" class="px-3 pt-2 text-right font-medium whitespace-nowrap">
                            Tokens (text / image / output / reasoning)
                        </th>
                        <th scope="col" rowspan="2" class="px-4 py-2 text-left align-bottom font-medium whitespace-nowrap">Status</th>
                    </tr>
                    <tr class="border-b border-zinc-200 text-xs">
                        <th scope="col" :class="subHeader">Pixelforge says</th>
                        <th scope="col" :class="subHeader">We recorded</th>
                        <th scope="col" :class="subHeader">Pixelforge says</th>
                        <th scope="col" :class="subHeader">We recorded</th>
                        <th scope="col" :class="subHeader">Δ</th>
                        <th scope="col" :class="subHeader">Pixelforge says</th>
                        <th scope="col" :class="subHeader">We recorded</th>
                        <th scope="col" :class="subHeader">Δ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    <tr v-for="day in usage.days" :key="day.date" data-usage-day :data-in-progress="day.in_progress ? '' : undefined">
                        <th scope="row" class="px-4 py-2.5 text-left font-normal whitespace-nowrap tabular-nums">
                            <span class="inline-flex items-center gap-2">
                                {{ day.date }}
                                <Badge v-if="day.in_progress" tone="info">In progress</Badge>
                            </span>
                        </th>
                        <UsageComparisonCells :line="day" />
                    </tr>
                </tbody>
                <tfoot>
                    <tr data-usage-total class="border-t border-zinc-200 font-semibold">
                        <th scope="row" class="px-4 py-2.5 text-left">Total</th>
                        <UsageComparisonCells :line="usage.total" />
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="flex flex-col gap-1 border-t border-zinc-100 px-4 py-3 text-xs text-zinc-500">
            <p v-if="hasDayInProgress" data-in-progress-note>
                Today (UTC) is in progress: generations still processing may complete later today.
            </p>
            <p>Generation counts are shown but never compared: Pixelforge doesn't say whether it counts failed generations.</p>
        </div>
    </section>
</template>
