<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import Button from '@/components/ui/Button.vue';
import { paths } from '@/lib/paths';

const page = usePage();
const isSignedIn = computed(() => page.props.auth.user !== null);
</script>

<template>
    <Head title="Welcome" />

    <main class="mx-auto flex min-h-screen max-w-xl flex-col justify-center gap-4 px-6">
        <p class="text-sm font-medium text-zinc-500">PromptEdit</p>
        <h1 class="text-3xl font-semibold tracking-tight">Generate images with Pixelforge</h1>
        <p class="text-zinc-600">Write a prompt, see what it will cost, and watch it render. You only pay for images that succeed.</p>

        <div class="flex flex-wrap gap-3 pt-2">
            <Button v-if="isSignedIn" :as="Link" :href="paths.dashboard">Open PromptEdit <span aria-hidden="true">→</span></Button>
            <template v-else>
                <Button :as="Link" :href="paths.login">Sign in</Button>
                <Button :as="Link" :href="paths.register" variant="secondary">Create account</Button>
            </template>
        </div>
    </main>
</template>
