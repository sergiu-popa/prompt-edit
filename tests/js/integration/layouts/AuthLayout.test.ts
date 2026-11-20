import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

import AuthLayout from '@/layouts/AuthLayout.vue';

import { givenAGuest, givenFlash, givenNoFlash } from '../support/inertia';

const NOTICE = 'Your session expired. Please try again.';

describe('AuthLayout', () => {
    beforeEach(() => {
        givenAGuest();
        givenNoFlash();
    });

    it('shows the server notice as a status between the heading and the form', () => {
        givenFlash({ notice: NOTICE });

        const wrapper = mount(AuthLayout, { props: { title: 'Sign in' }, slots: { default: '<form>Form</form>' } });

        const status = wrapper.get('[role="status"]');
        expect(status.text()).toBe(NOTICE);
        expect(status.element.previousElementSibling?.tagName).toBe('H1');
        expect(status.element.nextElementSibling?.tagName).toBe('FORM');
    });

    it('shows no status without a notice', () => {
        const wrapper = mount(AuthLayout, { props: { title: 'Sign in' } });

        expect(wrapper.find('[role="status"]').exists()).toBe(false);
    });
});
