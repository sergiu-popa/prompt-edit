import { router } from '@inertiajs/core';
import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { VisitOptions } from '@inertiajs/core';

import { givenCredits, givenFlash, givenSignedInAs, givenVisitsFailWith, givenVisitsStayPending } from '../../support/inertia';
import { visibleText } from '../../support/text';

import Buy from '@/pages/Credits/Buy.vue';

const PACKS = [1_000_000_000, 5_000_000_000, 10_000_000_000];

function mountPage(): VueWrapper {
    return mount(Buy, { props: { packs_nanodollars: PACKS } });
}

function addButtons(wrapper: VueWrapper) {
    return wrapper.findAll('[data-pack] button');
}

function addButton(wrapper: VueWrapper, label: string) {
    const card = wrapper.findAll('[data-pack]').find((candidate) => candidate.get('p').text() === label);

    if (!card) {
        throw new Error(`No ${label} pack.`);
    }

    return card.get('button');
}

/** Every POST succeeds, as a purchase that redirects back to Buy credits. */
function givenPurchasesSucceed() {
    return vi.spyOn(router, 'post').mockImplementation((_url, _data, options: VisitOptions = {}) => {
        options.onStart?.({} as Parameters<NonNullable<VisitOptions['onStart']>>[0]);
        options.onSuccess?.({} as Parameters<NonNullable<VisitOptions['onSuccess']>>[0]);
        options.onFinish?.({} as Parameters<NonNullable<VisitOptions['onFinish']>>[0]);
    });
}

describe('Buy credits page', () => {
    beforeEach(() => {
        givenSignedInAs({ id: 1, name: 'Ada Lovelace', email: 'ada@example.com' });
        givenCredits({ available_nanodollars: 6_312_500, reserved_nanodollars: 0, running_generations: 0 });
    });

    it('titles the page Buy credits and labels it a demo that takes no payment (AC 15)', () => {
        const wrapper = mountPage();

        expect(wrapper.findComponent({ name: 'InertiaHead' }).props('title')).toBe('Buy credits');
        expect(wrapper.get('h1').text()).toBe('Buy credits');
        expect(wrapper.get('main header [data-actions]').text()).toBe('Demo — no payment is taken');
    });

    it('offers the three packs, each with its own Add', () => {
        const wrapper = mountPage();

        expect(wrapper.findAll('[data-pack] p').map((amount) => amount.text())).toStrictEqual(['$1.00', '$5.00', '$10.00']);
        expect(addButtons(wrapper).map((button) => button.text())).toStrictEqual(['Add $1.00', 'Add $5.00', 'Add $10.00']);
        expect(visibleText(wrapper.findAll('[data-pack]')[0]!)).toContain('Add');
    });

    it('has no card or payment fields', () => {
        const wrapper = mountPage();

        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.find('select').exists()).toBe(false);
        expect(wrapper.find('textarea').exists()).toBe(false);
    });

    it("posts the pack and the visit's purchase token", async () => {
        const post = givenVisitsStayPending();
        const wrapper = mountPage();

        await addButton(wrapper, '$1.00').trigger('click');

        expect(post).toHaveBeenCalledOnce();
        expect(post.mock.calls[0]?.[0]).toBe('/credits/purchases');
        expect(post.mock.calls[0]?.[1]).toStrictEqual({
            pack_nanodollars: 1_000_000_000,
            purchase_token: expect.stringMatching(/^[0-9a-f-]{36}$/),
        });
    });

    it('disables every Add while a purchase runs, and the clicked one reads Adding…', async () => {
        givenVisitsStayPending();
        const wrapper = mountPage();

        await addButton(wrapper, '$5.00').trigger('click');

        const buttons = addButtons(wrapper);
        expect(buttons.map((button) => button.attributes('disabled'))).toStrictEqual(['', '', '']);
        expect(buttons[1]!.text()).toBe('Adding…');
        expect(buttons[1]!.attributes('aria-busy')).toBe('true');
        expect(buttons[0]!.text()).toBe('Add $1.00');
    });

    it('sends one request however often a pending Add is clicked', async () => {
        const post = givenVisitsStayPending();
        const wrapper = mountPage();

        await addButton(wrapper, '$1.00').trigger('click');
        await addButton(wrapper, '$1.00').trigger('click');
        await addButton(wrapper, '$10.00').trigger('click');

        expect(post).toHaveBeenCalledOnce();
    });

    it('keeps the token for a resend and makes a new one after a success', async () => {
        const post = givenPurchasesSucceed();
        const wrapper = mountPage();

        await addButton(wrapper, '$1.00').trigger('click');
        await addButton(wrapper, '$1.00').trigger('click');

        const [first, second] = post.mock.calls.map((call) => (call[1] as { purchase_token: string }).purchase_token);
        expect(first).toBeDefined();
        expect(second).toBeDefined();
        expect(second).not.toBe(first);
    });

    it('keeps the token after a refused purchase, since nothing was recorded', async () => {
        const post = givenVisitsFailWith({ pack_nanodollars: 'Choose one of the packs.' });
        const wrapper = mountPage();

        await addButton(wrapper, '$1.00').trigger('click');
        await addButton(wrapper, '$1.00').trigger('click');

        const [first, second] = post.mock.calls.map((call) => (call[1] as { purchase_token: string }).purchase_token);
        expect(second).toBe(first);
        expect(wrapper.get('[role="alert"]').text()).toBe('Choose one of the packs.');
    });

    it('confirms what was added and the new balance (AC 16)', () => {
        givenFlash({ purchased_nanodollars: 1_000_000_000 });
        givenCredits({ available_nanodollars: 1_006_312_500, reserved_nanodollars: 0, running_generations: 0 });

        expect(visibleText(mountPage().get('[data-purchased]'))).toBe('✓ $1.00 added. Balance $1.0063');
    });

    it('confirms nothing before a purchase', () => {
        expect(mountPage().find('[data-purchased]').exists()).toBe(false);
    });
});
