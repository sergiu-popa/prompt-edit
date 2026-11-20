<script setup lang="ts">
/**
 * History's "paused" note, shown while live updates are retrying after a failed poll.
 * It floats at the bottom of the viewport, so the list never shifts. The status region
 * is always there and only its content changes, so screen readers announce it.
 */
defineProps<{ paused: boolean }>();
</script>

<template>
    <div role="status" class="pointer-events-none fixed inset-x-0 bottom-4 z-20 flex justify-center px-4 sm:justify-end sm:px-6">
        <p
            v-if="paused"
            class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 shadow-sm ring-1 ring-zinc-900/10"
        >
            <span aria-hidden="true">⟳</span> Live updates paused — retrying…
        </p>
    </div>
</template>
