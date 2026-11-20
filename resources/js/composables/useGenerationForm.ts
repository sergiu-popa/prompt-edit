import type { InertiaForm } from '@inertiajs/vue3';
import { useForm, usePage } from '@inertiajs/vue3';
import type { ComputedRef, MaybeRefOrGetter } from 'vue';
import { computed, ref, toValue, watch } from 'vue';

import type { LiveEstimate } from '@/composables/useEstimate';
import { useEstimate } from '@/composables/useEstimate';
import { creditShortfall } from '@/lib/credits';
import { canSubmitGeneration } from '@/lib/generationForm';
import { DEFAULT_QUALITY, DEFAULT_SIZE } from '@/lib/imageOptions';
import { paths } from '@/lib/paths';
import { countedReferenceImages, referenceRowErrors, submittedRowIndexes } from '@/lib/referenceImages';
import type { CreditShortfall } from '@/types/credits';
import type { GenerationFormInput, GenerationInputLimits, GenerationSubmission } from '@/types/generation';
import type { PricingTable } from '@/types/pricing';

export interface GenerationFormOptions {
    pricing: MaybeRefOrGetter<PricingTable>;
    limits: MaybeRefOrGetter<GenerationInputLimits>;
    /** False until Pixelforge is configured (D-40). */
    canSubmit: MaybeRefOrGetter<boolean>;
    /** The shared balance's available n$, or null when there is none to check against. */
    available?: MaybeRefOrGetter<number | null>;
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
    /** Why the balance can't hold this request: the server's refusal until an input changes, else the advisory. */
    shortfall: ComputedRef<CreditShortfall | null>;
    /** Whether `shortfall` is the server's refusal rather than the browser's advisory. */
    isShortfallFromServer: ComputedRef<boolean>;
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
 * - While the TS max estimate exceeds the available balance, Generate stays closed with
 *   the shortfall (PE-017 AC 14). The check is advisory: the server decides (D-68). Its
 *   refusal (the `balance` error and the flashed amounts) is shown until an input
 *   changes, then the advisory takes over. Without the flash, as after Back, the
 *   error's own text is shown instead.
 */
export function useGenerationForm(options: GenerationFormOptions): GenerationForm {
    const form = useForm<GenerationFormInput>({ prompt: '', size: DEFAULT_SIZE, quality: DEFAULT_QUALITY, reference_images: [] });

    const submissionToken = crypto.randomUUID();

    const liveEstimate = useEstimate(options.pricing, form);

    const isSubmittable = computed(
        () =>
            toValue(options.canSubmit) &&
            canSubmitGeneration(form, toValue(options.limits), liveEstimate.estimate.value !== null) &&
            advisoryShortfall.value === null,
    );

    /** Where each submitted reference came from, captured at submit. */
    const submittedRows = ref<number[]>([]);
    const fieldErrors = computed<Record<string, string | undefined>>(() => form.errors);
    const rowErrors = computed(() => referenceRowErrors(submittedRows.value, fieldErrors.value));

    const page = usePage();

    /** Set by any input change, so a refusal for credit stops speaking for the new request. */
    const hasChangedSinceSubmit = ref(false);

    const serverShortfall = computed<CreditShortfall | null>(() => {
        if (form.processing || hasChangedSinceSubmit.value || page.props.errors.balance === undefined) {
            return null;
        }

        return page.flash.credit_shortfall ?? null;
    });

    const advisoryShortfall = computed<CreditShortfall | null>(() => {
        const available = toValue(options.available) ?? null;
        const estimate = liveEstimate.estimate.value;

        if (available === null || estimate === null) {
            return null;
        }

        const required = estimate.user_nanodollars.max;
        const shortfall = creditShortfall(required, available);

        return shortfall === 0
            ? null
            : { required_nanodollars: required, available_nanodollars: available, shortfall_nanodollars: shortfall };
    });

    const shortfall = computed(() => serverShortfall.value ?? advisoryShortfall.value);
    const isShortfallFromServer = computed(() => serverShortfall.value !== null);

    const formError = computed(() => {
        if (form.processing) {
            return undefined;
        }

        const { submission, submission_token: submissionToken, balance } = page.props.errors;
        const balanceError = serverShortfall.value === null && !hasChangedSinceSubmit.value ? balance : undefined;

        return submission ?? submissionToken ?? balanceError;
    });

    watch(
        () => [form.prompt, form.size, form.quality, form.reference_images],
        () => (hasChangedSinceSubmit.value = true),
        { deep: true },
    );
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
        hasChangedSinceSubmit.value = false;

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

    return { ...liveEstimate, form, isSubmittable, rowErrors, formError, shortfall, isShortfallFromServer, submit };
}
