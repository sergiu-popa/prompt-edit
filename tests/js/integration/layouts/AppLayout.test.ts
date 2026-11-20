import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

import AppLayout from '@/layouts/AppLayout.vue';

import {
    givenCredits,
    givenFlash,
    givenNoFlash,
    givenOperatorNavigation,
    givenSignedInAs,
    givenThePageIsAt,
    givenVisitsStayPending,
    page,
} from '../support/inertia';

/** Sign out sits directly in the header's row; the balance's button is nested deeper. */
const SIGN_OUT = 'header > div > button';

describe('AppLayout', () => {
    beforeEach(() => {
        givenSignedInAs({ id: 1, name: 'Ada Lovelace', email: 'ada@example.com' });
        givenCredits({ available_nanodollars: 913_687_500, reserved_nanodollars: 86_312_500, running_generations: 1 });
        givenNoFlash();
    });

    describe('balance', () => {
        it('shows the available balance in the header', () => {
            const balance = mount(AppLayout).get('header [data-balance]');

            expect(balance.text()).toContain('Balance');
            expect(balance.get('button').text()).toBe('$0.9137');
            expect(balance.get('[role="tooltip"]').text()).toBe('$0.913687500');
        });

        it('shows the starter grant right after sign-up as $1.0000', () => {
            givenCredits({ available_nanodollars: 1_000_000_000, reserved_nanodollars: 0, running_generations: 0 });

            expect(mount(AppLayout).get('header [data-balance] button').text()).toBe('$1.0000');
        });

        it('updates when a live update brings a new balance', async () => {
            const wrapper = mount(AppLayout);

            page.props.credits = { available_nanodollars: 925_668_750, reserved_nanodollars: 0, running_generations: 0 };
            await nextTick();

            expect(wrapper.get('header [data-balance] button').text()).toBe('$0.9257');
        });

        it('shows no balance and no Buy credits without one, as for a guest', () => {
            givenCredits(null);

            const wrapper = mount(AppLayout);
            expect(wrapper.find('[data-balance]').exists()).toBe(false);
            expect(wrapper.find('[data-buy-credits]').exists()).toBe(false);
        });

        it('links to Buy credits beside the balance', () => {
            const link = mount(AppLayout).get('header [data-buy-credits]');

            expect(link.text()).toBe('Buy credits');
            expect(link.attributes('href')).toBe('/credits');
        });

        it('shows a negative balance in red with its minus sign', () => {
            givenCredits({ available_nanodollars: -1_000_000, reserved_nanodollars: 0, running_generations: 0 });

            const amount = mount(AppLayout).get('header [data-balance] button');
            expect(amount.text()).toBe('-$0.0010');
            expect(amount.element.closest('.text-red-700')).not.toBeNull();
        });

        it('shows a positive balance without the warning colour', () => {
            const amount = mount(AppLayout).get('header [data-balance] button');

            expect(amount.element.closest('.text-red-700')).toBeNull();
        });
    });

    it('shows the server notice as a status at the top of <main>', () => {
        givenFlash({ notice: 'Your session expired. Please try again.' });

        const wrapper = mount(AppLayout, { slots: { default: '<p>Page content</p>' } });

        const status = wrapper.get('main > [role="status"]');
        expect(status.text()).toBe('Your session expired. Please try again.');
        expect(status.element).toBe(wrapper.get('main').element.firstElementChild);
    });

    it('shows no status without a notice', () => {
        expect(mount(AppLayout).find('[role="status"]').exists()).toBe(false);
    });

    it('links the app name to the signed-in home', () => {
        const wrapper = mount(AppLayout);

        const brand = wrapper.get('header a');
        expect(brand.text()).toBe('PromptEdit');
        expect(brand.attributes('href')).toBe('/dashboard');
    });

    it('shows the signed-in user', () => {
        const wrapper = mount(AppLayout);

        expect(wrapper.get('header').text()).toContain('Ada Lovelace');
    });

    it('signs out with one POST to /logout', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => {});
        const wrapper = mount(AppLayout);

        const signOut = wrapper.get(SIGN_OUT);
        expect(signOut.text()).toBe('Sign out');
        expect(signOut.attributes('type')).toBe('button');

        await signOut.trigger('click');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post).toHaveBeenCalledWith('/logout', {}, expect.any(Object));
    });

    it('shows a busy Sign out and sends one request however often it is clicked', async () => {
        const post = givenVisitsStayPending();
        const wrapper = mount(AppLayout);

        await wrapper.get(SIGN_OUT).trigger('click');
        await wrapper.get(SIGN_OUT).trigger('click');

        const signOut = wrapper.get(SIGN_OUT);
        expect(signOut.text()).toBe('Signing out…');
        expect(signOut.attributes('disabled')).toBeDefined();
        expect(signOut.attributes('aria-busy')).toBe('true');
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('renders the page inside <main>', () => {
        const wrapper = mount(AppLayout, { slots: { default: '<p>Page content</p>' } });

        expect(wrapper.get('main').text()).toBe('Page content');
    });

    it('links to Generate in the header nav, marked current on the Generate page', () => {
        givenThePageIsAt('/dashboard');

        const generate = mount(AppLayout).get('header nav a');

        expect(generate.text()).toBe('Generate');
        expect(generate.attributes('href')).toBe('/dashboard');
        expect(generate.attributes('aria-current')).toBe('page');
    });

    it('marks Generate current whatever the query string', () => {
        givenThePageIsAt('/dashboard?from=test');

        expect(mount(AppLayout).get('header nav a').attributes('aria-current')).toBe('page');
    });

    it('does not mark Generate current on another page', () => {
        givenThePageIsAt('/generations');

        expect(mount(AppLayout).get('header nav a').attributes('aria-current')).toBeUndefined();
    });

    it('links to History after Generate in the header nav', () => {
        const links = mount(AppLayout).findAll('header nav a');

        expect(links.map((link) => [link.text(), link.attributes('href')])).toStrictEqual([
            ['Generate', '/dashboard'],
            ['History', '/generations'],
        ]);
    });

    it.each(['/generations', '/generations?page=2'])('marks History, and only History, current at %s', (url) => {
        givenThePageIsAt(url);

        const links = mount(AppLayout).findAll('header nav a');

        expect(links.map((link) => link.attributes('aria-current'))).toStrictEqual([undefined, 'page']);
    });

    it('does not mark History current on the Generate page', () => {
        givenThePageIsAt('/dashboard');

        expect(mount(AppLayout).findAll('header nav a')[1]!.attributes('aria-current')).toBeUndefined();
    });

    it('shows no Report link to a non-operator', () => {
        const links = mount(AppLayout).findAll('header nav a');

        expect(links.map((link) => link.text())).toStrictEqual(['Generate', 'History']);
    });

    it("links to Report after History for an operator, from the server's nav links", () => {
        givenOperatorNavigation();

        const links = mount(AppLayout).findAll('header nav a');

        expect(links.map((link) => [link.text(), link.attributes('href')])).toStrictEqual([
            ['Generate', '/dashboard'],
            ['History', '/generations'],
            ['Report', '/report'],
        ]);
    });

    it.each(['/report', '/report?start_date=2026-11-01&end_date=2026-11-18'])('marks Report, and only Report, current at %s', (url) => {
        givenOperatorNavigation();
        givenThePageIsAt(url);

        const links = mount(AppLayout).findAll('header nav a');

        expect(links.map((link) => link.attributes('aria-current'))).toStrictEqual([undefined, undefined, 'page']);
    });
});
