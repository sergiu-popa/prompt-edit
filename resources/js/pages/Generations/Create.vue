<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useId } from 'vue';

import EstimatePanel from '@/components/estimate/EstimatePanel.vue';
import PromptField from '@/components/generation/PromptField.vue';
import ReferenceImagesField from '@/components/generation/ReferenceImagesField.vue';
import SubmitGenerationArea from '@/components/generation/SubmitGenerationArea.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import RadioGroup from '@/components/ui/RadioGroup.vue';
import { useFocusFirstInvalidField } from '@/composables/useFocusFirstInvalidField';
import { useGenerationForm } from '@/composables/useGenerationForm';
import { QUALITY_OPTIONS, SIZE_OPTIONS } from '@/lib/imageOptions';
import AppLayout from '@/layouts/AppLayout.vue';
import type { GenerationInputLimits } from '@/types/generation';
import type { PricingTable } from '@/types/pricing';

const props = defineProps<{
    pricing: PricingTable;
    limits: GenerationInputLimits;
    /** False until Pixelforge is configured (D-40): the page estimates, but Generate stays closed. */
    // eslint-disable-next-line vue/prop-name-casing -- named as the server sends it; Vue camelizes kebab-case only
    can_submit: boolean;
}>();

const formId = useId();
const focusFirstInvalidField = useFocusFirstInvalidField('formElement');

const { form, estimate, referenceImageCount, perReferenceNanodollars, isSubmittable, rowErrors, formError, submit } = useGenerationForm({
    pricing: () => props.pricing,
    limits: () => props.limits,
    canSubmit: () => props.can_submit,
    onRejected: () => focusFirstInvalidField(),
});
</script>

<template>
    <Head title="Generate" />

    <AppLayout>
        <div class="flex flex-col gap-8">
            <PageHeader
                title="New image"
                description="Describe the image, choose a size and quality, and see the price before you generate."
            />

            <!--
                Mobile: one column, the form, the estimate, then Generate, so the price comes first.
                From `lg`: the form and Generate on the left, the estimate kept in view on the right.
            -->
            <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_30rem] lg:grid-rows-[auto_1fr] lg:items-start">
                <form :id="formId" ref="formElement" novalidate class="flex min-w-0 flex-col gap-8" @submit.prevent="submit">
                    <PromptField v-model="form.prompt" :max-characters="limits.max_prompt_characters" :server-error="form.errors.prompt" />
                    <RadioGroup v-model="form.size" legend="Size" :options="SIZE_OPTIONS" :error="form.errors.size" />
                    <RadioGroup v-model="form.quality" legend="Quality" :options="QUALITY_OPTIONS" :error="form.errors.quality" />
                    <ReferenceImagesField
                        v-model="form.reference_images"
                        :max="limits.max_reference_images"
                        :per-reference-nanodollars="perReferenceNanodollars"
                        :error="form.errors.reference_images"
                        :row-errors="rowErrors"
                    />
                </form>

                <aside class="min-w-0 lg:sticky lg:top-6 lg:col-start-2 lg:row-span-2 lg:row-start-1">
                    <EstimatePanel
                        :estimate="estimate"
                        :quality="form.quality"
                        :size="form.size"
                        :reference-image-count="referenceImageCount"
                    />
                </aside>

                <SubmitGenerationArea
                    class="lg:col-start-1 lg:row-start-2"
                    :form-id="formId"
                    :disabled="!isSubmittable"
                    :busy="form.processing"
                    :error="formError"
                    :unavailable="!can_submit"
                />
            </div>
        </div>
    </AppLayout>
</template>
