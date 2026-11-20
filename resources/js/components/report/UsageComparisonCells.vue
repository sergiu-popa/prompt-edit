<script setup lang="ts">
import UsageDelta from '@/components/report/UsageDelta.vue';
import Badge from '@/components/ui/Badge.vue';
import MoneyAmount from '@/components/ui/MoneyAmount.vue';
import { formatCount } from '@/lib/counting';
import type { UsageComparisonLine, UsageFigures } from '@/types/usage';

/**
 * The cells of one reconciliation line, a day or the total, after its label: the status first,
 * so the verdict shows before the table scrolls sideways, then counts, costs and tokens. While Pixelforge's figures are missing, its cells, the deltas and
 * the status read "—". It renders the cells alone, so its row stays the table's own.
 */
defineProps<{
    line: UsageComparisonLine;
}>();

const TOKEN_FIELDS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as const;

function tokens(figures: UsageFigures): string {
    return TOKEN_FIELDS.map((field) => formatCount(figures[field])).join(' / ');
}

const cell = 'px-2 py-2.5 text-right whitespace-nowrap tabular-nums';
const missing = 'px-2 py-2.5 text-right whitespace-nowrap text-zinc-400';
</script>

<template>
    <td data-status class="px-3 py-2.5 text-left whitespace-nowrap">
        <Badge v-if="line.matches === true" tone="success">Match</Badge>
        <Badge v-else-if="line.matches === false" tone="danger">Mismatch</Badge>
        <span v-else class="text-zinc-400">—</span>
    </td>

    <td v-if="line.pixelforge" data-pixelforge-generations :class="cell">{{ formatCount(line.pixelforge.generations) }}</td>
    <td v-else data-pixelforge-generations :class="missing">—</td>
    <td data-recorded-generations :class="cell">{{ formatCount(line.recorded.generations) }}</td>

    <td v-if="line.pixelforge" data-pixelforge-cost :class="cell">
        <MoneyAmount :nanodollars="line.pixelforge.provider_cost_nanodollars" align="end" />
    </td>
    <td v-else data-pixelforge-cost :class="missing">—</td>
    <td data-recorded-cost :class="cell"><MoneyAmount :nanodollars="line.recorded.provider_cost_nanodollars" align="end" /></td>
    <td v-if="line.delta" data-cost-delta :class="cell"><UsageDelta :value="line.delta.provider_cost_nanodollars" kind="usd" /></td>
    <td v-else data-cost-delta :class="missing">—</td>

    <td v-if="line.pixelforge" data-pixelforge-tokens :class="cell">{{ tokens(line.pixelforge) }}</td>
    <td v-else data-pixelforge-tokens :class="missing">—</td>
    <td data-recorded-tokens :class="cell">{{ tokens(line.recorded) }}</td>
    <td v-if="line.delta" data-tokens-delta :class="cell">
        <template v-for="(field, index) in TOKEN_FIELDS" :key="field"
            ><template v-if="index > 0"> / </template><UsageDelta :value="line.delta[field]" kind="count"
        /></template>
    </td>
    <td v-else data-tokens-delta :class="missing">—</td>
</template>
