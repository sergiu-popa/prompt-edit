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

const navItems = [
    { label: 'Generate', href: paths.dashboard },
    { label: 'History', href: paths.generations },
] as const;

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
                <!-- On a phone the nav starts its own row, pulled left so the first label lines up with the brand. -->
                <nav aria-label="Main" class="order-3 -ml-2.5 flex w-full items-center gap-1 text-sm sm:order-2 sm:ml-0 sm:w-auto">
                    <Link
                        v-for="item in navItems"
                        :key="item.href"
                        :href="item.href"
                        :aria-current="currentPath === item.href ? 'page' : undefined"
                        class="rounded-md px-2.5 py-1.5 text-zinc-600 transition-colors hover:bg-zinc-50 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 aria-[current=page]:bg-zinc-100 aria-[current=page]:font-medium aria-[current=page]:text-zinc-900"
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
