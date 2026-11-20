import { expect, test } from './fixtures';
import { registerNewUser } from './support/auth';
import { expectNoHorizontalScroll } from './support/layout';
import {
    exactChargeAndEstimate,
    exactNanodollars,
    expectNoReload,
    historyItems,
    markNoReload,
    statusOf,
    submitPrompt,
    TERMINAL_TIMEOUT_MS,
} from './support/liveStatus';

/** PE-017's worked example at Generate's defaults (Medium, Square): its max is 86,312,500 n$. */
const DUSK_PROMPT = 'A lighthouse at dusk [succeed]';

/** The E2E starter (D-71) plus the $1.00 pack. */
const TOPPED_UP_NANODOLLARS = 1_010_000_000;

test('a user short of credit is blocked, buys $1.00, generates, and the header drops by exactly the charge', async ({ page }, testInfo) => {
    await registerNewUser(page, testInfo, { topUp: false });
    const banner = page.getByRole('banner');
    const headerAmount = banner.locator('[data-balance] button');
    await expect(headerAmount).toHaveText('$0.0100');

    await page.getByLabel('Prompt').fill(DUSK_PROMPT);
    const generate = page.getByRole('button', { name: 'Generate', exact: true });
    await expect(generate).toBeDisabled();
    const shortfall = page.locator('[data-submit-area] [data-shortfall]');
    await expect(shortfall.locator('[data-shortfall-copy]')).toHaveText(
        'This generation needs $0.0863 reserved (its maximum estimate), but you have $0.0100 available. You need $0.0764 more.',
    );

    await shortfall.getByRole('link', { name: 'Buy credits' }).click();
    await expect(page).toHaveURL('/credits');
    await expect(page.getByText('Demo — no payment is taken')).toBeVisible();
    await expect(page.getByRole('textbox')).toHaveCount(0);

    await page.getByRole('button', { name: 'Add $1.00' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'added.' })).toHaveText('✓ $1.00 added. Balance $1.0100', {
        useInnerText: true,
    });
    await expect(headerAmount).toHaveText('$1.0100');

    await page.setViewportSize({ width: 375, height: 812 });
    await expectNoHorizontalScroll(page);
    await page.setViewportSize(testInfo.project.use.viewport ?? { width: 1280, height: 720 });

    await banner.getByRole('link', { name: 'Generate' }).click();
    await expect(page).toHaveURL('/dashboard');
    await submitPrompt(page, DUSK_PROMPT);
    await markNoReload(page);

    const item = historyItems(page).filter({ hasText: 'Just submitted' });
    await expect(item.locator('[data-reserved]')).toHaveText('· $0.0863 reserved', { useInnerText: true });
    await expect(statusOf(item)).toHaveText('Succeeded', { timeout: TERMINAL_TIMEOUT_MS });

    const { charge } = await exactChargeAndEstimate(item);
    await expect.poll(() => exactNanodollars(banner.locator('[data-balance] [role="tooltip"]'))).toBe(TOPPED_UP_NANODOLLARS - charge);
    await expect(item.locator('[data-reserved]')).toHaveCount(0);
    await expectNoReload(page);

    await page.setViewportSize({ width: 375, height: 812 });
    await expectNoHorizontalScroll(page);
});
