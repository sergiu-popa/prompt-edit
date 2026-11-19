<script setup lang="ts">
import { computed, useId } from 'vue';

const props = defineProps<{
    label: string;
    hint?: string;
    error?: string;
    /** Defaults to a generated id, unique on the page. */
    id?: string;
}>();

defineSlots<{
    /** Bind these to the input: `id`, `aria-describedby` and the invalid flag. */
    default(props: { id: string; describedBy: string | undefined; invalid: boolean }): unknown;
}>();

const generatedId = useId();
const fieldId = computed(() => props.id ?? generatedId);
const hintId = computed(() => `${fieldId.value}-hint`);
const errorId = computed(() => `${fieldId.value}-error`);

const slotProps = computed(() => {
    const describedBy = [props.hint ? hintId.value : null, props.error ? errorId.value : null].filter((id) => id !== null).join(' ');

    return { id: fieldId.value, describedBy: describedBy === '' ? undefined : describedBy, invalid: Boolean(props.error) };
});
</script>

<template>
    <div class="flex flex-col gap-2">
        <label :for="fieldId" class="text-sm font-medium text-zinc-800">{{ label }}</label>
        <slot v-bind="slotProps" />
        <p v-if="hint" :id="hintId" class="text-sm text-zinc-500">{{ hint }}</p>
        <p v-if="error" :id="errorId" class="flex items-start gap-1.5 text-sm text-red-600">
            <svg
                class="mt-0.5 size-4 shrink-0"
                viewBox="0 0 16 16"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path d="M8 1.75 14.75 13.75H1.25Z" />
                <path d="M8 6.25v3.25" />
                <path d="M8 11.75v.01" />
            </svg>
            <span>{{ error }}</span>
        </p>
    </div>
</template>
