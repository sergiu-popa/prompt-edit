import { expect, test } from './fixtures';
import { OPERATOR_USER, registerNewUser, signIn } from './support/auth';
import { expectNoHorizontalScroll } from './support/layout';
import { exactNanodollars, historyItems, statusOf, submitPrompt, TERMINAL_TIMEOUT_MS, USAGE_MATCH_TIMEOUT_MS } from './support/liveStatus';

/*
 * The fake's `/v1/usage` comes from its own state (D-75), so today's row reconciles once
 * every generation it finished is recorded. Parallel specs keep creating generations, and a
 * `[no-webhooks]` one is recorded only when the reconciler polls it, so today may be
 * briefly mismatched: the check reloads until it matches. Other days and the total move
 * with those specs too, so they are never asserted here; PHP tests prove them exactly.
 */
test('an operator sees today in progress and matching Pixelforge’s usage', async ({ page }, testInfo) => {
    await registerNewUser(page, testInfo);

    await submitPrompt(page, '[succeed] a calm harbour', { quality: 'Low', size: 'Square' });
    await expect(statusOf(historyItems(page).first())).toHaveText('Succeeded', { timeout: TERMINAL_TIMEOUT_MS });

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL('/');
    await signIn(page, OPERATOR_USER.email, OPERATOR_USER.password);
    await expect(page).toHaveURL('/dashboard');
    await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Report' }).click();
    await expect(page).toHaveURL('/report');

    const reconciliation = page.getByRole('table', { name: 'Pixelforge reconciliation (per UTC day)' });
    const today = reconciliation.locator('tr[data-usage-day][data-in-progress]');

    await expect(async () => {
        await page.reload();
        await expect(today).toHaveCount(1, { timeout: 1_000 });
        await expect(today.locator('th')).toContainText('In progress', { timeout: 1_000 });
        await expect(today.locator('[data-status]')).toHaveText('Match', { timeout: 1_000 });
    }).toPass({ timeout: USAGE_MATCH_TIMEOUT_MS });

    const pixelforgeCost = await exactNanodollars(today.locator('[data-pixelforge-cost] [role="tooltip"]'));
    expect(pixelforgeCost).toBeGreaterThan(0);
    expect(await exactNanodollars(today.locator('[data-recorded-cost] [role="tooltip"]'))).toBe(pixelforgeCost);

    await page.setViewportSize({ width: 375, height: 812 });
    await expectNoHorizontalScroll(page);
});
