<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, useTemplateRef, watch } from 'vue';

import { formatBalance, formatUsd, formatUsdExact } from '@/lib/money';

/**
 * An amount shown to 4 decimals, with its exact 9-decimal value one hover, focus or
 * tap away (a toggletip). The exact value always describes the button, so it is
 * never hover-only. Hidden, the tip is `display: none`, so it never widens the page.
 *
 * Hover follows a mouse only: touch browsers fire hover events around a tap (Chromium
 * sends `mouseleave` right after it), so a tap or pen press toggles the tip instead, and
 * a press anywhere else, blur or Escape closes it.
 *
 * Hover covers the amount and the tip together, with no gap between them, so the mouse
 * can move onto the exact value without losing it, and Escape closes an open tip wherever
 * focus is (WCAG 1.4.13).
 */
const props = withDefaults(
    defineProps<{
        nanodollars: number;
        /** Which edge of the amount the tip lines up with. */
        align?: 'start' | 'end';
        /** A balance, so a negative amount rounds away from zero instead of half away. */
        balance?: boolean;
    }>(),
    { align: 'start', balance: false },
);

const tooltipId = useId();
const isRevealed = ref(false);
const root = useTemplateRef<HTMLElement>('root');

/** The pointer pressing the button right now, if any, so its focus and click are read as a tap. */
const pressingPointerType = ref<string | null>(null);

const displayed = computed(() => (props.balance ? formatBalance(props.nanodollars) : formatUsd(props.nanodollars)));
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

function concealOnEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        conceal();
    }
}

function stopListening(): void {
    document.removeEventListener('pointerdown', concealOnPressElsewhere);
    document.removeEventListener('keydown', concealOnEscape);
}

watch(isRevealed, (revealed) => {
    if (revealed) {
        document.addEventListener('pointerdown', concealOnPressElsewhere);
        document.addEventListener('keydown', concealOnEscape);
    } else {
        stopListening();
    }
});

onBeforeUnmount(stopListening);
</script>

<template>
    <span ref="root" class="relative inline-block" @pointerenter="onPointerEnter" @pointerleave="onPointerLeave">
        <button
            type="button"
            :aria-describedby="tooltipId"
            class="cursor-help rounded-sm tabular-nums underline decoration-zinc-300/70 decoration-dotted underline-offset-4 transition-colors hover:decoration-zinc-500 focus-visible:decoration-zinc-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900"
            @pointerdown="onPointerDown"
            @pointercancel="onPointerCancel"
            @focus="onFocus"
            @blur="onBlur"
            @click="onClick"
        >
            {{ displayed }}
        </button>
        <!-- The padding, not a margin, spaces the tip from the amount, so hover has no gap to cross. -->
        <span
            v-show="isRevealed"
            :id="tooltipId"
            role="tooltip"
            :class="['absolute bottom-full z-10 pb-1.5', align === 'end' ? 'right-0' : 'left-0']"
            ><span class="block rounded-md bg-zinc-900 px-2 py-1 text-xs font-normal whitespace-nowrap text-white tabular-nums shadow-sm">{{
                exact
            }}</span></span
        >
    </span>
</template>
