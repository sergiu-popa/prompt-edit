import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AppLayout from '@/layouts/AppLayout.vue';

import { givenSignedInAs, givenThePageIsAt, givenVisitsStayPending } from '../support/inertia';

describe('AppLayout', () => {
    beforeEach(() => {
        givenSignedInAs({ id: 1, name: 'Ada Lovelace', email: 'ada@example.com' });
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

        const signOut = wrapper.get('header button');
        expect(signOut.text()).toBe('Sign out');
        expect(signOut.attributes('type')).toBe('button');

        await signOut.trigger('click');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post).toHaveBeenCalledWith('/logout', {}, expect.any(Object));
    });

    it('shows a busy Sign out and sends one request however often it is clicked', async () => {
        const post = givenVisitsStayPending();
        const wrapper = mount(AppLayout);

        await wrapper.get('header button').trigger('click');
        await wrapper.get('header button').trigger('click');

        const signOut = wrapper.get('header button');
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
});
