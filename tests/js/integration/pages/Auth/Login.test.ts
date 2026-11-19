import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import Login from '@/pages/Auth/Login.vue';

import { givenVisitsFailWith, givenVisitsStayPending } from '../../support/inertia';

function input(wrapper: VueWrapper, label: string): HTMLInputElement {
    const id = wrapper
        .findAll('label')
        .find((candidate) => candidate.text() === label)
        ?.attributes('for');

    return wrapper.get<HTMLInputElement>(`input[id="${id}"]`).element;
}

async function signIn(wrapper: VueWrapper, email: string, password: string): Promise<void> {
    await wrapper.get(`input[id="${input(wrapper, 'Email').id}"]`).setValue(email);
    await wrapper.get(`input[id="${input(wrapper, 'Password').id}"]`).setValue(password);
    await wrapper.get('form').trigger('submit');
}

function describedBy(wrapper: VueWrapper, field: HTMLInputElement): string {
    return (field.getAttribute('aria-describedby') ?? '')
        .split(' ')
        .map((id) => wrapper.find(`[id="${id}"]`).text())
        .join(' ');
}

describe('Login page', () => {
    it('titles the page and its card "Sign in"', () => {
        const wrapper = mount(Login);

        expect(wrapper.findComponent({ name: 'InertiaHead' }).props('title')).toBe('Sign in');
        expect(wrapper.get('h1').text()).toBe('Sign in');
    });

    it('labels both inputs for sign-in and leaves validation to the server', () => {
        const wrapper = mount(Login);

        expect(input(wrapper, 'Email').type).toBe('email');
        expect(input(wrapper, 'Email').autocomplete).toBe('username');
        expect(input(wrapper, 'Password').type).toBe('password');
        expect(input(wrapper, 'Password').autocomplete).toBe('current-password');
        expect(wrapper.get('form').attributes('novalidate')).toBeDefined();
    });

    it('posts the email and password to /login', async () => {
        const post = givenVisitsStayPending();
        const wrapper = mount(Login);

        await signIn(wrapper, 'ada@example.com', 'correct-password');

        expect(post).toHaveBeenCalledWith('/login', { email: 'ada@example.com', password: 'correct-password' }, expect.any(Object));
    });

    it('shows a busy, disabled button and sends one request however often it is submitted', async () => {
        const post = givenVisitsStayPending();
        const wrapper = mount(Login);

        await signIn(wrapper, 'ada@example.com', 'correct-password');
        await wrapper.get('form').trigger('submit');
        await wrapper.get('button[type="submit"]').trigger('click');

        const button = wrapper.get('button[type="submit"]');
        expect(button.text()).toBe('Signing in…');
        expect(button.attributes('disabled')).toBeDefined();
        expect(button.attributes('aria-busy')).toBe('true');
        expect(post).toHaveBeenCalledTimes(1);
    });

    it.each([['These credentials do not match our records.'], ['Too many login attempts. Please try again in 42 seconds.']])(
        'shows "%s" under Email, keeps the email and clears the password',
        async (message) => {
            givenVisitsFailWith({ email: message });
            const wrapper = mount(Login);

            await signIn(wrapper, 'ada@example.com', 'wrong-password');

            const email = input(wrapper, 'Email');
            expect(email.getAttribute('aria-invalid')).toBe('true');
            expect(describedBy(wrapper, email)).toBe(message);
            expect(email.value).toBe('ada@example.com');
            expect(input(wrapper, 'Password').value).toBe('');
            expect(input(wrapper, 'Password').getAttribute('aria-invalid')).toBeNull();
            expect(wrapper.get('button[type="submit"]').text()).toBe('Sign in');
        },
    );

    it('shows a field message under each empty field', async () => {
        givenVisitsFailWith({ email: 'The email field is required.', password: 'The password field is required.' });
        const wrapper = mount(Login);

        await signIn(wrapper, '', '');

        expect(describedBy(wrapper, input(wrapper, 'Email'))).toBe('The email field is required.');
        expect(describedBy(wrapper, input(wrapper, 'Password'))).toBe('The password field is required.');
    });

    it('links to account creation', () => {
        const wrapper = mount(Login);

        const link = wrapper.get('a[href="/register"]');
        expect(link.text()).toBe('Create one');
        expect(link.element.parentElement?.textContent?.trim()).toBe('No account yet? Create one');
    });
});
