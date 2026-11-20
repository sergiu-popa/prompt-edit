import { expect, test } from './fixtures';
import { OPERATOR_USER, registerNewUser, signIn } from './support/auth';
import { expectInsideContentBox, expectNoHorizontalScroll } from './support/layout';
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
    const to = page.getByLabel('To');
    await expect(to).toHaveAttribute('max', /^\d{4}-\d{2}-\d{2}$/);
    await expect(to).toHaveValue((await to.getAttribute('max')) ?? '');

    const row = page.getByRole('table', { name: 'Per user' }).getByRole('row').filter({ hasText: email });
    await expect(row).toHaveCount(1);
    await expect(row.locator('[data-succeeded]')).toHaveText('1');
    expect(await exactNanodollars(row.locator('[data-spend] [role="tooltip"]'))).toBe(charge);

    // The operator's header is the widest: Report, the balance and Buy credits stay inside the
    // gutters at every width. Below `lg` the nav takes its own row under the brand, so the name
    // keeps the first row's free space: with the real font, one row at 768 px cut it to "Op…".
    const banner = page.getByRole('banner');
    const name = banner.locator(':scope > div > p');
    for (const width of [375, 640, 768, 1023, 1024, 1280]) {
        await page.setViewportSize({ width, height: 812 });
        await expectNoHorizontalScroll(page);
        await expectInsideContentBox(banner.locator(':scope > div'), banner.locator('[data-balance], [data-buy-credits], button'));
        const brand = (await banner.getByRole('link', { name: 'PromptEdit' }).boundingBox())!;
        const nav = (await banner.getByRole('navigation', { name: 'Main' }).boundingBox())!;
        expect(nav.y >= brand.y + brand.height, `nav on its own row at ${width} px`).toBe(width < 1024);
        expect(await name.evaluate((element) => element.scrollWidth > element.clientWidth), `name cut at ${width} px`).toBe(false);
    }
});
