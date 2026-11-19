import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import Register from '@/pages/Auth/Register.vue';

import { descriptionOf, fillIn, inputLabelled } from '../../support/form';
import { givenVisitsFailWith, givenVisitsStayPending } from '../../support/inertia';

async function createAccount(wrapper: VueWrapper): Promise<void> {
    await fillIn(wrapper, 'Name', 'Ada Lovelace');
    await fillIn(wrapper, 'Email', 'ada@example.com');
    await fillIn(wrapper, 'Password', 'short');
    await fillIn(wrapper, 'Confirm password', 'shorter');
    await wrapper.get('form').trigger('submit');
}

describe('Register page', () => {
    it('titles the page and its card "Create account"', () => {
        const wrapper = mount(Register);

        expect(wrapper.findComponent({ name: 'InertiaHead' }).props('title')).toBe('Create account');
        expect(wrapper.get('h1').text()).toBe('Create account');
    });

    it('labels every input for account creation and leaves validation to the server', () => {
        const wrapper = mount(Register);

        expect(inputLabelled(wrapper, 'Name').autocomplete).toBe('name');
        expect(inputLabelled(wrapper, 'Email').type).toBe('email');
        expect(inputLabelled(wrapper, 'Email').autocomplete).toBe('username');
        expect(inputLabelled(wrapper, 'Password').type).toBe('password');
        expect(inputLabelled(wrapper, 'Password').autocomplete).toBe('new-password');
        expect(inputLabelled(wrapper, 'Confirm password').type).toBe('password');
        expect(inputLabelled(wrapper, 'Confirm password').autocomplete).toBe('new-password');
        expect(descriptionOf(wrapper, inputLabelled(wrapper, 'Password'))).toBe('At least 8 characters.');
        expect(wrapper.get('form').attributes('novalidate')).toBeDefined();
    });

    it('posts all four fields to /register', async () => {
        const post = givenVisitsStayPending();
        const wrapper = mount(Register);

        await createAccount(wrapper);

        expect(post).toHaveBeenCalledWith(
            '/register',
            { name: 'Ada Lovelace', email: 'ada@example.com', password: 'short', password_confirmation: 'shorter' },
            expect.any(Object),
        );
    });

    it('shows a busy, disabled button and sends one request however often it is submitted', async () => {
        const post = givenVisitsStayPending();
        const wrapper = mount(Register);

        await createAccount(wrapper);
        await wrapper.get('form').trigger('submit');
        await wrapper.get('button[type="submit"]').trigger('click');

        const button = wrapper.get('button[type="submit"]');
        expect(button.text()).toBe('Creating account…');
        expect(button.attributes('disabled')).toBeDefined();
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('shows each server error under its field', async () => {
        givenVisitsFailWith({
            name: 'The name field must not be greater than 255 characters.',
            email: 'The email has already been taken.',
            password: 'The password field must be at least 8 characters.',
        });
        const wrapper = mount(Register);

        await createAccount(wrapper);

        expect(descriptionOf(wrapper, inputLabelled(wrapper, 'Name'))).toBe('The name field must not be greater than 255 characters.');
        expect(descriptionOf(wrapper, inputLabelled(wrapper, 'Email'))).toBe('The email has already been taken.');
        expect(descriptionOf(wrapper, inputLabelled(wrapper, 'Password'))).toBe(
            'At least 8 characters. The password field must be at least 8 characters.',
        );
        for (const label of ['Name', 'Email', 'Password']) {
            expect(inputLabelled(wrapper, label).getAttribute('aria-invalid')).toBe('true');
        }
        expect(inputLabelled(wrapper, 'Confirm password').getAttribute('aria-invalid')).toBeNull();
    });

    it('shows a mismatched confirmation under Password', async () => {
        givenVisitsFailWith({ password: 'The password field confirmation does not match.' });
        const wrapper = mount(Register);

        await createAccount(wrapper);

        expect(descriptionOf(wrapper, inputLabelled(wrapper, 'Password'))).toContain('The password field confirmation does not match.');
    });

    it('keeps the name and email and clears both passwords after a failed submit', async () => {
        givenVisitsFailWith({ email: 'The email has already been taken.' });
        const wrapper = mount(Register);

        await createAccount(wrapper);

        expect(inputLabelled(wrapper, 'Name').value).toBe('Ada Lovelace');
        expect(inputLabelled(wrapper, 'Email').value).toBe('ada@example.com');
        expect(inputLabelled(wrapper, 'Password').value).toBe('');
        expect(inputLabelled(wrapper, 'Confirm password').value).toBe('');
        expect(wrapper.get('button[type="submit"]').text()).toBe('Create account');
    });

    it('links to sign-in', () => {
        const wrapper = mount(Register);

        const link = wrapper.get('a[href="/login"]');
        expect(link.text()).toBe('Sign in');
        expect(link.element.parentElement?.textContent?.trim()).toBe('Already have an account? Sign in');
    });
});
