import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import Textarea from '@/components/ui/Textarea.vue';

describe('Textarea', () => {
    it('shows its model value and updates it as the user types', async () => {
        const wrapper = mount(Textarea, {
            props: {
                modelValue: 'A lighthouse',
                'onUpdate:modelValue': (value: string | undefined) => wrapper.setProps({ modelValue: value }),
            },
        });

        expect(wrapper.element.tagName).toBe('TEXTAREA');
        expect(wrapper.element.value).toBe('A lighthouse');

        await wrapper.setValue('A lighthouse on a cliff');

        expect(wrapper.props('modelValue')).toBe('A lighthouse on a cliff');
    });

    it('follows a model value changed by its parent', async () => {
        const wrapper = mount(Textarea, { props: { modelValue: 'A lighthouse' } });

        await wrapper.setProps({ modelValue: '' });

        expect(wrapper.element.value).toBe('');
    });

    it('is marked invalid only when invalid', async () => {
        const wrapper = mount(Textarea, { props: { modelValue: '' } });

        expect(wrapper.attributes('aria-invalid')).toBeUndefined();

        await wrapper.setProps({ invalid: true });

        expect(wrapper.attributes('aria-invalid')).toBe('true');
    });

    it('never limits its length, so pasting is never truncated', async () => {
        const wrapper = mount(Textarea, {
            props: { modelValue: '', 'onUpdate:modelValue': (value: string | undefined) => wrapper.setProps({ modelValue: value }) },
        });

        await wrapper.setValue('a'.repeat(5_000));

        expect(wrapper.attributes('maxlength')).toBeUndefined();
        expect(wrapper.props('modelValue')).toHaveLength(5_000);
    });

    it('passes its attributes to the textarea', () => {
        const wrapper = mount(Textarea, {
            props: { modelValue: '' },
            attrs: { id: 'prompt', rows: '6', 'aria-describedby': 'prompt-hint' },
        });

        expect(wrapper.attributes()).toMatchObject({ id: 'prompt', rows: '6', 'aria-describedby': 'prompt-hint' });
    });
});
