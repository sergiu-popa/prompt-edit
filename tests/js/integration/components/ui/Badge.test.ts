import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import Badge from '@/components/ui/Badge.vue';

const TONES = ['neutral', 'info', 'success', 'warning', 'danger'] as const;

/** The classes that size a badge; every tone must share them, so badges line up. */
const SIZE_CLASSES = ['inline-flex', 'text-xs', 'rounded-md', 'align-baseline'];

describe('Badge', () => {
    it('renders its slot as the label, neutral by default', () => {
        const wrapper = mount(Badge, { slots: { default: 'exact' } });

        expect(wrapper.element.tagName).toBe('SPAN');
        expect(wrapper.text()).toBe('exact');
        expect(wrapper.attributes('data-tone')).toBe('neutral');
    });

    it.each(TONES)('renders the %s tone with the same size as every other tone', (tone) => {
        const wrapper = mount(Badge, { props: { tone }, slots: { default: 'label' } });

        expect(wrapper.attributes('data-tone')).toBe(tone);
        expect(wrapper.classes()).toEqual(expect.arrayContaining(SIZE_CLASSES));
    });

    it('gives each tone its own colours', () => {
        const colours = TONES.map((tone) =>
            mount(Badge, { props: { tone }, slots: { default: 'label' } })
                .classes()
                .sort()
                .join(' '),
        );

        expect(new Set(colours).size).toBe(TONES.length);
    });
});
