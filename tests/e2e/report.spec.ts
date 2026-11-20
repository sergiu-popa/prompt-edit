import { expect, test } from './fixtures';
import { OPERATOR_USER, registerNewUser, signIn } from './support/auth';
import { expectNoHorizontalScroll } from './support/layout';
import { exactNanodollars, historyItems, statusOf, submitPrompt, TERMINAL_TIMEOUT_MS } from './support/liveStatus';

/*
 * Other specs create generations in parallel, so the report's daily and overall totals move
 * during a run. This spec reads only its own user's row; PHP tests prove the exact totals.
 */
test('an operator sees a new user’s billed generation in the report, which the user cannot open', async ({ page }, testInfo) => {
    const email = await registerNewUser(page, testInfo);

    await submitPrompt(page, '[succeed] a red fox', { quality: 'Low', size: 'Square' });
    const item = historyItems(page).first();
    await expect(statusOf(item)).toHaveText('Succeeded', { timeout: TERMINAL_TIMEOUT_MS });
    await expect(item.locator('[data-charge]')).toBeVisible();
    const charge = await exactNanodollars(item.locator('[data-charge] [role="tooltip"]'));
    expect(charge).toBeGreaterThan(0);

    // A non-operator never gets the link, and the URL is refused. page.request shares the
    // session but loads no document, so the 403 logs no console error.
    await expect(page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Report' })).toHaveCount(0);
    const refused = await page.request.get('/report');
    expect(refused.status()).toBe(403);
    expect(await refused.text()).toContain('access to this page.');

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL('/');
    await signIn(page, OPERATOR_USER.email, OPERATOR_USER.password);
    await expect(page).toHaveURL('/dashboard');

    await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Report' }).click();

    await expect(page).toHaveURL('/report');
    await expect(page).toHaveTitle(/^Report/);
    await expect(page.getByRole('heading', { level: 1, name: 'Operator report' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Report' })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByLabel('To')).toHaveValue(new Date().toISOString().slice(0, 10));

    const row = page.getByRole('table', { name: 'Per user' }).getByRole('row').filter({ hasText: email });
    await expect(row).toHaveCount(1);
    await expect(row.locator('[data-succeeded]')).toHaveText('1');
    expect(await exactNanodollars(row.locator('[data-spend] [role="tooltip"]'))).toBe(charge);

    await page.setViewportSize({ width: 375, height: 812 });
    await expectNoHorizontalScroll(page);
});
