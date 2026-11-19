import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import Welcome from '@/pages/Welcome.vue';

import { givenSignedInAs } from '../support/inertia';

describe('Welcome page', () => {
    it('introduces the product', () => {
        const wrapper = mount(Welcome);

        expect(wrapper.get('h1').text()).toBe('Generate images with Pixelforge');
        expect(wrapper.text()).toContain('You only pay for images that succeed.');
    });

    it('titles the document through Inertia', () => {
        const wrapper = mount(Welcome);

        expect(wrapper.findComponent({ name: 'InertiaHead' }).props('title')).toBe('Welcome');
    });

    it('offers a guest sign-in and account creation', () => {
        const wrapper = mount(Welcome);

        const links = wrapper.findAll('a').map((link) => [link.text(), link.attributes('href')]);
        expect(links).toEqual([
            ['Sign in', '/login'],
            ['Create account', '/register'],
        ]);
    });

    it('offers a signed-in user only the way into the app', () => {
        givenSignedInAs({ id: 1, name: 'Ada Lovelace', email: 'ada@example.com' });
        const wrapper = mount(Welcome);

        const links = wrapper.findAll('a').map((link) => [link.text(), link.attributes('href')]);
        expect(links).toEqual([['Open PromptEdit →', '/dashboard']]);
    });
});
