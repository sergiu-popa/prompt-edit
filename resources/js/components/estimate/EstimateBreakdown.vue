<script setup lang="ts">
import { computed } from 'vue';

import AmountRange from '@/components/estimate/AmountRange.vue';
import { formatCount } from '@/lib/counting';
import { describeImage } from '@/lib/imageOptions';
import type { EstimateBounds, EstimateLine, GenerationCostEstimate } from '@/types/estimate';
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
    /** The counted (non-blank) reference rows; the references line shows only when there are some. */
    referenceImageCount: number;
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

/** The flag comes from the estimate, so the panel can never label a line differently from how it is priced. */
function flagOf(line: EstimateLine): 'exact' | 'estimated' {
    return line.exact ? 'exact' : 'estimated';
}

function tokenRange(bounds: EstimateBounds): string {
    return bounds.min === bounds.max ? formatCount(bounds.min) : `${formatCount(bounds.min)}–${formatCount(bounds.max)}`;
}

const tokenLines = computed<TokenLine[]>(() => {
    const { lines, prompt_characters: promptCharacters } = props.estimate;
    const text = lines.input_text_tokens;
    const images = lines.input_image_tokens;
    const referenceCount = props.referenceImageCount;

    const promptLine: TokenLine =
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
                  flag: flagOf(text),
                  detail: null,
                  cost: text.provider_nanodollars,
              };

    /** "765 × 1 = 765": the per-image count is exact, so the division is too. */
    const referencesLines: TokenLine[] =
        referenceCount > 0
            ? [
                  {
                      label: 'Reference images',
                      tokens: `${formatCount(images.tokens.max / referenceCount)} × ${formatCount(referenceCount)} = ${formatCount(images.tokens.max)}`,
                      flag: flagOf(images),
                      detail: null,
                      cost: images.provider_nanodollars,
                  },
              ]
            : [];

    return [
        promptLine,
        ...referencesLines,
        {
            label: 'Output',
            tokens: tokenRange(lines.output_tokens.tokens),
            flag: flagOf(lines.output_tokens),
            detail: describeImage(props.quality, props.size),
            cost: lines.output_tokens.provider_nanodollars,
        },
        {
            label: 'Reasoning',
            tokens: tokenRange(lines.reasoning_tokens.tokens),
            flag: flagOf(lines.reasoning_tokens),
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
    <!--
        A container query, not a viewport one: the panel is narrow both on a phone and in
        the desktop sidebar. Below 32rem each line stacks its cost under its tokens, so the
        amounts never run past the card; from 32rem it is an ordinary three-column table.
    -->
    <div class="@container">
        <table class="block w-full text-sm @lg:table">
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
            <tbody class="grid grid-cols-[max-content_minmax(0,1fr)] @lg:table-row-group">
                <tr v-for="line in tokenLines" :key="line.label" class="col-span-2 grid grid-cols-subgrid py-1.5 align-top @lg:table-row">
                    <th scope="row" class="pr-3 text-left font-medium whitespace-nowrap text-zinc-800 @lg:py-1.5">{{ line.label }}</th>
                    <td class="text-zinc-600 tabular-nums @lg:py-1.5 @lg:pr-3">
                        <span class="whitespace-nowrap">{{ line.tokens }}</span>
                        {{ ' ' }}
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
                    <td class="col-span-2 text-right whitespace-nowrap text-zinc-900 @lg:py-1.5">
                        <AmountRange :min="line.cost.min" :max="line.cost.max" align="end" />
                    </td>
                </tr>
            </tbody>
            <tbody class="block border-t border-zinc-200 @lg:table-row-group">
                <tr v-for="line in totalLines" :key="line.label" class="flex justify-between pt-2 @lg:table-row">
                    <th
                        scope="row"
                        :class="[
                            'pr-3 text-left whitespace-nowrap @lg:pt-2',
                            line.emphasis ? 'font-semibold text-zinc-900' : 'font-medium text-zinc-800',
                        ]"
                    >
                        {{ line.label }}
                    </th>
                    <td class="@lg:pt-2 @lg:pr-3" />
                    <td :class="['text-right whitespace-nowrap @lg:pt-2', line.emphasis ? 'font-semibold text-zinc-900' : 'text-zinc-900']">
                        <AmountRange :min="line.cost.min" :max="line.cost.max" align="end" />
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
