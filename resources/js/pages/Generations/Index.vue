<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import PageHeader from '@/components/ui/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
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
            <PageHeader title="History" description="Your generations, newest first." />
        </div>
    </AppLayout>
</template>
