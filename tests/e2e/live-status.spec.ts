import { expect, test } from './fixtures';
import { registerNewUser } from './support/auth';
import { expectInsideContentBox, expectNoHorizontalScroll } from './support/layout';
import {
    exactChargeAndEstimate,
    EXPIRY_TIMEOUT_MS,
    expectNoReload,
    FAKE_EXPIRE_TTL_MS,
    historyItems,
    markNoReload,
    OVERLOADED_FAILURE_TIMEOUT_MS,
    PROCESSING_TIMEOUT_MS,
    RETRY_NOTE_TIMEOUT_MS,
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

test('a [fail] generation turns Failed live, explained and not charged', async ({ page }, testInfo) => {
    await registerNewUser(page, testInfo);

    await submitPrompt(page, `[fail] ${LIGHTHOUSE_PROMPT}`);
    await markNoReload(page);

    const item = historyItems(page).first();
    await expect(statusOf(item)).toHaveText('Failed', { timeout: TERMINAL_TIMEOUT_MS });
    await expect(item.locator('[data-failure-message]')).toHaveText(
        "Pixelforge couldn't produce an image for this prompt. Try rephrasing it.",
    );
    await expect(item.locator('[data-charge]')).toHaveText('Not charged');
    await expect(item.locator('[data-estimate]')).toHaveText(/^Est\. \$\d+\.\d{4} – \$\d+\.\d{4}$/, { useInnerText: true });
    await expect(item.getByRole('img')).toHaveCount(0);
    await expectNoReload(page);
});

test('a [duplicate] generation succeeds as one History item, charged once within its estimate', async ({ page }, testInfo) => {
    await registerNewUser(page, testInfo);

    await submitPrompt(page, `[duplicate] ${LIGHTHOUSE_PROMPT}`);
    await markNoReload(page);

    const item = historyItems(page).first();
    await expect(statusOf(item)).toHaveText('Succeeded', { timeout: TERMINAL_TIMEOUT_MS });
    await expect(historyItems(page)).toHaveCount(1);
    await expect(item.locator('[data-charge]')).toHaveCount(1);

    const { charge, min, max } = await exactChargeAndEstimate(item);
    expect(charge).toBeGreaterThanOrEqual(min);
    expect(charge).toBeLessThanOrEqual(max);
    await expectNoReload(page);
});

test('an [expire] image turns into an Image expired placeholder once its short TTL passes, with no reload', async ({ page }, testInfo) => {
    await registerNewUser(page, testInfo);

    await submitPrompt(page, `[expire] ${LIGHTHOUSE_PROMPT}`);
    await markNoReload(page);

    const item = historyItems(page).first();
    await expect(statusOf(item)).toHaveText('Succeeded', { timeout: TERMINAL_TIMEOUT_MS });

    // The fixture's console-error check proves the dead URL is never requested.
    await expect(item.getByText('Image expired', { exact: true })).toBeVisible({ timeout: EXPIRY_TIMEOUT_MS });
    await expect(item.locator('[data-image-note]')).toHaveText('Images are kept for 24 h after completion.');
    await expect(item.locator('img')).toHaveCount(0);
    await expect(item.locator('[data-charge]')).toHaveText(/^Charged \$\d+\.\d{4}$/, { useInnerText: true });
    await expectNoReload(page);
});

test('an [expire] image that expired while the user was away reads Image expired after Back', async ({ page }, testInfo) => {
    await registerNewUser(page, testInfo);

    await submitPrompt(page, `[expire] ${LIGHTHOUSE_PROMPT}`);
    await markNoReload(page);

    const item = historyItems(page).first();
    await expect(statusOf(item)).toHaveText('Succeeded', { timeout: TERMINAL_TIMEOUT_MS });
    const succeededAt = Date.now();

    await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Generate' }).click();
    await expect(page).toHaveURL('/dashboard');
    await expect.poll(() => Date.now(), { timeout: EXPIRY_TIMEOUT_MS }).toBeGreaterThan(succeededAt + FAKE_EXPIRE_TTL_MS);

    // Back remounts History from its saved props, unasked. A stale clock would render the
    // image until it ticks past the expiry, so the card is read once, as soon as it shows.
    await page.goBack();
    await expect(statusOf(item)).toHaveText('Succeeded');
    expect(await item.locator('img').count(), 'no image is rendered from the restored props').toBe(0);
    await expect(item.getByText('Image expired', { exact: true })).toBeVisible();
    await expectNoReload(page);
});

test('an [overloaded] prompt reads retrying, then fails free once the overload deadline passes, with no reload', async ({
    page,
}, testInfo) => {
    test.setTimeout(RETRY_NOTE_TIMEOUT_MS + OVERLOADED_FAILURE_TIMEOUT_MS + 30_000);
    await registerNewUser(page, testInfo);

    await submitPrompt(page, 'A lighthouse at dusk [overloaded]', { quality: 'Medium', size: 'Square' });
    await markNoReload(page);

    const item = historyItems(page).first();
    const note = item.locator('[data-status-note]');
    await expect(note).toHaveText('Pixelforge is busy — retrying automatically', { timeout: RETRY_NOTE_TIMEOUT_MS });
    await expect(statusOf(item)).toHaveText('Queued');
    await expect(item.locator('[data-estimate]')).toHaveText('Est. $0.0582 – $0.0863', { useInnerText: true });

    await page.setViewportSize({ width: 375, height: 812 });
    await expectInsideContentBox(
        item.locator(':scope > div'),
        item.locator('[data-tone], [data-prompt], [data-status-note], [data-estimate]'),
    );
    await expectNoHorizontalScroll(page);

    await expect(statusOf(item)).toHaveText('Failed', { timeout: OVERLOADED_FAILURE_TIMEOUT_MS });
    await expect(item.locator('[data-failure-message]')).toHaveText(
        "Pixelforge was overloaded and didn't accept this request. You were not charged.",
    );
    await expect(item.locator('[data-charge]')).toHaveText('Not charged');
    await expect(item.locator('[data-estimate]')).toHaveText('Est. $0.0582 – $0.0863', { useInnerText: true });
    await expect(note).toHaveCount(0);
    await expectNoReload(page);
});
