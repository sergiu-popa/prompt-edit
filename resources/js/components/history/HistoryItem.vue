<script setup lang="ts">
import { computed, ref, useId } from 'vue';

import AmountRange from '@/components/estimate/AmountRange.vue';
import Badge from '@/components/ui/Badge.vue';
import { countCodePoints } from '@/lib/counting';
import { describeImage } from '@/lib/imageOptions';
import { formatExactTimestamp, formatRelativeTime } from '@/lib/relativeTime';
import type { Generation, GenerationStatus } from '@/types/generation';

/**
 * One generation on History: its status, the prompt (clamped to two lines), what was
 * asked for, the estimate the user saw and when. From `sm` the badge has its own
 * column and the time sits on the right; on a phone both share the first line. It owns
 * its row's padding, so the highlight fills the row; inside a list's rounded card, the
 * first and last rows round their corners to match.
 */
const props = withDefaults(
    defineProps<{
        generation: Generation;
        /** The generation just submitted: an accent bar, a tint and a "Just submitted" tag. */
        highlighted?: boolean;
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

/** Read once, when the item renders; History shows a snapshot until PE-012's live updates. */
const createdAgo = computed(() => formatRelativeTime(props.generation.created_at, new Date()));
const createdExactly = computed(() => formatExactTimestamp(props.generation.created_at));
</script>

<template>
    <div
        :data-highlighted="highlighted ? 'true' : undefined"
        :class="[
            'flex flex-col gap-2 px-4 py-4 group-first:rounded-t-[calc(var(--radius-xl)-1px)] group-last:rounded-b-[calc(var(--radius-xl)-1px)] sm:grid sm:grid-cols-[6.5rem_minmax(0,1fr)_auto] sm:items-start sm:gap-x-4 sm:px-5',
            highlighted && 'bg-sky-50/70 shadow-[inset_4px_0_0_var(--color-sky-600)]',
        ]"
    >
        <div class="flex items-center justify-between gap-3 sm:contents">
            <Badge :tone="STATUS_TONES[generation.status]" class="sm:col-start-1 sm:row-start-1 sm:justify-self-start">{{
                generation.status_label
            }}</Badge>
            <div class="flex shrink-0 items-center gap-2 sm:col-start-3 sm:row-start-1 sm:flex-col sm:items-end sm:gap-1">
                <time :datetime="generation.created_at" :title="createdExactly" class="text-sm whitespace-nowrap text-zinc-500">{{
                    createdAgo
                }}</time>
                <span v-if="highlighted" class="text-xs font-medium whitespace-nowrap text-sky-800">Just submitted</span>
            </div>
        </div>

        <div class="flex min-w-0 flex-col gap-1 sm:col-start-2 sm:row-start-1">
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
            <p data-estimate class="text-sm text-zinc-600">
                Est.
                <AmountRange :min="generation.user_estimate_nanodollars.min" :max="generation.user_estimate_nanodollars.max" />
            </p>
        </div>
    </div>
</template>
