import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import { visibleText } from '../../support/text';

import ShortfallNotice from '@/components/credits/ShortfallNotice.vue';

const AC_11 = { required: 86_312_500, available: 6_312_500, shortfall: 80_000_000 };

describe('ShortfallNotice', () => {
    it("states AC 11's shortfall in words, calling the max the amount reserved", () => {
        const wrapper = mount(ShortfallNotice, { props: AC_11 });

        expect(visibleText(wrapper.get('[data-shortfall-copy]'))).toBe(
            'This generation needs $0.0863 reserved (its maximum estimate), but you have $0.0063 available. You need $0.0800 more.',
        );
    });

    it('rounds a 1 n$ shortfall up to $0.0001, never $0.0000', () => {
        const wrapper = mount(ShortfallNotice, { props: { required: 86_312_500, available: 86_312_499, shortfall: 1 } });

        expect(wrapper.get('[data-shortfall-copy]').text()).toMatch(/You need \$0\.0001 more\.$/);
    });

    it('shows a negative balance with its minus sign', () => {
        const wrapper = mount(ShortfallNotice, { props: { required: 86_312_500, available: -1_000_000, shortfall: 87_312_500 } });

        expect(wrapper.get('[data-shortfall-copy]').text()).toBe(
            'This generation needs $0.0863 reserved (its maximum estimate), but you have -$0.0010 available. You need $0.0874 more.',
        );
    });

    it('links to Buy credits', () => {
        const link = mount(ShortfallNotice, { props: AC_11 }).get('a');

        expect(link.text()).toBe('Buy credits');
        expect(link.attributes('href')).toBe('/credits');
    });

    it('is an alert only when it is the server refusing', () => {
        expect(mount(ShortfallNotice, { props: AC_11 }).get('[data-shortfall]').attributes('role')).toBeUndefined();
        expect(
            mount(ShortfallNotice, { props: { ...AC_11, announce: true } })
                .get('[data-shortfall]')
                .attributes('role'),
        ).toBe('alert');
    });
});
