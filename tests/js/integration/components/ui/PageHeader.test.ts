import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import PageHeader from '@/components/ui/PageHeader.vue';

describe('PageHeader', () => {
    it("headlines the page with its title, the page's only h1", () => {
        const wrapper = mount(PageHeader, { props: { title: 'History' } });

        expect(wrapper.element.tagName).toBe('HEADER');
        expect(wrapper.findAll('h1').map((heading) => heading.text())).toStrictEqual(['History']);
        expect(wrapper.find('p').exists()).toBe(false);
    });

    it('describes the page under the title', () => {
        const wrapper = mount(PageHeader, { props: { title: 'History', description: 'Your generations, newest first.' } });

        expect(wrapper.get('p').text()).toBe('Your generations, newest first.');
    });

    it('renders its actions beside the title', () => {
        const wrapper = mount(PageHeader, { props: { title: 'History' }, slots: { actions: '<a href="/dashboard">New image</a>' } });

        expect(wrapper.get('a').text()).toBe('New image');
        expect(wrapper.get('a').attributes('href')).toBe('/dashboard');
    });

    it('renders no actions wrapper without actions', () => {
        const wrapper = mount(PageHeader, { props: { title: 'History' } });

        expect(wrapper.find('[data-actions]').exists()).toBe(false);
    });
});
