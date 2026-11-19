import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

import Button from '@/components/ui/Button.vue';

describe('Button', () => {
    it('renders its slot as the label and is a plain button by default', () => {
        const wrapper = mount(Button, { slots: { default: 'Save' } });

        expect(wrapper.element.tagName).toBe('BUTTON');
        expect(wrapper.attributes('type')).toBe('button');
        expect(wrapper.text()).toBe('Save');
        expect(wrapper.attributes('disabled')).toBeUndefined();
        expect(wrapper.attributes('aria-busy')).toBeUndefined();
    });

    it('can submit a form', () => {
        const wrapper = mount(Button, { props: { type: 'submit' }, slots: { default: 'Save' } });

        expect(wrapper.attributes('type')).toBe('submit');
    });

    it('is disabled, marked busy and shows the busy label while busy', () => {
        const wrapper = mount(Button, { props: { busy: true, busyLabel: 'Saving…' }, slots: { default: 'Save' } });

        expect(wrapper.attributes('disabled')).toBeDefined();
        expect(wrapper.attributes('aria-busy')).toBe('true');
        expect(wrapper.text()).toBe('Saving…');
        expect(wrapper.find('svg').exists()).toBe(true);
    });

    it('keeps its slot label while busy when no busy label is given', () => {
        const wrapper = mount(Button, { props: { busy: true }, slots: { default: 'Save' } });

        expect(wrapper.text()).toBe('Save');
    });

    it('emits a click when enabled', () => {
        const onClick = vi.fn();
        const wrapper = mount(Button, { attrs: { onClick }, slots: { default: 'Save' } });

        wrapper.element.dispatchEvent(new MouseEvent('click'));

        expect(onClick).toHaveBeenCalledTimes(1);
    });

    it.each([{ disabled: true }, { busy: true }])('emits nothing when %o', (props) => {
        const onClick = vi.fn();
        const wrapper = mount(Button, { props, attrs: { onClick }, slots: { default: 'Save' } });

        wrapper.element.dispatchEvent(new MouseEvent('click'));

        expect(onClick).not.toHaveBeenCalled();
    });

    it('renders as another element, passing its attributes through', () => {
        const wrapper = mount(Button, { props: { as: 'a' }, attrs: { href: '/login' }, slots: { default: 'Sign in' } });

        expect(wrapper.element.tagName).toBe('A');
        expect(wrapper.attributes('href')).toBe('/login');
        expect(wrapper.attributes('type')).toBeUndefined();
        expect(wrapper.attributes('disabled')).toBeUndefined();
    });
});
