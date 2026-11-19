<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold tracking-tight">You're signed in.</h1>
            <p class="text-sm text-zinc-600">Image generation is coming next.</p>
        </div>
    </AppLayout>
</template>
