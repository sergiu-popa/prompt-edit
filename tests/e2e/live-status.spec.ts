import { expect, test } from './fixtures';
import { registerNewUser } from './support/auth';
import { expectInsideContentBox, expectNoHorizontalScroll } from './support/layout';
import {
    exactChargeAndEstimate,
    expectNoReload,
    historyItems,
    markNoReload,
    PROCESSING_TIMEOUT_MS,
    SLOW_TERMINAL_TIMEOUT_MS,
    statusOf,
    submitPrompt,
    TERMINAL_TIMEOUT_MS,
} from './support/liveStatus';

const LIGHTHOUSE_PROMPT = 'A lighthouse on a cliff at dawn, oil painting';

test('a [succeed] Lighthouse turns Succeeded live, with its image and a charge within its estimate', async ({ page }, testInfo) => {
    const prompt = `[succeed] ${LIGHTHOUSE_PROMPT}`;
    await registerNewUser(page, testInfo);

    await submitPrompt(page, prompt, { quality: 'High', size: 'Landscape', referenceImage: 'https://example.com/style.png' });
    await markNoReload(page);

    const item = historyItems(page).filter({ hasText: 'Just submitted' });
    await expect(item).toHaveCount(1);
    await expect(statusOf(item)).toHaveText(/^(Queued|Processing( · \d+ s)?)$/);
    await expect(item.locator('[data-estimate]')).toHaveText('Est. $0.3478 – $0.4572', { useInnerText: true });

    await expect(statusOf(item)).toHaveText('Succeeded', { timeout: TERMINAL_TIMEOUT_MS });

    const image = item.getByRole('img', { name: prompt });
    await expect(image).toBeVisible();
    await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth > 0)).toBe(true);
    const link = item.getByRole('link', { name: `${prompt} (opens in a new tab)` });
    await expect(link).toHaveAttribute('target', '_blank');
    await expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    await expect(link).toHaveAttribute('href', /^\/fake-pixelforge\/images\//);

    await expect(item.locator('[data-charge]')).toHaveText(/^Charged \$\d+\.\d{4}$/, { useInnerText: true });
    await expect(item.locator('[data-completed]')).toHaveText(/^Completed \d{1,2}:\d{2}\s[AP]M$/, { useInnerText: true });
    const { charge, min, max } = await exactChargeAndEstimate(item);
    expect(charge).toBeGreaterThanOrEqual(min);
    expect(charge).toBeLessThanOrEqual(max);

    await expect(item).toContainText('Just submitted');
    await expectNoReload(page);

    await page.setViewportSize({ width: 375, height: 812 });
    await expectInsideContentBox(
        item.locator(':scope > div'),
        item.locator('img, [data-tone], time, [data-prompt], [data-charge], [data-estimate]'),
    );
    await expectNoHorizontalScroll(page);
});

test('a [slow] generation stays Processing with a rising count while polling, then succeeds', async ({ page }, testInfo) => {
    await registerNewUser(page, testInfo);

    await submitPrompt(page, `[slow] ${LIGHTHOUSE_PROMPT}`);
    await markNoReload(page);

    const item = historyItems(page).first();
    const status = statusOf(item);
    await expect(status).toHaveText(/^Processing · \d+ s$/, { timeout: PROCESSING_TIMEOUT_MS });

    const secondsShown = async (): Promise<number> => Number(/(\d+) s$/.exec((await status.textContent()) ?? '')?.[1] ?? -1);
    const firstCount = await secondsShown();
    await expect.poll(secondsShown).toBeGreaterThan(firstCount);

    await expect(status).toHaveText('Succeeded', { timeout: SLOW_TERMINAL_TIMEOUT_MS });
    await expectNoReload(page);
});
