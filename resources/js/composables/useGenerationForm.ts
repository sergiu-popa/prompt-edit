import type { InertiaForm } from '@inertiajs/vue3';
import { useForm, usePage } from '@inertiajs/vue3';
import type { ComputedRef, MaybeRefOrGetter } from 'vue';
import { computed, ref, toValue, watch } from 'vue';

import type { LiveEstimate } from '@/composables/useEstimate';
import { useEstimate } from '@/composables/useEstimate';
import { canSubmitGeneration } from '@/lib/generationForm';
import { DEFAULT_QUALITY, DEFAULT_SIZE } from '@/lib/imageOptions';
import { paths } from '@/lib/paths';
import { countedReferenceImages, referenceRowErrors, submittedRowIndexes } from '@/lib/referenceImages';
import type { GenerationFormInput, GenerationInputLimits, GenerationSubmission } from '@/types/generation';
import type { PricingTable } from '@/types/pricing';

export interface GenerationFormOptions {
    pricing: MaybeRefOrGetter<PricingTable>;
    limits: MaybeRefOrGetter<GenerationInputLimits>;
    /** False until Pixelforge is configured (D-40). */
    canSubmit: MaybeRefOrGetter<boolean>;
    /** Called once the server has rejected a submission and its errors are set. */
    onRejected: () => void;
}

export interface GenerationForm extends LiveEstimate {
    form: InertiaForm<GenerationFormInput>;
    /** Whether Generate may submit now (lib/generationForm.ts's rules, and the page is open). */
    isSubmittable: ComputedRef<boolean>;
    /** The server's `reference_images.N` errors, keyed by the row each came from. */
    rowErrors: ComputedRef<Record<number, string>>;
    /** The server's error that belongs to no field (the throttle, closed, or a stale form), hidden while a submit is in flight. */
    formError: ComputedRef<string | undefined>;
    submit: () => void;
}

/**
 * The Generate form: its state, live estimate, submit rules, server errors and the submit.
 *
 * - The form has no remember key, and the token naming this visit's form submission (D-38)
 *   is made once per mount, so going Back to Generate starts a new form with a new token.
 *   A validation or throttle response keeps the page mounted, so a resubmit reuses the
 *   token safely: nothing was created.
 * - The submitted references are exactly the rows the estimate counted, so the price shown
 *   is the price held.
 * - A field's server error goes as soon as that field changes.
 */
export function useGenerationForm(options: GenerationFormOptions): GenerationForm {
    const form = useForm<GenerationFormInput>({ prompt: '', size: DEFAULT_SIZE, quality: DEFAULT_QUALITY, reference_images: [] });

    const submissionToken = crypto.randomUUID();

    const liveEstimate = useEstimate(options.pricing, form);

    const isSubmittable = computed(
        () => toValue(options.canSubmit) && canSubmitGeneration(form, toValue(options.limits), liveEstimate.estimate.value !== null),
    );

    /** Where each submitted reference came from, captured at submit. */
    const submittedRows = ref<number[]>([]);
    const fieldErrors = computed<Record<string, string | undefined>>(() => form.errors);
    const rowErrors = computed(() => referenceRowErrors(submittedRows.value, fieldErrors.value));

    const page = usePage();
    const formError = computed(() => (form.processing ? undefined : (page.props.errors.submission ?? page.props.errors.submission_token)));

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
            const referenceKeys = Object.keys(form.errors).filter(
                (key) => key === 'reference_images' || key.startsWith('reference_images.'),
            );

            form.clearErrors(...(referenceKeys as (keyof GenerationFormInput)[]));
        },
    );

    function submit(): void {
        // The disabled button stops clicks and Enter in a field; this stops a second submit before it re-renders.
        if (!isSubmittable.value || form.processing) {
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
            onError: () => options.onRejected(),
        });
    }

    return { ...liveEstimate, form, isSubmittable, rowErrors, formError, submit };
}
