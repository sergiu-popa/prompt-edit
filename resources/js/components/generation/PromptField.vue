<script setup lang="ts">
import { computed } from 'vue';

import Field from '@/components/ui/Field.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { countCodePoints, formatCount } from '@/lib/counting';

/**
 * The prompt and its code-point counter. Going over the limit is an error state only:
 * typing and pasting are never blocked or truncated, and the estimate keeps following.
 */
const props = defineProps<{ maxCharacters: number }>();

const prompt = defineModel<string>({ required: true });

const characterCount = computed(() => countCodePoints(prompt.value));
const charactersOver = computed(() => characterCount.value - props.maxCharacters);

const error = computed(() =>
    charactersOver.value > 0
        ? `Pixelforge accepts at most ${formatCount(props.maxCharacters)} characters. Shorten your prompt by ${formatCount(charactersOver.value)}.`
        : undefined,
);
</script>

<template>
    <Field
        label="Prompt"
        hint="Characters are counted like Pixelforge counts them; some emoji count as more than one character."
        :error="error"
    >
        <template #aside>
            <span :class="charactersOver > 0 ? 'font-medium text-red-600' : undefined"
                >{{ formatCount(characterCount) }} / {{ formatCount(maxCharacters) }}</span
            >
        </template>
        <template #default="{ id, describedBy, invalid }">
            <Textarea :id="id" v-model="prompt" rows="5" :aria-describedby="describedBy" :invalid="invalid" />
        </template>
    </Field>
</template>
