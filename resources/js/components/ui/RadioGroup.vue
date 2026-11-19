<script setup lang="ts" generic="T extends string">
import { computed, useId } from 'vue';

import FieldError from '@/components/ui/FieldError.vue';

/**
 * A single-choice group of native radios styled as segments. Native radios give the
 * keyboard behaviour for free: Tab enters the group, the arrow keys move the choice.
 * An error describes the group and marks the checked radio, where Tab lands, invalid.
 */
const props = defineProps<{
    legend: string;
    options: readonly { value: T; label: string; detail?: string }[];
    error?: string;
}>();

const model = defineModel<T>({ required: true });

const name = useId();
const errorId = computed(() => `${name}-error`);
</script>

<template>
    <fieldset class="flex min-w-0 flex-col gap-2" :aria-describedby="props.error ? errorId : undefined">
        <legend class="mb-2 text-sm font-medium text-zinc-800">{{ legend }}</legend>
        <div class="flex flex-wrap gap-1 rounded-lg bg-zinc-100 p-1">
            <label
                v-for="option in options"
                :key="option.value"
                :class="[
                    'flex min-w-0 flex-1 cursor-pointer flex-col items-center rounded-md px-3 py-1.5 text-center text-sm text-zinc-600 transition-colors',
                    'hover:text-zinc-900',
                    'has-checked:bg-white has-checked:font-medium has-checked:text-zinc-900 has-checked:shadow-xs',
                    'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-zinc-900',
                ]"
            >
                <input
                    v-model="model"
                    type="radio"
                    :name="name"
                    :value="option.value"
                    :aria-invalid="props.error && model === option.value ? 'true' : undefined"
                    class="sr-only"
                />
                <span>{{ option.label }}</span>
                <span v-if="option.detail" class="text-xs font-normal text-zinc-500">{{ option.detail }}</span>
            </label>
        </div>
        <FieldError v-if="error" :id="errorId" :message="error" />
    </fieldset>
</template>
