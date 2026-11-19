<script setup lang="ts">
import type { Component } from 'vue';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        /** The element or component to render; anything but `button` gets no `type` or `disabled`. */
        as?: string | Component;
        type?: 'button' | 'submit';
        variant?: 'primary' | 'secondary';
        disabled?: boolean;
        busy?: boolean;
        /** Shown instead of the slot while busy. */
        busyLabel?: string;
    }>(),
    {
        as: 'button',
        type: 'button',
        variant: 'primary',
        disabled: false,
        busy: false,
        busyLabel: undefined,
    },
);

const emit = defineEmits<{ click: [event: MouseEvent] }>();

const isNativeButton = computed(() => props.as === 'button');
const isInert = computed(() => props.disabled || props.busy);

const variantClasses = {
    primary: 'bg-zinc-900 text-white hover:bg-zinc-800',
    secondary: 'border border-zinc-200 bg-white text-zinc-900 shadow-xs hover:bg-zinc-50',
} as const;

function onClick(event: MouseEvent): void {
    if (isInert.value) {
        event.preventDefault();

        return;
    }

    emit('click', event);
}
</script>

<template>
    <component
        :is="as"
        :type="isNativeButton ? type : undefined"
        :disabled="isNativeButton ? isInert : undefined"
        :aria-busy="busy ? 'true' : undefined"
        :class="[
            'inline-flex h-10 items-center justify-center gap-2 rounded-lg px-4 text-sm font-medium whitespace-nowrap transition-colors',
            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900',
            'disabled:cursor-not-allowed disabled:opacity-75 aria-busy:opacity-75',
            variantClasses[variant],
        ]"
        @click="onClick"
    >
        <svg v-if="busy" class="size-4 motion-safe:animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
        <template v-if="busy && busyLabel">{{ busyLabel }}</template>
        <slot v-else />
    </component>
</template>
