import { expect, test } from '../e2e/fixtures';

/**
 * The production smoke (PE-004 AC17, PE-015, PE-018), run after every deploy with `make smoke-production`.
 * It never registers a user and writes nothing beyond one reviewer session: signed in, it only
 * reads History and the operator report (D-76). It also checks the reconciler's health (D-59)
 * and that Pixelforge's webhook URL answers without a redirect. Importing the E2E fixtures
 * fails every test on a browser console or page error.
 */
const reviewer = {
    email: process.env.E2E_REVIEWER_EMAIL ?? '',
    password: process.env.E2E_REVIEWER_PASSWORD ?? '',
};

const LEAKS = ['Whoops', 'Stack trace', 'vendor/', 'SQLSTATE', 'APP_KEY', 'DB_PASSWORD'];

/** How long the report may take to match: past the 60 s usage cache, with room for a catch-up (D-76). */
const REPORT_MATCH_TIMEOUT_MS = 180_000;

test('the health check answers 200', async ({ request }) => {
    const response = await request.get('/up');

    expect(response.status()).toBe(200);
});

test('the reconciler health check answers 200 ok', async ({ request }) => {
    const response = await request.get('/health/reconciler');

    expect(response.status()).toBe(200);
    expect((await response.json()) as { status: string }).toMatchObject({ status: 'ok' });
});

test('the webhook URL refuses an unsigned delivery itself, with no redirect', async ({ request }) => {
    // Pixelforge follows no redirect, so the exact URL must answer; a 400 (not a 500)
    // also proves the signing secret is set.
    const response = await request.post('/webhooks/pixelforge', { data: {}, maxRedirects: 0 });

    expect(response.status()).toBe(400);
    expect(response.headers()['location']).toBeUndefined();
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

test('a spoofed X-Forwarded-Host never reaches a generated URL', async ({ request }) => {
    // The proxy chain hands PHP a client's X-Forwarded-Host as the Host, so the app
    // refuses every host but its own (TrustHosts, D-21).
    for (const path of ['/login', '/dashboard']) {
        const response = await request.get(path, {
            headers: { 'X-Forwarded-Host': 'attacker.example' },
            maxRedirects: 0,
        });

        expect(response.headers()['location'] ?? '', `${path} Location`).not.toContain('attacker.example');
        expect(await response.text(), `${path} body`).not.toContain('attacker.example');
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

test('the reviewer sees History and a matching report, read-only, then signs out', async ({ page }) => {
    test.setTimeout(REPORT_MATCH_TIMEOUT_MS + 60_000);

    const writes: string[] = [];
    page.on('request', (request) => {
        if (request.method() !== 'GET') {
            writes.push(`${request.method()} ${new URL(request.url()).pathname}`);
        }
    });

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

    // History: the demo set's images are live for 24 h after a refresh (D-76).
    await page.goto('/generations');
    const succeeded = page
        .getByRole('main')
        .getByRole('listitem')
        .filter({ has: page.locator('[data-tone]', { hasText: /^Succeeded$/ }) });
    await expect(succeeded.first()).toBeVisible();
    await expect(succeeded.locator('[data-charge]')).toHaveCount(await succeeded.count());

    // History lazy-loads its images, so each is asked to load now; the page itself is unchanged.
    const images = succeeded.locator('img');
    await expect
        .poll(
            async () =>
                images.evaluateAll((elements) =>
                    elements.some((element) => {
                        const image = element as HTMLImageElement;
                        image.loading = 'eager';

                        return image.complete && image.naturalWidth > 0;
                    }),
                ),
            { message: 'a succeeded generation shows a loaded image' },
        )
        .toBe(true);

    // The report's default range matches /v1/usage on every day and in total (AC 16).
    await page.goto('/report');
    const reconciliation = page.getByRole('table', { name: 'Pixelforge reconciliation (per UTC day)' });
    await expect(async () => {
        await page.reload();
        await expect(reconciliation).toBeVisible({ timeout: 5_000 });
        await expect(page.locator('[data-usage-notice]')).toHaveCount(0, { timeout: 1_000 });
        await expect(reconciliation.locator('[data-status]', { hasText: 'Mismatch' })).toHaveCount(0, { timeout: 1_000 });
        const days = await reconciliation.locator('tr[data-usage-day]').count();
        expect(days).toBeGreaterThan(0);
        await expect(reconciliation.locator('tr[data-usage-day] [data-status]', { hasText: /^Match$/ })).toHaveCount(days, {
            timeout: 1_000,
        });
        await expect(reconciliation.locator('tr[data-usage-total] [data-status]')).toHaveText('Match', { timeout: 1_000 });
    }).toPass({ timeout: REPORT_MATCH_TIMEOUT_MS, intervals: [5_000, 10_000, 15_000] });

    await signOut.click();

    await expect(page).toHaveURL('/');

    await page.goto('/dashboard');
    await expect(page).toHaveURL('/login');

    expect(writes, 'the only writes are sign-in and sign-out').toEqual(['POST /login', 'POST /logout']);
});
