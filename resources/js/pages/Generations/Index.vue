<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

import HistoryItem from '@/components/history/HistoryItem.vue';
import HistoryPagination from '@/components/history/HistoryPagination.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { paths } from '@/lib/paths';
import type { Generation } from '@/types/generation';
import type { Paginated } from '@/types/pagination';

defineProps<{
    generations: Paginated<Generation>;
}>();
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
                <li v-for="generation in generations.data" :key="generation.id" class="px-4 py-4 sm:px-5">
                    <HistoryItem :generation="generation" />
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
    </AppLayout>
</template>
