<script setup lang="ts">
/**
 * Never takes a `maxlength`: it counts UTF-16 units, not the code points Pixelforge
 * counts, and it would truncate a paste. A limit is shown by the field instead.
 */
const model = defineModel<string>({ default: '' });

withDefaults(defineProps<{ invalid?: boolean }>(), { invalid: false });
</script>

<template>
    <textarea
        v-model="model"
        :aria-invalid="invalid ? 'true' : undefined"
        :class="[
            'block min-h-28 w-full min-w-0 resize-y rounded-lg border bg-white px-3 py-2 text-sm text-zinc-900 shadow-xs placeholder:text-zinc-400',
            'focus-visible:outline-2 focus-visible:outline-offset-2',
            invalid ? 'border-red-500 focus-visible:outline-red-500' : 'border-zinc-200 focus-visible:outline-zinc-900',
        ]"
    />
</template>
