<script setup lang="ts">
import { useId } from 'vue';

import ShortfallNotice from '@/components/credits/ShortfallNotice.vue';
import Button from '@/components/ui/Button.vue';
import FieldError from '@/components/ui/FieldError.vue';
import type { CreditShortfall } from '@/types/credits';

/**
 * Generate's submit button, the form-level error beside it (the throttle, or a stale
 * form), announced as an alert, the shortfall while the balance can't hold the request
 * (PE-017), and the notice while generation is not open yet. It
 * sits outside the form in the DOM, after the estimate, so a phone user reads the price
 * before reaching it; `form` ties it to the form. Full width on a phone, right-aligned
 * from `sm`.
 */
defineProps<{
    /** The id of the form the button submits. */
    formId: string;
    disabled: boolean;
    busy: boolean;
    /** An error that belongs to no field. */
    error?: string;
    /** Pixelforge is not configured yet, so the button stays disabled and a notice says why. */
    unavailable?: boolean;
    /** Why the balance can't hold this request, which also describes the button. */
    shortfall?: CreditShortfall | null;
    /** The shortfall is the server's refusal, so it is announced. */
    announceShortfall?: boolean;
}>();

const errorId = useId();
const noticeId = useId();
const shortfallId = useId();
</script>

<template>
    <div data-submit-area class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
        <p v-if="unavailable" :id="noticeId" class="rounded-lg bg-zinc-100 px-3 py-2 text-sm text-zinc-700 sm:basis-full">
            Image generation opens soon. You can already see what an image would cost.
        </p>
        <ShortfallNotice
            v-if="shortfall"
            :id="shortfallId"
            class="sm:basis-full"
            :required="shortfall.required_nanodollars"
            :available="shortfall.available_nanodollars"
            :shortfall="shortfall.shortfall_nanodollars"
            :announce="announceShortfall"
        />
        <div v-if="error" role="alert" class="min-w-0">
            <FieldError :id="errorId" :message="error" />
        </div>
        <Button
            type="submit"
            :form="formId"
            class="w-full shrink-0 sm:w-auto sm:min-w-40"
            :disabled="disabled"
            :busy="busy"
            busy-label="Generating…"
            :aria-describedby="
                [unavailable ? noticeId : null, shortfall ? shortfallId : null, error ? errorId : null]
                    .filter((id) => id !== null)
                    .join(' ') || undefined
            "
            >Generate</Button
        >
    </div>
</template>
