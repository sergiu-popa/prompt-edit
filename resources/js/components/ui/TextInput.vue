<script setup lang="ts">
const model = defineModel<string>({ default: '' });

withDefaults(defineProps<{ invalid?: boolean }>(), { invalid: false });
</script>

<template>
    <input
        v-model="model"
        :aria-invalid="invalid ? 'true' : undefined"
        :class="[
            'block h-10 w-full min-w-0 rounded-lg border bg-white px-3 text-sm text-zinc-900 shadow-xs placeholder:text-zinc-400',
            'focus-visible:outline-2 focus-visible:outline-offset-2',
            invalid ? 'border-red-500 focus-visible:outline-red-500' : 'border-zinc-200 focus-visible:outline-zinc-900',
        ]"
    />
</template>
