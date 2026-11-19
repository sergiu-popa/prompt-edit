<script setup lang="ts">
import { computed, nextTick, ref, useId, useTemplateRef } from 'vue';

import Button from '@/components/ui/Button.vue';
import MoneyAmount from '@/components/ui/MoneyAmount.vue';
import TextInput from '@/components/ui/TextInput.vue';
import { isHttpImageUrl } from '@/lib/referenceImages';

/**
 * Up to `max` reference image URL rows. Every non-blank row is billed, even one that
 * isn't a valid URL yet, so the estimate shows what submitting will cost once it is
 * fixed. The shape hint waits until a row loses focus, so typing `https://` never
 * flashes an error. Add and Remove keep focus on mousedown and move it themselves:
 * otherwise the blur would show a hint, shift the button and swallow the click.
 */
const props = defineProps<{
    max: number;
    /** What one more counted row adds, or null while the estimate is unavailable. */
    perReferenceNanodollars: number | null;
}>();

const rows = defineModel<string[]>({ required: true });

const baseId = useId();
const list = useTemplateRef<HTMLElement>('list');
const actions = useTemplateRef<HTMLElement>('actions');
const focusedRow = ref<number | null>(null);

const isFull = computed(() => rows.value.length >= props.max);

const HINT = 'Enter an http:// or https:// image URL.';

/** Bound rather than static: vue-tsc mis-parses a static `https://` attribute on a component in a v-for. */
const PLACEHOLDER = 'https://example.com/style.png';

function rowId(index: number): string {
    return `${baseId}-row-${index}`;
}

function hintId(index: number): string {
    return `${rowId(index)}-hint`;
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

    rows.value = [...rows.value, ''];
    await nextTick();
    list.value?.querySelector<HTMLInputElement>(`[id="${rowId(rows.value.length - 1)}"]`)?.focus();
}

/** The removed row's button leaves the page, so focus moves to Add instead of dropping to the body. */
async function removeRow(index: number): Promise<void> {
    rows.value = rows.value.filter((_row, rowIndex) => rowIndex !== index);
    focusedRow.value = null;
    await nextTick();
    actions.value?.querySelector<HTMLButtonElement>('button')?.focus();
}
</script>

<template>
    <!-- The count sits outside the fieldset: inside one, `top-0` is measured below the legend. -->
    <div class="relative min-w-0">
        <p class="absolute top-0 right-0 text-sm text-zinc-500 tabular-nums">{{ rows.length }} of {{ max }}</p>
        <fieldset class="flex min-w-0 flex-col gap-3">
            <legend class="mb-2 text-sm font-medium text-zinc-800">Reference images (optional)</legend>

            <ul v-if="rows.length > 0" ref="list" class="flex flex-col gap-3">
                <li v-for="(row, index) in rows" :key="index" class="flex flex-col gap-1.5">
                    <label :for="rowId(index)" class="sr-only">Reference image {{ index + 1 }}</label>
                    <div class="flex items-center gap-2">
                        <TextInput
                            :id="rowId(index)"
                            :model-value="row"
                            type="url"
                            inputmode="url"
                            autocomplete="off"
                            :placeholder="PLACEHOLDER"
                            :invalid="needsHint(index)"
                            :aria-describedby="needsHint(index) ? hintId(index) : undefined"
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
                    <p v-if="needsHint(index)" :id="hintId(index)" class="text-sm text-red-600">{{ HINT }}</p>
                </li>
            </ul>

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
