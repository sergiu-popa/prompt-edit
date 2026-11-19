import { expect, test } from '../e2e/fixtures';

/**
 * The production smoke (AC17), run after every deploy with `make smoke-production`.
 * It never registers a user and writes nothing beyond one reviewer session.
 * Importing the E2E fixtures fails every test on a browser console or page error.
 */
const reviewer = {
    email: process.env.E2E_REVIEWER_EMAIL ?? '',
    password: process.env.E2E_REVIEWER_PASSWORD ?? '',
};

const LEAKS = ['Whoops', 'Stack trace', 'vendor/', 'SQLSTATE', 'APP_KEY', 'DB_PASSWORD'];

test('the health check answers 200', async ({ request }) => {
    const response = await request.get('/up');

    expect(response.status()).toBe(200);
});

test('plain http redirects to https', async ({ request, baseURL }) => {
    const secure = new URL('/login', baseURL);
    const plain = new URL(secure);
    plain.protocol = 'http:';

    const response = await request.get(plain.href, { maxRedirects: 0 });

    expect(response.status()).toBe(301);
    expect(response.headers()['location']).toBe(secure.href);
});

test('an unknown URL answers a plain 404', async ({ request }) => {
    // Through the API: a 404 document would make Chrome log a console error.
    const response = await request.get(`/smoke-missing-${Date.now()}`);
    const body = await response.text();

    expect(response.status()).toBe(404);
    expect(body).toContain('Not Found');

    for (const leak of LEAKS) {
        expect(body, `404 body must not contain ${leak}`).not.toContain(leak);
    }
});

test('the sign-in page renders over https with secure cookies', async ({ page, context }) => {
    const requested: string[] = [];
    page.on('request', (request) => requested.push(request.url()));

    await page.goto('/login');

    await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible();

    const cookies = await context.cookies();
    const session = cookies.find((cookie) => cookie.name === 'promptedit-session');
    const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');

    expect(session, 'session cookie').toMatchObject({ secure: true, httpOnly: true, sameSite: 'Lax' });
    expect(xsrf, 'XSRF-TOKEN cookie').toMatchObject({ secure: true, httpOnly: false });

    const insecure = requested.filter((url) => !url.startsWith('https://') && !url.startsWith('data:'));
    expect(insecure, 'requests that are not https').toEqual([]);
});

test('the reviewer signs in and out', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill(reviewer.email);
    await page.getByLabel('Password').fill(reviewer.password);
    await page.getByRole('button', { name: 'Sign in' }).click();

    // While the password is in the form, nothing here may be an expect(): a failing
    // matcher writes an ARIA snapshot of the page, input values included, to
    // test-results/. waitForURL writes none, and the dashboard has no password field.
    await page.waitForURL('/dashboard');
    const signOut = page.getByRole('button', { name: 'Sign out' });
    await expect(signOut).toBeVisible();

    await signOut.click();

    await expect(page).toHaveURL('/');

    await page.goto('/dashboard');
    await expect(page).toHaveURL('/login');
});
