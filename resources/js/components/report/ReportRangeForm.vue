<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { useId } from 'vue';

import Button from '@/components/ui/Button.vue';
import Field from '@/components/ui/Field.vue';
import TextInput from '@/components/ui/TextInput.vue';
import { useFocusFirstInvalidField } from '@/composables/useFocusFirstInvalidField';
import { paths } from '@/lib/paths';
import type { ReportLimits, ReportRange } from '@/types/report';

/**
 * The report's UTC range (D-65). The server validates it; a rejected range comes back as
 * field errors, and the typed dates stay (`preserveState`). On a full load of an invalid
 * URL the errors arrive with the page, so they are read from its props too.
 */
const props = defineProps<{
    range: ReportRange;
    limits: ReportLimits;
}>();

const page = usePage();
const hintId = useId();
const form = useForm({ start_date: props.range.start_date, end_date: props.range.end_date });
const focusFirstInvalidField = useFocusFirstInvalidField('formElement');

function errorFor(field: 'start_date' | 'end_date'): string | undefined {
    return form.errors[field] ?? page.props.errors[field];
}

function describedBy(fieldDescribedBy: string | undefined): string {
    return [fieldDescribedBy, hintId].filter((id) => id !== undefined).join(' ');
}

function apply(): void {
    if (form.processing) {
        return;
    }

    form.get(paths.report, {
        preserveState: true,
        preserveScroll: true,
        onError: () => focusFirstInvalidField(),
    });
}
</script>

<template>
    <form ref="formElement" class="flex flex-col gap-3" novalidate @submit.prevent="apply">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
            <Field v-slot="{ id, describedBy: fieldDescribedBy, invalid }" label="From" :error="errorFor('start_date')" class="sm:w-44">
                <TextInput
                    :id="id"
                    v-model="form.start_date"
                    type="date"
                    :max="limits.today"
                    :aria-describedby="describedBy(fieldDescribedBy)"
                    :invalid="invalid"
                />
            </Field>
            <Field v-slot="{ id, describedBy: fieldDescribedBy, invalid }" label="To" :error="errorFor('end_date')" class="sm:w-44">
                <TextInput
                    :id="id"
                    v-model="form.end_date"
                    type="date"
                    :max="limits.today"
                    :aria-describedby="describedBy(fieldDescribedBy)"
                    :invalid="invalid"
                />
            </Field>
            <!-- From `sm`, the label row's height lines the button up with the inputs. -->
            <Button type="submit" class="sm:mt-7" :busy="form.processing" busy-label="Applying…">Apply</Button>
        </div>
        <p :id="hintId" class="text-sm text-zinc-500">UTC dates, inclusive, up to {{ limits.max_days }} days.</p>
    </form>
</template>
