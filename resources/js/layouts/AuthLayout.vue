<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import { paths } from '@/lib/paths';

defineProps<{ title: string }>();

const slots = defineSlots<{
    default?(): unknown;
    footer?(): unknown;
}>();
</script>

<template>
    <main class="flex min-h-screen flex-col items-center justify-center gap-6 bg-zinc-50 px-4 py-12">
        <Link :href="paths.home" class="text-sm font-semibold tracking-tight text-zinc-900">PromptEdit</Link>

        <div class="flex w-full max-w-sm flex-col gap-6 rounded-xl border border-zinc-200 bg-white p-6 shadow-xs sm:p-8">
            <h1 class="text-lg font-semibold tracking-tight">{{ title }}</h1>
            <slot />
            <p v-if="slots.footer" class="text-sm text-zinc-600">
                <slot name="footer" />
            </p>
        </div>
    </main>
</template>
