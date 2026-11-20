<script setup lang="ts">
/**
 * A page's title (its only h1), an optional one-line description, and actions on the
 * right from `sm`. On a phone the actions wrap under the title.
 */
defineProps<{
    title: string;
    description?: string;
}>();

const slots = defineSlots<{ actions?(): unknown }>();
</script>

<template>
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex min-w-0 flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900">{{ title }}</h1>
            <p v-if="description" class="text-sm text-zinc-600">{{ description }}</p>
        </div>
        <div v-if="slots.actions" data-actions class="flex shrink-0 items-center gap-3">
            <slot name="actions" />
        </div>
    </header>
</template>
