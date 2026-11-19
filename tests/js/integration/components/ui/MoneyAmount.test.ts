import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';

import MoneyAmount from '@/components/ui/MoneyAmount.vue';

let wrapper: VueWrapper | undefined;

function mountAmount(nanodollars: number): VueWrapper {
    wrapper = mount(MoneyAmount, { props: { nanodollars }, attachTo: document.body });

    return wrapper;
}

function tooltipOf(amount: VueWrapper) {
    return amount.get('[role="tooltip"]');
}

/** The text of everything the button's `aria-describedby` points at. */
function descriptionOf(amount: VueWrapper): string {
    return (amount.get('button').attributes('aria-describedby') ?? '')
        .split(' ')
        .map((id) => document.getElementById(id)?.textContent?.trim())
        .join(' ');
}

afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
});

describe('MoneyAmount', () => {
    it('shows the amount to 4 decimals on a plain button', () => {
        const amount = mountAmount(457_137_500);

        expect(amount.get('button').text()).toBe('$0.4571');
        expect(amount.get('button').attributes('type')).toBe('button');
    });

    it('describes the button with the exact 9-decimal value, so assistive technology reads it', () => {
        const amount = mountAmount(457_137_500);

        expect(descriptionOf(amount)).toBe('$0.457137500');
    });

    it('hides the exact value until asked for', () => {
        const amount = mountAmount(457_137_500);

        expect(tooltipOf(amount).isVisible()).toBe(false);
    });

    it.each(['mouseenter', 'focus', 'click'])('reveals the exact value on %s', async (event) => {
        const amount = mountAmount(457_137_500);

        await amount.get('button').trigger(event);

        expect(tooltipOf(amount).isVisible()).toBe(true);
        expect(tooltipOf(amount).text()).toBe('$0.457137500');
    });

    it.each<[string, string, Record<string, string>]>([
        ['Escape', 'keydown', { key: 'Escape' }],
        ['blur', 'blur', {}],
        ['mouseleave', 'mouseleave', {}],
    ])('hides the exact value again on %s', async (_label, event, options) => {
        const amount = mountAmount(457_137_500);

        await amount.get('button').trigger('focus');
        await amount.get('button').trigger(event, options);

        expect(tooltipOf(amount).isVisible()).toBe(false);
    });

    it('shows a tiny non-zero amount as <$0.0001, with its exact value', () => {
        const amount = mountAmount(40_000);

        expect(amount.get('button').text()).toBe('<$0.0001');
        expect(descriptionOf(amount)).toBe('$0.000040000');
    });
});
