<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import type { PaginationLinks, PaginationMeta } from '@/types/pagination';

/**
 * Previous, "Page x of y" and Next. A missing link (on the first or last page) is a
 * disabled label, so the controls keep their places.
 */
defineProps<{
    meta: PaginationMeta;
    links: PaginationLinks;
}>();

const LINK_CLASSES =
    'inline-flex h-9 items-center rounded-lg border border-zinc-200 bg-white px-3 text-sm font-medium text-zinc-900 shadow-xs hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900';
const DISABLED_CLASSES =
    'inline-flex h-9 cursor-not-allowed items-center rounded-lg border border-zinc-200 px-3 text-sm font-medium text-zinc-400';
</script>

<template>
    <nav aria-label="Pagination" class="flex items-center justify-between gap-3">
        <Link v-if="links.prev" :href="links.prev" rel="prev" :class="LINK_CLASSES">Previous</Link>
        <span v-else aria-disabled="true" :class="DISABLED_CLASSES">Previous</span>

        <p class="text-sm text-zinc-600 tabular-nums">Page {{ meta.current_page }} of {{ meta.last_page }}</p>

        <Link v-if="links.next" :href="links.next" rel="next" :class="LINK_CLASSES">Next</Link>
        <span v-else aria-disabled="true" :class="DISABLED_CLASSES">Next</span>
    </nav>
</template>
