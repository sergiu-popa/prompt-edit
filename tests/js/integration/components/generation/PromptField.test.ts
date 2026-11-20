import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import { descriptionOf } from '../../support/form';

import PromptField from '@/components/generation/PromptField.vue';

const LIGHTHOUSE = 'A lighthouse on a cliff at dawn, in oils';
const HINT = 'Characters are counted like Pixelforge counts them; some emoji count as more than one character.';

function mountPrompt(modelValue = '', serverError?: string): VueWrapper {
    const wrapper = mount(PromptField, {
        props: {
            modelValue,
            maxCharacters: 4_000,
            serverError,
            'onUpdate:modelValue': (value: string | undefined) => wrapper.setProps({ modelValue: value }),
        },
    });

    return wrapper;
}

function textareaOf(wrapper: VueWrapper): HTMLTextAreaElement {
    return wrapper.get<HTMLTextAreaElement>('textarea').element;
}

function counterOf(wrapper: VueWrapper): string {
    const label = wrapper.get('label');

    return label.element.parentElement?.querySelector('[id$="-aside"]')?.textContent?.trim() ?? '';
}

describe('PromptField', () => {
    it('is labelled Prompt and starts at 0 / 4,000 with the counting hint', () => {
        const wrapper = mountPrompt();

        expect(wrapper.get('label').text()).toBe('Prompt');
        expect(wrapper.get('label').attributes('for')).toBe(textareaOf(wrapper).id);
        expect(counterOf(wrapper)).toBe('0 / 4,000');
        expect(wrapper.text()).toContain(HINT);
    });

    it('counts code points as the user types, so 👍🏽 adds 2', async () => {
        const wrapper = mountPrompt();

        await wrapper.get('textarea').setValue(LIGHTHOUSE);
        expect(counterOf(wrapper)).toBe('40 / 4,000');

        await wrapper.get('textarea').setValue(`${LIGHTHOUSE}👍🏽`);
        expect(counterOf(wrapper)).toBe('42 / 4,000');
    });

    it('accepts exactly 4,000 characters', () => {
        const wrapper = mountPrompt('a'.repeat(4_000));

        expect(counterOf(wrapper)).toBe('4,000 / 4,000');
        expect(textareaOf(wrapper).getAttribute('aria-invalid')).toBeNull();
        expect(wrapper.text()).not.toContain('Shorten');
    });

    it('asks to shorten by 1 at 4,001 characters', () => {
        const wrapper = mountPrompt('a'.repeat(4_001));

        expect(wrapper.text()).toContain('Pixelforge accepts at most 4,000 characters. Shorten your prompt by 1.');
    });

    it('turns to an error over the limit, counting emoji as Pixelforge does, and keeps the whole prompt', async () => {
        const prompt = `${'a'.repeat(4_010)}👍🏽`;
        const wrapper = mountPrompt();

        await wrapper.get('textarea').setValue(prompt);

        expect(counterOf(wrapper)).toBe('4,012 / 4,000');
        expect(wrapper.text()).toContain('Pixelforge accepts at most 4,000 characters. Shorten your prompt by 12.');
        expect(textareaOf(wrapper).getAttribute('aria-invalid')).toBe('true');
        expect(textareaOf(wrapper).value).toBe(prompt);
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toStrictEqual([prompt]);
    });

    it('never limits the textarea length', () => {
        expect(textareaOf(mountPrompt()).hasAttribute('maxlength')).toBe(false);
    });

    it('describes the textarea with the counter, the hint and the error', () => {
        const wrapper = mountPrompt('a'.repeat(4_012));

        expect(descriptionOf(wrapper, textareaOf(wrapper))).toBe(
            `4,012 / 4,000 ${HINT} Pixelforge accepts at most 4,000 characters. Shorten your prompt by 12.`,
        );
    });

    it("shows the server's error under the prompt and marks it invalid", () => {
        const wrapper = mountPrompt(LIGHTHOUSE, 'Enter a prompt.');

        expect(textareaOf(wrapper).getAttribute('aria-invalid')).toBe('true');
        expect(descriptionOf(wrapper, textareaOf(wrapper))).toBe(`40 / 4,000 ${HINT} Enter a prompt.`);
    });

    it("shows its own over-limit error instead of the server's", () => {
        const wrapper = mountPrompt('a'.repeat(4_001), 'The prompt must be 4,000 characters or fewer.');

        expect(wrapper.text()).toContain('Shorten your prompt by 1.');
        expect(wrapper.text()).not.toContain('The prompt must be 4,000 characters or fewer.');
    });
});
