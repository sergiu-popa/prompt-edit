<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';

import HistoryItem from '@/components/history/HistoryItem.vue';
import HistoryPagination from '@/components/history/HistoryPagination.vue';
import LiveUpdatesNote from '@/components/history/LiveUpdatesNote.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useLiveUpdates } from '@/composables/useLiveUpdates';
import { useServerClock } from '@/composables/useServerClock';
import AppLayout from '@/layouts/AppLayout.vue';
import { paths } from '@/lib/paths';
import type { GenerationHistory, GenerationStatus } from '@/types/generation';

const props = defineProps<{
    generations: GenerationHistory;
}>();

/** The server's clock, re-aligned on every fresh response (not a Back), for elapsed and relative times. */
const now = useServerClock(() => props.generations.server_time);

const IN_PROGRESS: readonly GenerationStatus[] = ['pending', 'queued', 'processing'];

/**
 * Flashed by the submit's redirect only, so a reload or Back never highlights it again.
 * It is read once, because a live update's partial reload replaces the flash with an
 * empty one; a reload or a pagination click mounts the page anew.
 */
const highlightedId = usePage().flash.highlighted_generation_id;

/**
 * Polls our own server, never Pixelforge, while anything shown is in progress (D-11, D-41).
 * Each poll also refreshes the shared balance, so the header shows a settled charge or a
 * returned hold without a reload (PE-017 AC 3).
 */
const { isPaused } = useLiveUpdates({
    only: ['generations', 'credits'],
    active: () => props.generations.data.some((generation) => IN_PROGRESS.includes(generation.status)),
});
</script>

<template>
    <Head title="History" />

    <AppLayout>
        <div class="flex flex-col gap-8">
            <PageHeader title="History" description="Your generations, newest first.">
                <template #actions>
                    <Button :as="Link" :href="paths.dashboard">New image</Button>
                </template>
            </PageHeader>

            <ul v-if="generations.data.length > 0" class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white shadow-xs">
                <li v-for="generation in generations.data" :key="generation.id" class="group">
                    <HistoryItem :generation="generation" :highlighted="generation.id === highlightedId" :now="now" />
                </li>
            </ul>

            <div
                v-else-if="generations.meta.total === 0"
                class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-zinc-300 bg-white px-6 py-12 text-center"
            >
                <p class="text-base font-semibold text-zinc-900">No generations yet.</p>
                <p class="text-sm text-zinc-600">Write a prompt and see the price before you pay.</p>
                <Button :as="Link" :href="paths.dashboard" class="mt-4">Create your first image →</Button>
            </div>

            <div
                v-else
                class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-300 bg-white px-6 py-12 text-center"
            >
                <p class="text-sm text-zinc-600">Nothing on this page.</p>
                <Link
                    v-if="generations.links.first"
                    :href="generations.links.first"
                    class="rounded-sm text-sm font-medium text-zinc-900 underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900"
                    >Go to the first page</Link
                >
            </div>

            <HistoryPagination v-if="generations.meta.last_page > 1" :meta="generations.meta" :links="generations.links" />
        </div>

        <LiveUpdatesNote :paused="isPaused" />
    </AppLayout>
</template>
