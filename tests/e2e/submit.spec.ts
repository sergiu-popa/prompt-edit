import type { Locator, Page } from '@playwright/test';

import { expect, test } from './fixtures';
import { registerNewUser } from './support/auth';
import { expectNoReload, historyItems, LOCAL_FAILURE_TIMEOUT_MS, markNoReload, statusOf } from './support/liveStatus';

const LIGHTHOUSE_PROMPT = 'A lighthouse on a cliff at dawn, oil painting';

function headline(page: Page): Locator {
    return page.getByRole('complementary').locator('[data-headline]');
}

function generateButton(page: Page): Locator {
    return page.getByRole('button', { name: 'Generate', exact: true });
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
    await expect(statusOf(item)).toHaveText(/^(Queued|Processing( · \d+ s)?|Succeeded)$/);

    await page.reload();

    await expect(historyItems(page)).toHaveCount(1);
    await expect(page.getByText('Just submitted')).toHaveCount(0);
});

test('a [blocked] prompt turns Failed live, with the content-policy copy and no charge', async ({ page }, testInfo) => {
    await registerNewUser(page, testInfo);

    await page.getByLabel('Prompt').fill('A [blocked] castle');
    await expect(page.getByRole('radio', { name: 'Medium' })).toBeChecked();
    await expect(page.getByRole('radio', { name: 'Square' })).toBeChecked();
    await expect(headline(page)).toContainText('$0.0582 – $0.0863', { useInnerText: true });

    await generateButton(page).click();

    await expect(page).toHaveURL('/generations');
    await markNoReload(page);
    const item = historyItems(page).first();
    await expect(item.locator('[data-estimate]')).toHaveText('Est. $0.0582 – $0.0863', { useInnerText: true });
    await expect(item.locator('[data-meta]')).toHaveText('Medium · 1024×1024 · No references');

    await expect(statusOf(item)).toHaveText('Failed', { timeout: LOCAL_FAILURE_TIMEOUT_MS });
    await expect(item.locator('[data-failure-message]')).toHaveText(
        "Prompt rejected by Pixelforge's content policy. Edit the prompt and try again.",
    );
    await expect(item.locator('[data-charge]')).toHaveText('Not charged');
    await expect(item.locator('[data-estimate]')).toHaveText('Est. $0.0582 – $0.0863', { useInnerText: true });
    await expectNoReload(page);
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
