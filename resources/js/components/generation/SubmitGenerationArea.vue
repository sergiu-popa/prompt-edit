<script setup lang="ts">
import { useId } from 'vue';

import Button from '@/components/ui/Button.vue';
import FieldError from '@/components/ui/FieldError.vue';

/**
 * Generate's submit button, and the form-level error beside it (the throttle, or a
 * stale form), announced as an alert. It sits outside the form in the DOM, after the
 * estimate, so a phone user reads the price before reaching it; `form` ties it to the
 * form. Full width on a phone, right-aligned from `sm`.
 */
defineProps<{
    /** The id of the form the button submits. */
    formId: string;
    disabled: boolean;
    busy: boolean;
    /** An error that belongs to no field. */
    error?: string;
}>();

const errorId = useId();
</script>

<template>
    <div data-submit-area class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
        <div v-if="error" role="alert" class="min-w-0">
            <FieldError :id="errorId" :message="error" />
        </div>
        <Button
            type="submit"
            :form="formId"
            class="w-full shrink-0 sm:w-auto sm:min-w-40"
            :disabled="disabled"
            :busy="busy"
            busy-label="Generating…"
            :aria-describedby="error ? errorId : undefined"
            >Generate</Button
        >
    </div>
</template>
