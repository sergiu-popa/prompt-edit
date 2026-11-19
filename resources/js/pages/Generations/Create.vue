<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, useId, watch } from 'vue';

import EstimatePanel from '@/components/estimate/EstimatePanel.vue';
import PromptField from '@/components/generation/PromptField.vue';
import ReferenceImagesField from '@/components/generation/ReferenceImagesField.vue';
import SubmitGenerationArea from '@/components/generation/SubmitGenerationArea.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import RadioGroup from '@/components/ui/RadioGroup.vue';
import { useEstimate } from '@/composables/useEstimate';
import { useFocusFirstInvalidField } from '@/composables/useFocusFirstInvalidField';
import { canSubmitGeneration } from '@/lib/generationForm';
import { DEFAULT_QUALITY, DEFAULT_SIZE, QUALITY_OPTIONS, SIZE_OPTIONS } from '@/lib/imageOptions';
import { paths } from '@/lib/paths';
import { countedReferenceImages, referenceRowErrors, submittedRowIndexes } from '@/lib/referenceImages';
import AppLayout from '@/layouts/AppLayout.vue';
import type { GenerationFormInput, GenerationInputLimits, GenerationSubmission } from '@/types/generation';
import type { PricingTable } from '@/types/pricing';

const props = defineProps<{
    pricing: PricingTable;
    limits: GenerationInputLimits;
}>();

/** No remember key: going Back to Generate starts a new form, with a new token. */
const form = useForm<GenerationFormInput>({ prompt: '', size: DEFAULT_SIZE, quality: DEFAULT_QUALITY, reference_images: [] });

/**
 * Names this visit's form submission (D-38). A validation or throttle response keeps the
 * page mounted, so a resubmit reuses it safely: nothing was created.
 */
const submissionToken = crypto.randomUUID();

const formId = useId();
const focusFirstInvalidField = useFocusFirstInvalidField('formElement');

const { estimate, referenceImageCount, perReferenceNanodollars } = useEstimate(() => props.pricing, form);

const canSubmit = computed(() => canSubmitGeneration(form, props.limits, estimate.value !== null));

/** Where each submitted reference came from, so `reference_images.N` errors land on their rows. */
const submittedRows = ref<number[]>([]);

const fieldErrors = computed<Record<string, string | undefined>>(() => form.errors);
const rowErrors = computed(() => referenceRowErrors(submittedRows.value, fieldErrors.value));

/** The throttle and a stale form belong to no field, so they come from the page's errors. */
const page = usePage();
const formError = computed(() => (form.processing ? undefined : (page.props.errors.submission ?? page.props.errors.submission_token)));

/** A field's server error goes as soon as that field changes. */
watch(
    () => form.prompt,
    () => form.clearErrors('prompt'),
);
watch(
    () => form.size,
    () => form.clearErrors('size'),
);
watch(
    () => form.quality,
    () => form.clearErrors('quality'),
);
watch(
    () => form.reference_images,
    () => {
        const referenceKeys = Object.keys(form.errors).filter((key) => key === 'reference_images' || key.startsWith('reference_images.'));

        form.clearErrors(...(referenceKeys as (keyof GenerationFormInput)[]));
    },
);

function submit(): void {
    // The disabled button stops clicks and Enter in a field; this stops a second submit before it re-renders.
    if (!canSubmit.value || form.processing) {
        return;
    }

    submittedRows.value = submittedRowIndexes(form.reference_images);

    form.transform((data): GenerationSubmission => ({
        prompt: data.prompt,
        size: data.size,
        quality: data.quality,
        reference_images: countedReferenceImages(data.reference_images),
        submission_token: submissionToken,
    })).post(paths.generations, {
        onError: () => focusFirstInvalidField(),
    });
}
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
            <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_28rem] lg:grid-rows-[auto_1fr] lg:items-start">
                <form :id="formId" ref="formElement" novalidate class="flex min-w-0 flex-col gap-8" @submit.prevent="submit">
                    <PromptField v-model="form.prompt" :max-characters="limits.max_prompt_characters" :server-error="form.errors.prompt" />
                    <RadioGroup v-model="form.size" legend="Size" :options="SIZE_OPTIONS" :error="form.errors.size" />
                    <RadioGroup v-model="form.quality" legend="Quality" :options="QUALITY_OPTIONS" :error="form.errors.quality" />
                    <ReferenceImagesField
                        v-model="form.reference_images"
                        :max="limits.max_reference_images"
                        :per-reference-nanodollars="perReferenceNanodollars"
                        :error="fieldErrors.reference_images"
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
                    :disabled="!canSubmit"
                    :busy="form.processing"
                    :error="formError"
                />
            </div>
        </div>
    </AppLayout>
</template>
