import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

import MoneyAmount from '@/components/ui/MoneyAmount.vue';

let wrapper: VueWrapper | undefined;

function mountAmount(nanodollars: number): VueWrapper {
    wrapper = mount(MoneyAmount, { props: { nanodollars }, attachTo: document.body });

    return wrapper;
}

function tooltipOf(amount: VueWrapper) {
    return amount.get('[role="tooltip"]');
}

/** The events a browser sends for one tap: press, focus (unless already focused), click. */
async function tap(amount: VueWrapper, pointerType: string, { focuses = true } = {}): Promise<void> {
    const button = amount.get('button');

    await button.trigger('pointerdown', { pointerType });
    if (focuses) {
        await button.trigger('focus');
    }
    await button.trigger('pointerup', { pointerType });
    await button.trigger('click');
}

/** The events a browser sends for one mouse click on an unfocused button. */
async function mousePress(amount: VueWrapper): Promise<void> {
    const button = amount.get('button');

    await button.trigger('pointerdown', { pointerType: 'mouse' });
    await button.trigger('focus');
    await button.trigger('pointerup', { pointerType: 'mouse' });
    await button.trigger('click');
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

    it.each<[string, string, Record<string, string>]>([
        ['a mouse hover', 'pointerenter', { pointerType: 'mouse' }],
        ['keyboard focus', 'focus', {}],
        ['a keyboard click', 'click', {}],
    ])('reveals the exact value on %s', async (_label, event, options) => {
        const amount = mountAmount(457_137_500);

        await amount.get('button').trigger(event, options);

        expect(tooltipOf(amount).isVisible()).toBe(true);
        expect(tooltipOf(amount).text()).toBe('$0.457137500');
    });

    it.each<[string, string, Record<string, string>]>([
        ['Escape', 'keydown', { key: 'Escape' }],
        ['blur', 'blur', {}],
        ['the mouse leaving', 'pointerleave', { pointerType: 'mouse' }],
    ])('hides the exact value again on %s', async (_label, event, options) => {
        const amount = mountAmount(457_137_500);

        await amount.get('button').trigger('focus');
        await amount.get('button').trigger(event, options);

        expect(tooltipOf(amount).isVisible()).toBe(false);
    });

    it('keeps the exact value open when a mouse clicks the amount it hovers', async () => {
        const amount = mountAmount(457_137_500);

        await amount.get('button').trigger('pointerenter', { pointerType: 'mouse' });
        await mousePress(amount);

        expect(tooltipOf(amount).isVisible()).toBe(true);
    });

    describe.each(['touch', 'pen'])('on %s', (pointerType) => {
        it('ignores the hover events a browser fires around a tap', async () => {
            const amount = mountAmount(457_137_500);

            await amount.get('button').trigger('pointerenter', { pointerType });
            expect(tooltipOf(amount).isVisible()).toBe(false);

            await tap(amount, pointerType);
            await amount.get('button').trigger('pointerleave', { pointerType });
            await amount.get('button').trigger('mouseleave');

            expect(tooltipOf(amount).isVisible()).toBe(true);
        });

        it('opens on a tap, even though the tap also focuses the button', async () => {
            const amount = mountAmount(457_137_500);

            await tap(amount, pointerType);

            expect(tooltipOf(amount).isVisible()).toBe(true);
            expect(tooltipOf(amount).text()).toBe('$0.457137500');
        });

        it('closes on a second tap and opens again on a third', async () => {
            const amount = mountAmount(457_137_500);

            await tap(amount, pointerType);
            await tap(amount, pointerType, { focuses: false });
            expect(tooltipOf(amount).isVisible()).toBe(false);

            await tap(amount, pointerType, { focuses: false });
            expect(tooltipOf(amount).isVisible()).toBe(true);
        });

        it('closes on a press anywhere else on the page', async () => {
            const amount = mountAmount(457_137_500);
            const elsewhere = document.body.appendChild(document.createElement('p'));

            await tap(amount, pointerType);
            elsewhere.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerType }));
            await nextTick();

            expect(tooltipOf(amount).isVisible()).toBe(false);
            elsewhere.remove();
        });

        it('stays open on a press on the amount itself', async () => {
            const amount = mountAmount(457_137_500);

            await tap(amount, pointerType);
            await amount.get('[role="tooltip"]').trigger('pointerdown', { pointerType });

            expect(tooltipOf(amount).isVisible()).toBe(true);
        });

        it('closes on Escape and on blur', async () => {
            const amount = mountAmount(457_137_500);

            await tap(amount, pointerType);
            await amount.get('button').trigger('keydown', { key: 'Escape' });
            expect(tooltipOf(amount).isVisible()).toBe(false);

            await tap(amount, pointerType, { focuses: false });
            await amount.get('button').trigger('blur');
            expect(tooltipOf(amount).isVisible()).toBe(false);
        });

        it('treats keyboard focus after a cancelled press as keyboard focus', async () => {
            const amount = mountAmount(457_137_500);

            await amount.get('button').trigger('pointerdown', { pointerType });
            await amount.get('button').trigger('pointercancel', { pointerType });
            await amount.get('button').trigger('focus');

            expect(tooltipOf(amount).isVisible()).toBe(true);
        });
    });

    it('keeps the exact value in the description while the tip is closed', async () => {
        const amount = mountAmount(457_137_500);

        await tap(amount, 'touch');
        await tap(amount, 'touch', { focuses: false });

        expect(tooltipOf(amount).isVisible()).toBe(false);
        expect(descriptionOf(amount)).toBe('$0.457137500');
    });

    it('stops listening for presses elsewhere once unmounted', async () => {
        const amount = mountAmount(457_137_500);
        const removeListener = vi.spyOn(document, 'removeEventListener');

        await tap(amount, 'touch');
        amount.unmount();
        wrapper = undefined;

        expect(removeListener).toHaveBeenCalledWith('pointerdown', expect.any(Function));
    });

    it('shows a tiny non-zero amount as <$0.0001, with its exact value', () => {
        const amount = mountAmount(40_000);

        expect(amount.get('button').text()).toBe('<$0.0001');
        expect(descriptionOf(amount)).toBe('$0.000040000');
    });
});
