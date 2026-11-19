<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import Button from '@/components/ui/Button.vue';
import { paths } from '@/lib/paths';

defineSlots<{
    default?(): unknown;
}>();

const page = usePage();
const userName = computed(() => page.props.auth.user?.name ?? '');

/** PE-011 adds History here. */
const navItems = [{ label: 'Generate', href: paths.dashboard }] as const;

/** Inertia's page URL is the path plus any query string or fragment. */
const currentPath = computed(() => page.url.split(/[?#]/, 1)[0]);

/** Set before the POST, so a second click can't send the old CSRF token after the first signs out. */
const isSigningOut = ref(false);

function signOut(): void {
    if (isSigningOut.value) {
        return;
    }

    isSigningOut.value = true;
    router.post(paths.logout, {}, { onFinish: () => (isSigningOut.value = false) });
}
</script>

<template>
    <div class="min-h-screen bg-zinc-50">
        <!--
            Mobile: the brand and Sign out share the first row, then the nav and the name wrap below.
            From `sm`: brand, nav, then the name and Sign out on the right.
        -->
        <header class="border-b border-zinc-200 bg-white">
            <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3 sm:px-6">
                <Link :href="paths.dashboard" class="order-1 text-sm font-semibold tracking-tight text-zinc-900">PromptEdit</Link>
                <nav aria-label="Main" class="order-3 flex w-full items-center gap-4 text-sm sm:order-2 sm:w-auto">
                    <Link
                        v-for="item in navItems"
                        :key="item.href"
                        :href="item.href"
                        :aria-current="currentPath === item.href ? 'page' : undefined"
                        class="rounded-sm text-zinc-600 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 aria-[current=page]:font-medium aria-[current=page]:text-zinc-900"
                        >{{ item.label }}</Link
                    >
                </nav>
                <p class="order-4 w-full min-w-0 truncate text-sm text-zinc-600 sm:order-3 sm:ml-auto sm:w-auto sm:max-w-64">
                    {{ userName }}
                </p>
                <Button
                    variant="secondary"
                    class="order-2 ml-auto sm:order-4 sm:ml-0"
                    :busy="isSigningOut"
                    busy-label="Signing out…"
                    @click="signOut"
                    >Sign out</Button
                >
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
            <slot />
        </main>
    </div>
</template>
