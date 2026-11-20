import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import { visibleText } from '../../support/text';

import CreditBalanceLine from '@/components/credits/CreditBalanceLine.vue';
import type { CreditBalance } from '@/types/credits';

function lineFor(credits: CreditBalance): string {
    return visibleText(mount(CreditBalanceLine, { props: { credits } }).get('[data-credit-balance]'));
}

describe('CreditBalanceLine', () => {
    it('shows what is available and what one running generation reserves (AC 4)', () => {
        expect(lineFor({ available_nanodollars: 913_687_500, reserved_nanodollars: 86_312_500, running_generations: 1 })).toBe(
            'Available $0.9137 · $0.0863 reserved for 1 running generation',
        );
    });

    it('pluralises several running generations', () => {
        expect(lineFor({ available_nanodollars: 827_375_000, reserved_nanodollars: 172_625_000, running_generations: 2 })).toBe(
            'Available $0.8274 · $0.1726 reserved for 2 running generations',
        );
    });

    it('leaves the reserved part out while nothing runs', () => {
        expect(lineFor({ available_nanodollars: 925_668_750, reserved_nanodollars: 0, running_generations: 0 })).toBe('Available $0.9257');
    });

    it('says a negative balance needs a top-up and links to Buy credits (AC 13)', () => {
        const wrapper = mount(CreditBalanceLine, {
            props: { credits: { available_nanodollars: -1_000_000, reserved_nanodollars: 0, running_generations: 0 } },
        });

        expect(visibleText(wrapper.get('[data-credit-balance]'))).toBe('Balance -$0.0010 · Top up to keep generating. Buy credits');
        expect(wrapper.get('[data-credit-balance] a').attributes('href')).toBe('/credits');
        expect(wrapper.get('[data-credit-balance] button').element.closest('.text-red-700')).not.toBeNull();
    });

    it('offers no top-up while the balance is not negative', () => {
        const wrapper = mount(CreditBalanceLine, {
            props: { credits: { available_nanodollars: 0, reserved_nanodollars: 86_312_500, running_generations: 1 } },
        });

        expect(wrapper.find('a').exists()).toBe(false);
        expect(visibleText(wrapper.get('[data-credit-balance]'))).toBe('Available $0.0000 · $0.0863 reserved for 1 running generation');
    });

    it('gives each amount its exact value', () => {
        const wrapper = mount(CreditBalanceLine, {
            props: { credits: { available_nanodollars: 913_687_500, reserved_nanodollars: 86_312_500, running_generations: 1 } },
        });

        expect(wrapper.findAll('[role="tooltip"]').map((tip) => tip.text())).toStrictEqual(['$0.913687500', '$0.086312500']);
    });
});
