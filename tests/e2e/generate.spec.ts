import type { Locator, Page } from '@playwright/test';

import { expect, test } from './fixtures';
import { DEMO_USER, signIn } from './support/auth';
import { expectInsideContentBox, expectNoHorizontalScroll } from './support/layout';

const LIGHTHOUSE_PROMPT = 'A lighthouse on a cliff at dawn, in oils';

function estimatePanel(page: Page): Locator {
    return page.getByRole('complementary');
}

/** Every amount, and the breakdown itself, stays inside the estimate card. */
async function expectBreakdownInsideCard(page: Page): Promise<void> {
    const card = estimatePanel(page).getByRole('region', { name: 'Estimated cost' });

    await expectInsideContentBox(card, card.locator('table, button'));
}

/** The live headline, read as rendered, so closed exact-value tips are not part of it. */
async function expectEstimate(page: Page, range: string, typical: string): Promise<void> {
    const headline = estimatePanel(page).locator('[aria-live="polite"]');

    await expect(headline).toHaveText(`${range} typically ${typical}`, { useInnerText: true });
}

/** Every XHR or fetch the page sends from now on. */
function recordRequests(page: Page): string[] {
    const requests: string[] = [];

    page.on('request', (request) => {
        if (['xhr', 'fetch'].includes(request.resourceType())) {
            requests.push(`${request.method()} ${request.url()}`);
        }
    });

    return requests;
}

test('estimates the cost live as the prompt, quality, size and references change, with no request', async ({ page }, testInfo) => {
    await signIn(page, DEMO_USER.email, DEMO_USER.password);

    await expect(page).toHaveURL('/dashboard');
    await expect(page.getByRole('heading', { level: 1, name: 'New image' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Generate' })).toHaveAttribute('aria-current', 'page');
    await expectEstimate(page, '$0.0581 – $0.0863', '$0.0722');
    await expect(estimatePanel(page)).toContainText('0 tokens. Start typing your prompt.');

    const requests = recordRequests(page);

    await page.getByLabel('Prompt').fill(LIGHTHOUSE_PROMPT);
    await expect(page.getByText('40 / 4,000')).toBeVisible();
    await expectEstimate(page, '$0.0582 – $0.0863', '$0.0723');

    // AC8 by keyboard: the arrow keys move the choice within the group.
    await page.getByRole('radio', { name: 'Medium' }).focus();
    await page.keyboard.press('ArrowRight');
    await expect(page.getByRole('radio', { name: 'High' })).toBeChecked();
    await expectEstimate(page, '$0.2307 – $0.3401', '$0.2854');

    await page.locator('label', { hasText: 'Landscape' }).click();
    await expect(page.getByRole('radio', { name: 'Landscape' })).toBeChecked();
    await expectEstimate(page, '$0.3382 – $0.4476', '$0.3929');

    const max = estimatePanel(page).locator('[aria-live="polite"]').getByRole('button', { name: '$0.4476' });
    await max.focus();
    await expect(page.getByRole('tooltip', { name: '$0.447575000' })).toBeVisible();
    await expect(max).toHaveAccessibleDescription('$0.447575000');

    const form = await page.locator('form').boundingBox();
    const panel = await estimatePanel(page).boundingBox();
    expect(form).not.toBeNull();
    expect(panel).not.toBeNull();

    if (testInfo.project.name === 'mobile') {
        expect(panel!.y, 'the panel follows the form').toBeGreaterThanOrEqual(form!.y + form!.height);
    } else {
        expect(panel!.x, 'the panel sits beside the form').toBeGreaterThanOrEqual(form!.x + form!.width);
    }

    await expectNoHorizontalScroll(page);

    await expect(page.locator('p', { hasText: 'each' })).toHaveText('+$0.0096 each', { useInnerText: true });
    await page.getByRole('button', { name: 'Add reference image' }).click();
    await expect(page.getByLabel('Reference image 1', { exact: true })).toBeFocused();
    await page.getByLabel('Reference image 1', { exact: true }).fill('https://example.com/style.png');
    await expectEstimate(page, '$0.3477 – $0.4571', '$0.4024');

    const maxWithReference = estimatePanel(page).locator('[aria-live="polite"]').getByRole('button', { name: '$0.4571' });
    await maxWithReference.focus();
    await expect(page.getByRole('tooltip', { name: '$0.457137500' })).toBeVisible();
    await expect(maxWithReference).toHaveAccessibleDescription('$0.457137500');
    await expect(page.getByText('Enter an http:// or https:// image URL.')).toHaveCount(0);

    await expectNoHorizontalScroll(page);
    await expectBreakdownInsideCard(page);

    // AC11 at the narrowest phone width, where the breakdown is tightest.
    await page.setViewportSize({ width: 375, height: 812 });
    await expectNoHorizontalScroll(page);
    await expectBreakdownInsideCard(page);

    expect(requests, 'XHR or fetch requests while editing').toStrictEqual([]);
});
