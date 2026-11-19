import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AppLayout from '@/layouts/AppLayout.vue';

import { givenSignedInAs } from '../support/inertia';

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
        expect(post).toHaveBeenCalledWith('/logout');
    });

    it('renders the page inside <main>', () => {
        const wrapper = mount(AppLayout, { slots: { default: '<p>Page content</p>' } });

        expect(wrapper.get('main').text()).toBe('Page content');
    });

    it('renders the nav slot inside <nav>, and no <nav> without it', () => {
        expect(mount(AppLayout).find('nav').exists()).toBe(false);

        const wrapper = mount(AppLayout, { slots: { nav: '<a href="/history">History</a>' } });

        expect(wrapper.get('header nav').text()).toBe('History');
    });
});
