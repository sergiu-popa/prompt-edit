<script setup lang="ts">
import { computed } from 'vue';

import AmountRange from '@/components/estimate/AmountRange.vue';
import Badge from '@/components/ui/Badge.vue';
import { formatCount } from '@/lib/counting';
import { MARKUP_PERCENT } from '@/lib/estimate';
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
        { label: `+ ${MARKUP_PERCENT}%`, cost: { min: user.min - provider.min, max: user.max - provider.max }, emphasis: false },
        { label: 'You pay', cost: user, emphasis: true },
    ];
});
</script>

<template>
    <!--
        A container query, not a viewport one: the panel is narrow both on a phone and in
        the desktop sidebar. Below 24rem (a phone) each line stacks its cost under its tokens,
        so the amounts never run past the card; from 24rem (the 30rem desktop sidebar) it is
        an ordinary three-column table, one row per line.
    -->
    <div class="@container">
        <table class="block w-full text-sm @sm:table">
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
            <tbody class="grid grid-cols-[max-content_minmax(0,1fr)] @sm:table-row-group">
                <tr v-for="line in tokenLines" :key="line.label" class="col-span-2 grid grid-cols-subgrid py-1.5 align-top @sm:table-row">
                    <th scope="row" class="pr-3 text-left font-medium whitespace-nowrap text-zinc-800 @sm:py-1.5">{{ line.label }}</th>
                    <td class="text-zinc-600 tabular-nums @sm:py-1.5 @sm:pr-3">
                        <!-- A token count never breaks; the empty-prompt message is a sentence, so it wraps inside its cell. -->
                        <span :class="{ 'whitespace-nowrap': line.flag !== null }">{{ line.tokens }}</span>
                        {{ ' ' }}
                        <Badge v-if="line.flag" :tone="line.flag === 'exact' ? 'success' : 'warning'">{{ line.flag }}</Badge>
                        <span v-if="line.detail" class="block text-xs text-zinc-500">{{ line.detail }}</span>
                    </td>
                    <td class="col-span-2 text-right whitespace-nowrap text-zinc-900 @sm:py-1.5">
                        <AmountRange :min="line.cost.min" :max="line.cost.max" align="end" />
                    </td>
                </tr>
            </tbody>
            <tbody class="block border-t border-zinc-200 pt-1 @sm:table-row-group">
                <tr v-for="line in totalLines" :key="line.label" class="flex justify-between pt-2 @sm:table-row">
                    <th
                        scope="row"
                        :class="[
                            'pr-3 text-left whitespace-nowrap @sm:pt-2.5',
                            line.emphasis ? 'font-semibold text-zinc-900' : 'font-medium text-zinc-800',
                        ]"
                    >
                        {{ line.label }}
                    </th>
                    <td class="@sm:pt-2.5 @sm:pr-3" />
                    <td
                        :class="[
                            'text-right whitespace-nowrap @sm:pt-2.5',
                            line.emphasis ? 'font-semibold text-zinc-900' : 'text-zinc-900',
                        ]"
                    >
                        <AmountRange :min="line.cost.min" :max="line.cost.max" align="end" />
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
