<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import PageHeader from '@/components/ui/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { OperatorReport, ReportLimits, ReportRange } from '@/types/report';

defineProps<{
    range: ReportRange;
    limits: ReportLimits;
    report: OperatorReport;
}>();
</script>

<template>
    <Head title="Report" />

    <AppLayout>
        <div class="flex flex-col gap-8">
            <PageHeader
                title="Operator report"
                :description="`Spend, Pixelforge cost and profit per UTC day, from ${range.start_date} to ${range.end_date}.`"
            />
        </div>
    </AppLayout>
</template>
