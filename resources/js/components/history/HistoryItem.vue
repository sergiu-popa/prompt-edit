<script setup lang="ts">
import { computed, ref, useId } from 'vue';

import AmountRange from '@/components/estimate/AmountRange.vue';
import ResultImage from '@/components/history/ResultImage.vue';
import Badge from '@/components/ui/Badge.vue';
import MoneyAmount from '@/components/ui/MoneyAmount.vue';
import { countCodePoints } from '@/lib/counting';
import { describeImage } from '@/lib/imageOptions';
import { elapsedSeconds } from '@/lib/generationTiming';
import { formatClockTime, formatElapsed, formatExactTimestamp, formatRelativeTime } from '@/lib/relativeTime';
import type { Generation, GenerationStatus } from '@/types/generation';

/**
 * One generation on History: its status, the prompt (clamped to two lines), what was
 * asked for, the estimate the user saw and when; once it succeeded, its image, what it
 * was actually charged and when it completed. Every time is read on `now`, the
 * server-aligned clock History ticks, so a processing badge counts up live. From `sm`
 * the badge has its own column, with the image under it, and the time sits on the
 * right; on a phone the image comes first, then the badge and time share a line. It owns
 * its row's padding, so the highlight fills the row; inside a list's rounded card, the
 * first and last rows round their corners to match.
 */
const props = withDefaults(
    defineProps<{
        generation: Generation;
        /** The generation just submitted: an accent bar, a tint and a "Just submitted" tag. */
        highlighted?: boolean;
        /** The server's "now" in epoch ms (useServerClock). */
        now: number;
    }>(),
    { highlighted: false },
);

/** Past this many code points, or with a line break, two lines may hide part of the prompt. */
const LONG_PROMPT_CODE_POINTS = 100;

const STATUS_TONES: Record<GenerationStatus, 'neutral' | 'info' | 'success' | 'danger'> = {
    pending: 'neutral',
    queued: 'neutral',
    processing: 'info',
    succeeded: 'success',
    failed: 'danger',
};

const promptId = useId();
const isExpanded = ref(false);

const isLongPrompt = computed(
    () => countCodePoints(props.generation.prompt) > LONG_PROMPT_CODE_POINTS || /[\r\n]/.test(props.generation.prompt),
);

const references = computed(() => {
    const count = props.generation.reference_image_count;

    if (count === 0) {
        return 'No references';
    }

    return count === 1 ? '1 reference' : `${count} references`;
});

/** "Processing · 14 s" while it runs, else the server's label. */
const statusText = computed(() => {
    const { status, status_label: label, started_at: startedAt } = props.generation;

    if (status !== 'processing' || startedAt === null) {
        return label;
    }

    return `${label} · ${formatElapsed(elapsedSeconds(startedAt, props.now))}`;
});

const createdAgo = computed(() => formatRelativeTime(props.generation.created_at, new Date(props.now)));
const createdExactly = computed(() => formatExactTimestamp(props.generation.created_at));

const isSucceeded = computed(() => props.generation.status === 'succeeded');

/** What the user paid; the server sends it for a succeeded generation only. */
const charge = computed(() => (isSucceeded.value ? props.generation.user_charge_nanodollars : null));

const completedAt = computed(() => (isSucceeded.value ? props.generation.completed_at : null));
const completedClock = computed(() => (completedAt.value === null ? '' : formatClockTime(completedAt.value, new Date(props.now))));
const completedExactly = computed(() => (completedAt.value === null ? '' : formatExactTimestamp(completedAt.value)));
</script>

<template>
    <div
        :data-highlighted="highlighted ? 'true' : undefined"
        :class="[
            'flex flex-col gap-2 px-4 py-4 group-first:rounded-t-[calc(var(--radius-xl)-1px)] group-last:rounded-b-[calc(var(--radius-xl)-1px)] sm:grid sm:grid-cols-[8.5rem_minmax(0,1fr)_auto] sm:grid-rows-[auto_1fr] sm:items-start sm:gap-x-4 sm:px-5',
            highlighted && 'bg-sky-50/70 shadow-[inset_4px_0_0_var(--color-sky-600)]',
        ]"
    >
        <ResultImage
            v-if="isSucceeded && generation.image_url !== null"
            :src="generation.image_url"
            :alt="generation.prompt"
            class="mb-1 sm:col-start-1 sm:row-start-2 sm:mb-0"
        />

        <div class="flex items-center justify-between gap-3 sm:contents">
            <Badge :tone="STATUS_TONES[generation.status]" class="tabular-nums sm:col-start-1 sm:row-start-1 sm:justify-self-start">{{
                statusText
            }}</Badge>
            <div class="flex shrink-0 items-center gap-2 sm:col-start-3 sm:row-start-1 sm:flex-col sm:items-end sm:gap-1">
                <time :datetime="generation.created_at" :title="createdExactly" class="text-sm whitespace-nowrap text-zinc-500">{{
                    createdAgo
                }}</time>
                <span v-if="highlighted" class="text-xs font-medium whitespace-nowrap text-sky-800">Just submitted</span>
            </div>
        </div>

        <div class="flex min-w-0 flex-col gap-1 sm:col-start-2 sm:row-span-2 sm:row-start-1">
            <p
                :id="promptId"
                data-prompt
                :title="generation.prompt"
                :class="['text-sm font-medium wrap-break-word text-zinc-900', isExpanded ? 'whitespace-pre-line' : 'line-clamp-2']"
            >
                {{ generation.prompt }}
            </p>
            <button
                v-if="isLongPrompt"
                type="button"
                :aria-expanded="isExpanded ? 'true' : 'false'"
                :aria-controls="promptId"
                class="self-start rounded-sm text-sm font-medium text-zinc-600 underline underline-offset-4 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900"
                @click="isExpanded = !isExpanded"
            >
                {{ isExpanded ? 'Show less' : 'Show full prompt' }}
            </button>
            <p data-meta class="text-sm text-zinc-600">{{ describeImage(generation.quality, generation.size) }} · {{ references }}</p>
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <p v-if="charge !== null" data-charge class="text-sm font-medium text-zinc-900">
                    Charged
                    <MoneyAmount :nanodollars="charge" />
                </p>
                <p data-estimate class="text-sm text-zinc-600">
                    Est.
                    <AmountRange :min="generation.user_estimate_nanodollars.min" :max="generation.user_estimate_nanodollars.max" />
                </p>
            </div>
            <p v-if="completedAt !== null" data-completed class="text-sm text-zinc-600">
                Completed
                <time :datetime="completedAt" :title="completedExactly">{{ completedClock }}</time>
            </p>
        </div>
    </div>
</template>
