<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import Button from '@/components/ui/Button.vue';
import Notice from '@/components/ui/Notice.vue';
import { paths } from '@/lib/paths';
import type { NavigationLink } from '@/types/navigation';

defineSlots<{
    default?(): unknown;
}>();

const page = usePage();
const userName = computed(() => page.props.auth.user?.name ?? '');
const notice = computed(() => page.flash.notice);

const STATIC_NAV_ITEMS: readonly NavigationLink[] = [
    { label: 'Generate', href: paths.dashboard },
    { label: 'History', href: paths.generations },
];

/** The operator's links come from the server, which sends them only to operators (D-63). */
const navItems = computed(() => [...STATIC_NAV_ITEMS, ...page.props.operator_navigation]);

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
            Mobile: a grid of two rows. The brand, the name (truncated, so it never pushes Sign out
            off the row) and Sign out share the first; the nav has the second to itself.
            From `sm`: one flex row of brand, nav, then the name and Sign out on the right.
            The DOM order is the desktop order, so the tab order is the same at every width.
        -->
        <header class="border-b border-zinc-200 bg-white">
            <div
                class="mx-auto grid max-w-5xl grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 px-4 py-3 sm:flex sm:gap-x-6 sm:px-6"
            >
                <Link :href="paths.dashboard" class="text-sm font-semibold tracking-tight text-zinc-900">PromptEdit</Link>
                <!-- On a phone the nav is pulled left so its first label lines up with the brand. -->
                <nav aria-label="Main" class="col-span-3 row-start-2 -ml-2.5 flex items-center gap-1 text-sm sm:ml-0">
                    <Link
                        v-for="item in navItems"
                        :key="item.href"
                        :href="item.href"
                        :aria-current="currentPath === item.href ? 'page' : undefined"
                        class="rounded-md px-2.5 py-1.5 text-zinc-600 transition-colors hover:bg-zinc-50 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 aria-[current=page]:bg-zinc-100 aria-[current=page]:font-medium aria-[current=page]:text-zinc-900"
                        >{{ item.label }}</Link
                    >
                </nav>
                <p class="col-start-2 row-start-1 min-w-0 truncate text-right text-sm text-zinc-600 sm:ml-auto sm:max-w-64 sm:text-left">
                    {{ userName }}
                </p>
                <Button variant="secondary" class="col-start-3 row-start-1" :busy="isSigningOut" busy-label="Signing out…" @click="signOut"
                    >Sign out</Button
                >
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
            <Notice v-if="notice" :message="notice" class="mb-6" />
            <slot />
        </main>
    </div>
</template>
