<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, useTemplateRef, watch } from 'vue';

import { formatUsd, formatUsdExact } from '@/lib/money';

/**
 * An amount shown to 4 decimals, with its exact 9-decimal value one hover, focus or
 * tap away (a toggletip). The exact value always describes the button, so it is
 * never hover-only. Hidden, the tip is `display: none`, so it never widens the page.
 *
 * Hover follows a mouse only: touch browsers fire hover events around a tap (Chromium
 * sends `mouseleave` right after it), so a tap or pen press toggles the tip instead, and
 * a press anywhere else, blur or Escape closes it.
 */
const props = withDefaults(
    defineProps<{
        nanodollars: number;
        /** Which edge of the amount the tip lines up with. */
        align?: 'start' | 'end';
    }>(),
    { align: 'start' },
);

const tooltipId = useId();
const isRevealed = ref(false);
const root = useTemplateRef<HTMLElement>('root');

/** The pointer pressing the button right now, if any, so its focus and click are read as a tap. */
const pressingPointerType = ref<string | null>(null);

const displayed = computed(() => formatUsd(props.nanodollars));
const exact = computed(() => formatUsdExact(props.nanodollars));

const isTapping = computed(() => pressingPointerType.value !== null && pressingPointerType.value !== 'mouse');

function reveal(): void {
    isRevealed.value = true;
}

function conceal(): void {
    isRevealed.value = false;
}

function onPointerEnter(event: PointerEvent): void {
    if (event.pointerType === 'mouse') {
        reveal();
    }
}

function onPointerLeave(event: PointerEvent): void {
    if (event.pointerType === 'mouse') {
        conceal();
    }
}

function onPointerDown(event: PointerEvent): void {
    pressingPointerType.value = event.pointerType;
}

function onFocus(): void {
    if (!isTapping.value) {
        reveal();
    }
}

function onBlur(): void {
    pressingPointerType.value = null;
    conceal();
}

function onClick(): void {
    if (isTapping.value) {
        isRevealed.value = !isRevealed.value;
    } else {
        reveal();
    }

    pressingPointerType.value = null;
}

function onPointerCancel(): void {
    pressingPointerType.value = null;
}

function concealOnPressElsewhere(event: PointerEvent): void {
    if (!(event.target instanceof Node) || !root.value?.contains(event.target)) {
        conceal();
    }
}

watch(isRevealed, (revealed) => {
    if (revealed) {
        document.addEventListener('pointerdown', concealOnPressElsewhere);
    } else {
        document.removeEventListener('pointerdown', concealOnPressElsewhere);
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', concealOnPressElsewhere);
});
</script>

<template>
    <span ref="root" class="relative inline-block">
        <button
            type="button"
            :aria-describedby="tooltipId"
            class="cursor-help rounded-sm tabular-nums underline decoration-zinc-300 decoration-dotted underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900"
            @pointerenter="onPointerEnter"
            @pointerleave="onPointerLeave"
            @pointerdown="onPointerDown"
            @pointercancel="onPointerCancel"
            @focus="onFocus"
            @blur="onBlur"
            @click="onClick"
            @keydown.escape="conceal"
        >
            {{ displayed }}
        </button>
        <span
            v-show="isRevealed"
            :id="tooltipId"
            role="tooltip"
            :class="[
                'absolute bottom-full z-10 mb-1.5 rounded-md bg-zinc-900 px-2 py-1 text-xs font-normal whitespace-nowrap text-white tabular-nums shadow-sm',
                align === 'end' ? 'right-0' : 'left-0',
            ]"
            >{{ exact }}</span
        >
    </span>
</template>
