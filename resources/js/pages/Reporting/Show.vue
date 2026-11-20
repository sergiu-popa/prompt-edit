<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import PageHeader from '@/components/ui/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
</script>

<template>
    <Head title="Report" />

    <AppLayout>
        <div class="flex flex-col gap-8">
            <PageHeader title="Operator report" />
        </div>
    </AppLayout>
</template>
