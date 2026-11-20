import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import Notice from '@/components/ui/Notice.vue';

describe('Notice', () => {
    it('renders the message as a status paragraph', () => {
        const wrapper = mount(Notice, { props: { message: 'Your session expired. Please try again.' } });

        expect(wrapper.element.tagName).toBe('P');
        expect(wrapper.attributes('role')).toBe('status');
        expect(wrapper.text()).toBe('Your session expired. Please try again.');
    });
});
