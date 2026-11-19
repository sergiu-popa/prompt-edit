<script setup lang="ts">
import { computed } from 'vue';

import AmountRange from '@/components/estimate/AmountRange.vue';
import { formatCount } from '@/lib/counting';
import { describeImage } from '@/lib/imageOptions';
import type { EstimateBounds, GenerationCostEstimate } from '@/types/estimate';
import type { ImageQuality, ImageSize } from '@/types/pricing';

/**
 * Tokens and Pixelforge cost per token type, then the totals. The markup is applied
 * once, to the totals, as settlement charges it. Each line is rounded on its own,
 * so the 4-decimal lines may not add up; their exact values do.
 */
const props = defineProps<{
    estimate: GenerationCostEstimate;
    quality: ImageQuality;
    size: ImageSize;
}>();

interface TokenLine {
    label: string;
    tokens: string;
    flag: 'exact' | 'estimated' | null;
    detail: string | null;
    cost: EstimateBounds;
}

interface TotalLine {
    label: string;
    cost: { min: number; max: number };
    emphasis: boolean;
}

function tokenRange(bounds: EstimateBounds): string {
    return bounds.min === bounds.max ? formatCount(bounds.min) : `${formatCount(bounds.min)}–${formatCount(bounds.max)}`;
}

const tokenLines = computed<TokenLine[]>(() => {
    const { lines, prompt_characters: promptCharacters } = props.estimate;
    const text = lines.input_text_tokens;

    return [
        promptCharacters === 0
            ? {
                  label: 'Prompt text',
                  tokens: '0 tokens. Start typing your prompt.',
                  flag: null,
                  detail: null,
                  cost: text.provider_nanodollars,
              }
            : {
                  label: 'Prompt text',
                  tokens: `≈ ${tokenRange(text.tokens)}`,
                  flag: 'estimated',
                  detail: null,
                  cost: text.provider_nanodollars,
              },
        {
            label: 'Output',
            tokens: tokenRange(lines.output_tokens.tokens),
            flag: 'exact',
            detail: describeImage(props.quality, props.size),
            cost: lines.output_tokens.provider_nanodollars,
        },
        {
            label: 'Reasoning',
            tokens: tokenRange(lines.reasoning_tokens.tokens),
            flag: 'estimated',
            detail: null,
            cost: lines.reasoning_tokens.provider_nanodollars,
        },
    ];
});

const totalLines = computed<TotalLine[]>(() => {
    const provider = props.estimate.provider_nanodollars;
    const user = props.estimate.user_nanodollars;

    return [
        { label: 'Pixelforge cost', cost: provider, emphasis: false },
        { label: '+ 25%', cost: { min: user.min - provider.min, max: user.max - provider.max }, emphasis: false },
        { label: 'You pay', cost: user, emphasis: true },
    ];
});
</script>

<template>
    <table class="w-full text-sm">
        <caption class="sr-only">
            Cost breakdown
        </caption>
        <thead class="sr-only">
            <tr>
                <th scope="col">Line</th>
                <th scope="col">Tokens</th>
                <th scope="col">Pixelforge cost</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="line in tokenLines" :key="line.label" class="align-top">
                <th scope="row" class="py-1.5 pr-3 text-left font-medium whitespace-nowrap text-zinc-800">{{ line.label }}</th>
                <td class="py-1.5 pr-3 text-zinc-600 tabular-nums">
                    {{ line.tokens }}
                    <span
                        v-if="line.flag"
                        :class="[
                            'inline-block rounded-sm px-1 text-xs font-medium',
                            line.flag === 'exact' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700',
                        ]"
                        >{{ line.flag }}</span
                    >
                    <span v-if="line.detail" class="block text-xs text-zinc-500">{{ line.detail }}</span>
                </td>
                <td class="py-1.5 text-right whitespace-nowrap text-zinc-900">
                    <AmountRange :min="line.cost.min" :max="line.cost.max" align="end" />
                </td>
            </tr>
        </tbody>
        <tbody class="border-t border-zinc-200">
            <tr v-for="line in totalLines" :key="line.label">
                <th
                    scope="row"
                    :class="[
                        'pt-2 pr-3 text-left whitespace-nowrap',
                        line.emphasis ? 'font-semibold text-zinc-900' : 'font-medium text-zinc-800',
                    ]"
                >
                    {{ line.label }}
                </th>
                <td class="pt-2 pr-3" />
                <td :class="['pt-2 text-right whitespace-nowrap', line.emphasis ? 'font-semibold text-zinc-900' : 'text-zinc-900']">
                    <AmountRange :min="line.cost.min" :max="line.cost.max" align="end" />
                </td>
            </tr>
        </tbody>
    </table>
</template>
