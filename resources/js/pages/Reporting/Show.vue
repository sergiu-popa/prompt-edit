<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

import ReportRangeForm from '@/components/report/ReportRangeForm.vue';
import ReportSummary from '@/components/report/ReportSummary.vue';
import ReportTable from '@/components/report/ReportTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { OperatorReport, ReportLimits, ReportRange, ReportTableRow } from '@/types/report';

const props = defineProps<{
    range: ReportRange;
    limits: ReportLimits;
    report: OperatorReport;
}>();

const dayRows = computed<ReportTableRow[]>(() => props.report.days.map((day) => ({ key: day.date, label: day.date, figures: day })));
const userRows = computed<ReportTableRow[]>(() =>
    props.report.users.map((user) => ({ key: user.user_id, label: user.email, figures: user })),
);
</script>

<template>
    <Head title="Report" />

    <AppLayout>
        <div class="flex flex-col gap-8">
            <PageHeader
                title="Operator report"
                :description="`Spend, Pixelforge cost and profit per UTC day, from ${range.start_date} to ${range.end_date}.`"
            />

            <ReportRangeForm :range="range" :limits="limits" />

            <ReportSummary :totals="report.totals" />

            <ReportTable
                caption="Per day (by Pixelforge completion date, UTC)"
                label-header="Date"
                :rows="dayRows"
                :totals="report.totals"
                note="Succeeded counts every generation Pixelforge billed, including any we failed locally and never charged."
            />

            <ReportTable caption="Per user" label-header="User" :rows="userRows" empty-text="No billed generations in this range." />
        </div>
    </AppLayout>
</template>
