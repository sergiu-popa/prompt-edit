import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';

import Field from '@/components/ui/Field.vue';
import FieldError from '@/components/ui/FieldError.vue';

type FieldSlotProps = { id: string; describedBy: string | undefined; invalid: boolean };

function mountField(props: { label: string; id?: string; hint?: string; error?: string }) {
    return mount(Field, {
        props,
        slots: {
            default: (slot: FieldSlotProps) =>
                h('input', {
                    id: slot.id,
                    'aria-describedby': slot.describedBy,
                    'aria-invalid': slot.invalid ? 'true' : undefined,
                }),
        },
    });
}

describe('Field', () => {
    it('labels its input with a visible label', () => {
        const wrapper = mountField({ label: 'Email' });

        const input = wrapper.get('input');
        expect(wrapper.get('label').text()).toBe('Email');
        expect(input.attributes('id')).toBeTruthy();
        expect(wrapper.get('label').attributes('for')).toBe(input.attributes('id'));
    });

    it('uses the id it is given', () => {
        const wrapper = mountField({ label: 'Email', id: 'email' });

        expect(wrapper.get('label').attributes('for')).toBe('email');
        expect(wrapper.get('input').attributes('id')).toBe('email');
    });

    it('gives each field on a page its own id', () => {
        const input = (slot: FieldSlotProps) => h('input', { id: slot.id });
        const wrapper = mount(
            defineComponent({
                render: () => [h(Field, { label: 'Email' }, { default: input }), h(Field, { label: 'Password' }, { default: input })],
            }),
        );

        const [email, password] = wrapper.findAll('input');
        expect(email?.attributes('id')).toBeTruthy();
        expect(email?.attributes('id')).not.toBe(password?.attributes('id'));
    });

    it('leaves a valid field without a description or invalid flag', () => {
        const wrapper = mountField({ label: 'Email', id: 'email' });

        expect(wrapper.get('input').attributes('aria-describedby')).toBeUndefined();
        expect(wrapper.get('input').attributes('aria-invalid')).toBeUndefined();
        expect(wrapper.findAll('p')).toHaveLength(0);
    });

    it('links its hint to the input', () => {
        const wrapper = mountField({ label: 'Password', id: 'password', hint: 'At least 8 characters.' });

        expect(wrapper.get('#password-hint').text()).toBe('At least 8 characters.');
        expect(wrapper.get('input').attributes('aria-describedby')).toBe('password-hint');
        expect(wrapper.get('input').attributes('aria-invalid')).toBeUndefined();
    });

    it('shows its error, links it to the input and marks the input invalid', () => {
        const wrapper = mountField({ label: 'Password', id: 'password', hint: 'At least 8 characters.', error: 'Too short.' });

        expect(wrapper.get('#password-error').text()).toBe('Too short.');
        expect(wrapper.get('input').attributes('aria-describedby')).toBe('password-hint password-error');
        expect(wrapper.get('input').attributes('aria-invalid')).toBe('true');
    });

    it('renders its aside on the label row and describes the input with it first', () => {
        const wrapper = mount(Field, {
            props: { label: 'Prompt', id: 'prompt', hint: 'Plain words.' },
            slots: {
                default: (slot: FieldSlotProps) => h('textarea', { id: slot.id, 'aria-describedby': slot.describedBy }),
                aside: () => '40 / 4,000',
            },
        });

        expect(wrapper.get('#prompt-aside').text()).toBe('40 / 4,000');
        expect(wrapper.get('#prompt-aside').element.parentElement).toBe(wrapper.get('label').element.parentElement);
        expect(wrapper.get('textarea').attributes('aria-describedby')).toBe('prompt-aside prompt-hint');
    });

    it('renders its error through FieldError, with the id the input points at', () => {
        const wrapper = mountField({ label: 'Password', id: 'password', error: 'Too short.' });

        expect(wrapper.findComponent(FieldError).props()).toStrictEqual({ id: 'password-error', message: 'Too short.' });
    });
});

describe('FieldError', () => {
    it('shows the message with a warning icon hidden from assistive technology', () => {
        const wrapper = mount(FieldError, { props: { id: 'prompt-error', message: 'Enter a prompt.' } });

        expect(wrapper.attributes('id')).toBe('prompt-error');
        expect(wrapper.text()).toBe('Enter a prompt.');
        expect(wrapper.get('svg').attributes('aria-hidden')).toBe('true');
    });
});
