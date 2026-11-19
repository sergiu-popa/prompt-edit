import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import TextInput from '@/components/ui/TextInput.vue';

describe('TextInput', () => {
    it('shows its model value and updates it as the user types', async () => {
        const wrapper = mount(TextInput, {
            props: {
                modelValue: 'ada@example.com',
                'onUpdate:modelValue': (value: string | undefined) => wrapper.setProps({ modelValue: value }),
            },
        });

        expect(wrapper.element.value).toBe('ada@example.com');

        await wrapper.setValue('grace@example.com');

        expect(wrapper.props('modelValue')).toBe('grace@example.com');
    });

    it('follows a model value changed by its parent', async () => {
        const wrapper = mount(TextInput, { props: { modelValue: 'secret' } });

        await wrapper.setProps({ modelValue: '' });

        expect(wrapper.element.value).toBe('');
    });

    it('is marked invalid only when invalid', async () => {
        const wrapper = mount(TextInput, { props: { modelValue: '' } });

        expect(wrapper.attributes('aria-invalid')).toBeUndefined();

        await wrapper.setProps({ invalid: true });

        expect(wrapper.attributes('aria-invalid')).toBe('true');
    });

    it('passes its attributes to the input', () => {
        const wrapper = mount(TextInput, {
            props: { modelValue: '' },
            attrs: { id: 'email', type: 'email', autocomplete: 'username', 'aria-describedby': 'email-error' },
        });

        expect(wrapper.element.tagName).toBe('INPUT');
        expect(wrapper.attributes()).toMatchObject({
            id: 'email',
            type: 'email',
            autocomplete: 'username',
            'aria-describedby': 'email-error',
        });
    });
});
