<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { reactive } from 'vue';

import EstimatePanel from '@/components/estimate/EstimatePanel.vue';
import PromptField from '@/components/generation/PromptField.vue';
import ReferenceImagesField from '@/components/generation/ReferenceImagesField.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import RadioGroup from '@/components/ui/RadioGroup.vue';
import { useEstimate } from '@/composables/useEstimate';
import { DEFAULT_QUALITY, DEFAULT_SIZE, QUALITY_OPTIONS, SIZE_OPTIONS } from '@/lib/imageOptions';
import AppLayout from '@/layouts/AppLayout.vue';
import type { GenerationFormInput, GenerationInputLimits } from '@/types/generation';
import type { PricingTable } from '@/types/pricing';

const props = defineProps<{
    pricing: PricingTable;
    limits: GenerationInputLimits;
}>();

const form = reactive<GenerationFormInput>({ prompt: '', size: DEFAULT_SIZE, quality: DEFAULT_QUALITY, reference_images: [] });

const { estimate, referenceImageCount, perReferenceNanodollars } = useEstimate(() => props.pricing, form);
</script>

<template>
    <Head title="Generate" />

    <AppLayout>
        <div class="flex flex-col gap-8">
            <PageHeader
                title="New image"
                description="Describe the image, choose a size and quality, and see the price before you generate."
            />

            <!-- Mobile: one column, the estimate after the form. From `lg`: two columns, the estimate kept in view. -->
            <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_28rem] lg:items-start">
                <!-- PE-011 wires the submit; until then Enter in a field must not reload the page. -->
                <form novalidate class="flex min-w-0 flex-col gap-8" @submit.prevent>
                    <PromptField v-model="form.prompt" :max-characters="limits.max_prompt_characters" />
                    <RadioGroup v-model="form.size" legend="Size" :options="SIZE_OPTIONS" />
                    <RadioGroup v-model="form.quality" legend="Quality" :options="QUALITY_OPTIONS" />
                    <ReferenceImagesField
                        v-model="form.reference_images"
                        :max="limits.max_reference_images"
                        :per-reference-nanodollars="perReferenceNanodollars"
                    />
                </form>

                <aside class="min-w-0 lg:sticky lg:top-6">
                    <EstimatePanel
                        :estimate="estimate"
                        :quality="form.quality"
                        :size="form.size"
                        :reference-image-count="referenceImageCount"
                    />
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
