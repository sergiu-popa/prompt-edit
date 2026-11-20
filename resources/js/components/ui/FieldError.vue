<script setup lang="ts">
/**
 * A field's error message with its warning icon. The `id` is what the input's or
 * group's `aria-describedby` points at, so the message is read with the field.
 */
defineProps<{
    id: string;
    message: string;
}>();
</script>

<template>
    <p :id="id" class="flex items-start gap-1.5 text-sm text-red-600">
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
        <span>{{ message }}</span>
    </p>
</template>
