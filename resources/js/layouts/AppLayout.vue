<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import Button from '@/components/ui/Button.vue';
import { paths } from '@/lib/paths';

const slots = defineSlots<{
    default?(): unknown;
    nav?(): unknown;
}>();

const page = usePage();
const userName = computed(() => page.props.auth.user?.name ?? '');

function signOut(): void {
    router.post(paths.logout);
}
</script>

<template>
    <div class="min-h-screen bg-zinc-50">
        <!--
            Mobile: the brand and Sign out share the first row and the name wraps below.
            From `sm`: brand, nav, then the name and Sign out on the right.
        -->
        <header class="border-b border-zinc-200 bg-white">
            <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3 sm:px-6">
                <Link :href="paths.dashboard" class="order-1 text-sm font-semibold tracking-tight text-zinc-900">PromptEdit</Link>
                <nav v-if="slots.nav" class="order-4 flex w-full items-center gap-4 text-sm sm:order-2 sm:w-auto">
                    <slot name="nav" />
                </nav>
                <p class="order-3 w-full min-w-0 truncate text-sm text-zinc-600 sm:ml-auto sm:w-auto sm:max-w-64">{{ userName }}</p>
                <Button variant="secondary" class="order-2 ml-auto sm:order-4 sm:ml-0" @click="signOut">Sign out</Button>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
            <slot />
        </main>
    </div>
</template>
