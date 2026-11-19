<script setup lang="ts">
import { computed, nextTick, ref, useId, useTemplateRef, watch } from 'vue';

import Button from '@/components/ui/Button.vue';
import FieldError from '@/components/ui/FieldError.vue';
import MoneyAmount from '@/components/ui/MoneyAmount.vue';
import TextInput from '@/components/ui/TextInput.vue';
import { isHttpImageUrl } from '@/lib/referenceImages';

/**
 * Up to `max` reference image URL rows. Every non-blank row is billed, even one that
 * isn't a valid URL yet, so the estimate shows what submitting will cost once it is
 * fixed. The shape hint waits until a row loses focus, so typing `https://` never
 * flashes an error. Add and Remove keep focus on mousedown and move it themselves:
 * otherwise the blur would show a hint, shift the button and swallow the click.
 *
 * The server's errors come in as a list error and per-row errors, keyed by row index;
 * a row's error replaces its hint, so each row has one message.
 */
const props = defineProps<{
    max: number;
    /** What one more counted row adds, or null while the estimate is unavailable. */
    perReferenceNanodollars: number | null;
    /** The server's error for the list as a whole, such as too many references. */
    error?: string;
    /** The server's error for each row, by row index. */
    rowErrors?: Record<number, string>;
}>();

const rows = defineModel<string[]>({ required: true });

const baseId = useId();
const list = useTemplateRef<HTMLElement>('list');
const actions = useTemplateRef<HTMLElement>('actions');
const focusedRow = ref<number | null>(null);

const isFull = computed(() => rows.value.length >= props.max);

/**
 * A stable key per row, so removing a row from the middle never hands another row's DOM
 * input (its undo history, a half-composed IME entry) to its neighbour.
 */
let nextRowKey = 0;
const rowKeys = ref<number[]>(rows.value.map(() => nextRowKey++));

/** If the parent replaces the rows, new rows get new keys and dropped rows lose theirs. */
watch(
    () => rows.value.length,
    (length) => {
        while (rowKeys.value.length < length) {
            rowKeys.value.push(nextRowKey++);
        }
        rowKeys.value.splice(length);
    },
);

function rowKey(index: number): number {
    return rowKeys.value[index] ?? -(index + 1);
}

const HINT = 'Enter an http:// or https:// image URL.';

/** Bound rather than static: vue-tsc mis-parses a static `https://` attribute on a component in a v-for. */
const PLACEHOLDER = 'https://example.com/style.png';

function rowId(index: number): string {
    return `${baseId}-row-${index}`;
}

function hintId(index: number): string {
    return `${rowId(index)}-hint`;
}

function rowErrorId(index: number): string {
    return `${rowId(index)}-error`;
}

const listErrorId = `${baseId}-error`;

function rowError(index: number): string | undefined {
    return props.rowErrors?.[index];
}

/** The id of the row's one message, if it has one: the server's error, else the shape hint. */
function rowDescriptionId(index: number): string | undefined {
    if (rowError(index) !== undefined) {
        return rowErrorId(index);
    }

    return needsHint(index) ? hintId(index) : undefined;
}

function removeLabel(index: number): string {
    return `Remove reference image ${index + 1}`;
}

function needsHint(index: number): boolean {
    const value = rows.value[index] ?? '';

    return focusedRow.value !== index && value.trim() !== '' && !isHttpImageUrl(value);
}

function updateRow(index: number, value: string): void {
    rows.value = rows.value.map((row, rowIndex) => (rowIndex === index ? value : row));
}

async function addRow(): Promise<void> {
    if (isFull.value) {
        return;
    }

    rowKeys.value.push(nextRowKey++);
    rows.value = [...rows.value, ''];
    await nextTick();
    list.value?.querySelector<HTMLInputElement>(`[id="${rowId(rows.value.length - 1)}"]`)?.focus();
}

/** The removed row's button leaves the page, so focus moves to Add instead of dropping to the body. */
async function removeRow(index: number): Promise<void> {
    rowKeys.value.splice(index, 1);
    rows.value = rows.value.filter((_row, rowIndex) => rowIndex !== index);
    focusedRow.value = null;
    await nextTick();
    actions.value?.querySelector<HTMLButtonElement>('button')?.focus();
}
</script>

<template>
    <!--
        The visible count sits outside the fieldset (inside one, `top-0` is measured below the
        legend), so it is hidden from assistive technology and the legend names the count instead.
    -->
    <div class="relative min-w-0">
        <p aria-hidden="true" class="absolute top-0 right-0 text-sm text-zinc-500 tabular-nums">{{ rows.length }} of {{ max }}</p>
        <fieldset class="flex min-w-0 flex-col gap-3" :aria-describedby="error ? listErrorId : undefined">
            <legend class="mb-2 text-sm font-medium text-zinc-800">
                Reference images (optional)<span class="sr-only">, {{ rows.length }} of {{ max }}</span>
            </legend>

            <ul v-if="rows.length > 0" ref="list" class="flex flex-col gap-3">
                <li v-for="(row, index) in rows" :key="rowKey(index)" class="flex flex-col gap-1.5">
                    <label :for="rowId(index)" class="sr-only">Reference image {{ index + 1 }}</label>
                    <div class="flex items-center gap-2">
                        <TextInput
                            :id="rowId(index)"
                            :model-value="row"
                            type="url"
                            inputmode="url"
                            autocomplete="off"
                            :placeholder="PLACEHOLDER"
                            :invalid="rowDescriptionId(index) !== undefined"
                            :aria-describedby="rowDescriptionId(index)"
                            @update:model-value="updateRow(index, $event ?? '')"
                            @focus="focusedRow = index"
                            @blur="focusedRow = null"
                        />
                        <Button
                            variant="secondary"
                            class="shrink-0 px-3"
                            :aria-label="removeLabel(index)"
                            @mousedown.prevent
                            @click="removeRow(index)"
                            ><span aria-hidden="true">×</span></Button
                        >
                    </div>
                    <FieldError v-if="rowError(index) !== undefined" :id="rowErrorId(index)" :message="rowError(index)!" />
                    <p v-else-if="needsHint(index)" :id="hintId(index)" class="text-sm text-red-600">{{ HINT }}</p>
                </li>
            </ul>

            <FieldError v-if="error" :id="listErrorId" :message="error" />

            <div ref="actions" class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <Button variant="secondary" :disabled="isFull" @mousedown.prevent @click="addRow">Add reference image</Button>
                <p v-if="isFull" class="text-sm text-zinc-500">Up to {{ max }} reference images.</p>
                <p v-else-if="perReferenceNanodollars !== null" class="text-sm text-zinc-600">
                    +<MoneyAmount :nanodollars="perReferenceNanodollars" /> each
                </p>
            </div>
        </fieldset>
    </div>
</template>
