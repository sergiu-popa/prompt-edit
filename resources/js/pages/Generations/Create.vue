<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import type { GenerationInputLimits } from '@/types/generation';
import type { PricingTable } from '@/types/pricing';

defineProps<{
    pricing: PricingTable;
    limits: GenerationInputLimits;
}>();
</script>

<template>
    <Head title="Generate" />

    <AppLayout>
        <h1 class="text-xl font-semibold tracking-tight">New image</h1>
    </AppLayout>
</template>
