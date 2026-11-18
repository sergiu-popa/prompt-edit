<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Welcome" />

    <main class="mx-auto flex min-h-screen max-w-xl flex-col justify-center gap-4 px-6">
        <p class="text-sm font-medium text-zinc-500">PromptEdit</p>
        <h1 class="text-3xl font-semibold tracking-tight">Generate images with Pixelforge</h1>
        <p class="text-zinc-600">Write a prompt, see what it will cost, and watch it render. You only pay for images that succeed.</p>
    </main>
</template>
