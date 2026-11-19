<script setup lang="ts">
import Button from '@/components/ui/Button.vue';

/**
 * Generate's submit button. It sits outside the form in the DOM, after the estimate,
 * so a phone user reads the price before reaching it; `form` ties it to the form.
 * Full width on a phone, right-aligned from `sm`.
 */
defineProps<{
    /** The id of the form the button submits. */
    formId: string;
    disabled: boolean;
    busy: boolean;
}>();
</script>

<template>
    <div data-submit-area class="flex flex-col gap-3 sm:items-end">
        <Button type="submit" :form="formId" class="w-full sm:w-auto sm:min-w-40" :disabled="disabled" :busy="busy" busy-label="Generating…"
            >Generate</Button
        >
    </div>
</template>
