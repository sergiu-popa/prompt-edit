import type { Locator, Page } from '@playwright/test';

import { expect, test } from './fixtures';
import { registerNewUser } from './support/auth';

const LIGHTHOUSE_PROMPT = 'A lighthouse on a cliff at dawn, oil painting';

function headline(page: Page): Locator {
    return page.getByRole('complementary').locator('[data-headline]');
}

function generateButton(page: Page): Locator {
    return page.getByRole('button', { name: 'Generate', exact: true });
}

function historyItems(page: Page): Locator {
    return page.getByRole('main').getByRole('listitem');
}

function statusOf(item: Locator): Locator {
    return item.locator('[data-tone]').first();
}

async function fillLighthouse(page: Page): Promise<void> {
    await page.getByLabel('Prompt').fill(LIGHTHOUSE_PROMPT);
    await page.locator('label', { hasText: 'High' }).click();
    await page.locator('label', { hasText: 'Landscape' }).click();
    await page.getByRole('button', { name: 'Add reference image' }).click();
    await page.getByLabel('Reference image 1', { exact: true }).fill('https://example.com/style.png');
    // A blank row is left out of both the estimate and the submission (AC 3).
    await page.getByRole('button', { name: 'Add reference image' }).click();
}

/** Live status arrives in PE-012, so History is reloaded until the badge reads `status`. */
async function expectStatusAfterReloads(page: Page, item: Locator, status: RegExp): Promise<void> {
    await expect(async () => {
        await page.reload();
        await expect(statusOf(item)).toHaveText(status, { timeout: 1_000 });
    }).toPass({ timeout: 30_000 });
}

test('submits Lighthouse at the price it showed and lands on History with it highlighted once', async ({ page }, testInfo) => {
    await registerNewUser(page, testInfo);

    await fillLighthouse(page);
    await expect(headline(page)).toContainText('$0.3478 – $0.4572', { useInnerText: true });

    await generateButton(page).click();

    await expect(page).toHaveURL('/generations');
    const item = historyItems(page).filter({ hasText: 'Just submitted' });
    await expect(item).toHaveCount(1);
    await expect(item.locator('[data-prompt]')).toHaveText(LIGHTHOUSE_PROMPT);
    await expect(item.locator('[data-estimate]')).toHaveText('Est. $0.3478 – $0.4572', { useInnerText: true });
    await expect(item.locator('[data-meta]')).toHaveText('High · 1536×1024 · 1 reference');
    await expect(statusOf(item)).toHaveText(/^(Queued|Processing|Succeeded)$/);

    await page.reload();

    await expect(historyItems(page)).toHaveCount(1);
    await expect(page.getByText('Just submitted')).toHaveCount(0);
});

test('shows a [blocked] prompt as Failed on History after a reload', async ({ page }, testInfo) => {
    await registerNewUser(page, testInfo);

    await page.getByLabel('Prompt').fill('A [blocked] castle');
    await expect(page.getByRole('radio', { name: 'Medium' })).toBeChecked();
    await expect(page.getByRole('radio', { name: 'Square' })).toBeChecked();
    await expect(headline(page)).toContainText('$0.0582 – $0.0863', { useInnerText: true });

    await generateButton(page).click();

    await expect(page).toHaveURL('/generations');
    const item = historyItems(page).first();
    await expect(item.locator('[data-estimate]')).toHaveText('Est. $0.0582 – $0.0863', { useInnerText: true });
    await expect(item.locator('[data-meta]')).toHaveText('Medium · 1024×1024 · No references');
    await expectStatusAfterReloads(page, item, /^Failed$/);
});

test('a double click creates one generation, and coming back for another creates a second', async ({ page }, testInfo) => {
    await registerNewUser(page, testInfo);

    await page.getByLabel('Prompt').fill(LIGHTHOUSE_PROMPT);
    await generateButton(page).dblclick();

    await expect(page).toHaveURL('/generations');
    await expect(historyItems(page)).toHaveCount(1);

    await page.goBack();
    await expect(page).toHaveURL('/dashboard');
    await page.getByLabel('Prompt').fill(LIGHTHOUSE_PROMPT);
    await generateButton(page).click();

    await expect(page).toHaveURL('/generations');
    await expect(historyItems(page)).toHaveCount(2);
});
