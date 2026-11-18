import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import Welcome from '@/pages/Welcome.vue';

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
});
