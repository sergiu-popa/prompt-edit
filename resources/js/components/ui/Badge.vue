<script setup lang="ts">
/**
 * A short label, such as a line's exact or estimated flag or a generation's status. Every tone has the same size
 * and sits on the surrounding text's baseline, so badges line up wherever they appear.
 */
withDefaults(defineProps<{ tone?: 'neutral' | 'info' | 'success' | 'warning' | 'danger' }>(), { tone: 'neutral' });

defineSlots<{ default(): unknown }>();

const toneClasses = {
    neutral: 'bg-zinc-50 text-zinc-700 ring-zinc-500/20',
    info: 'bg-sky-50 text-sky-800 ring-sky-600/20',
    success: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    warning: 'bg-amber-50 text-amber-800 ring-amber-600/25',
    danger: 'bg-red-50 text-red-700 ring-red-600/20',
} as const;
</script>

<template>
    <span
        :data-tone="tone"
        :class="[
            'inline-flex items-center rounded-md px-1.5 align-baseline text-xs leading-5 font-medium whitespace-nowrap ring-1 ring-inset',
            toneClasses[tone],
        ]"
        ><slot
    /></span>
</template>
