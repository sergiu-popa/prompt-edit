<script setup lang="ts">
/** A one-off message from the server, such as an expired session's, read out politely. */
defineProps<{ message: string }>();
</script>

<template>
    <p role="status" class="rounded-lg bg-zinc-100 px-3 py-2 text-sm break-words text-zinc-700">{{ message }}</p>
</template>
