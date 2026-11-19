<script setup lang="ts">
import { computed, useId } from 'vue';

import FieldError from '@/components/ui/FieldError.vue';

const props = defineProps<{
    label: string;
    hint?: string;
    error?: string;
    /** Defaults to a generated id, unique on the page. */
    id?: string;
}>();

const slots = defineSlots<{
    /** Bind these to the input: `id`, `aria-describedby` and the invalid flag. */
    default(props: { id: string; describedBy: string | undefined; invalid: boolean }): unknown;
    /** Right-aligned on the label row, such as a counter; it describes the input first. */
    aside?(): unknown;
}>();

const generatedId = useId();
const fieldId = computed(() => props.id ?? generatedId);
const hintId = computed(() => `${fieldId.value}-hint`);
const errorId = computed(() => `${fieldId.value}-error`);
const asideId = computed(() => `${fieldId.value}-aside`);

const slotProps = computed(() => {
    const describedBy = [slots.aside ? asideId.value : null, props.hint ? hintId.value : null, props.error ? errorId.value : null]
        .filter((id) => id !== null)
        .join(' ');

    return { id: fieldId.value, describedBy: describedBy === '' ? undefined : describedBy, invalid: Boolean(props.error) };
});
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex items-baseline justify-between gap-3">
            <label :for="fieldId" class="text-sm font-medium text-zinc-800">{{ label }}</label>
            <span v-if="slots.aside" :id="asideId" class="text-sm text-zinc-500 tabular-nums"><slot name="aside" /></span>
        </div>
        <slot v-bind="slotProps" />
        <p v-if="hint" :id="hintId" class="text-sm text-zinc-500">{{ hint }}</p>
        <FieldError v-if="error" :id="errorId" :message="error" />
    </div>
</template>
