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

/**
 * Each token line of the breakdown reads as one table row: its label and its cost start
 * on the same line, rather than the cost stacking under the tokens as on a phone, and
 * its exact/estimated badge sits on the line of its token count instead of wrapping below.
 */
async function expectOneRowPerTokenLine(page: Page): Promise<void> {
    const rows = estimatePanel(page).locator('tbody').first().locator('tr');
    const misaligned = await rows.evaluateAll((elements) =>
        elements
            .map((row) => {
                const label = row.querySelector('th')!;
                const tokens = row.querySelector('td')!;
                const cost = row.querySelector('td:last-child')!;
                const count = tokens.querySelector('span')!.getBoundingClientRect();
                const badge = tokens.querySelector('span + span:not(.block)')?.getBoundingClientRect();

                return {
                    label: label.textContent!.trim(),
                    costOffset: cost.getBoundingClientRect().top - label.getBoundingClientRect().top,
                    badgeBelowCount: badge === undefined ? false : badge.top >= count.bottom,
                };
            })
            .filter((row) => Math.abs(row.costOffset) > 0.5 || row.badgeBelowCount),
    );

    expect(await rows.count(), 'token lines').toBeGreaterThan(0);
    expect(misaligned, 'token lines whose cost leaves the label row or whose badge wraps under its count').toStrictEqual([]);
}

function headline(page: Page): Locator {
    return estimatePanel(page).locator('[data-headline]');
}

/** The headline, read as rendered so closed exact-value tips are not part of it, and what the live region announces. */
async function expectEstimate(page: Page, range: string, typical: string): Promise<void> {
    await expect(headline(page)).toHaveText(`${range} typically ${typical}`, { useInnerText: true });
    await expect(estimatePanel(page).locator('[aria-live="polite"]')).toHaveText(
        `Estimated cost ${range.replace(' – ', ' to ')}, typically ${typical}`,
    );
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

    const max = headline(page).getByRole('button', { name: '$0.4476' });
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

    const maxWithReference = headline(page).getByRole('button', { name: '$0.4571' });
    await maxWithReference.focus();
    await expect(page.getByRole('tooltip', { name: '$0.457137500' })).toBeVisible();
    await expect(maxWithReference).toHaveAccessibleDescription('$0.457137500');
    await expect(page.getByText('Enter an http:// or https:// image URL.')).toHaveCount(0);

    await expectNoHorizontalScroll(page);
    await expectBreakdownInsideCard(page);

    if (testInfo.project.name !== 'mobile') {
        await expectOneRowPerTokenLine(page);
    }

    // AC11 at the narrowest phone width, where the breakdown is tightest.
    await page.setViewportSize({ width: 375, height: 812 });
    await expectNoHorizontalScroll(page);
    await expectBreakdownInsideCard(page);

    expect(requests, 'XHR or fetch requests while editing').toStrictEqual([]);
});

test('a tap toggles the exact value, and a tap elsewhere closes it', async ({ page, hasTouch }) => {
    test.skip(!hasTouch, 'touch only: the mobile project emulates a touch screen');

    await signIn(page, DEMO_USER.email, DEMO_USER.password);
    await expect(page).toHaveURL('/dashboard');
    await expectEstimate(page, '$0.0581 – $0.0863', '$0.0722');

    const max = headline(page).getByRole('button', { name: '$0.0863' });
    const exactMax = page.getByRole('tooltip', { name: '$0.086250000' });

    await max.tap();
    await expect(exactMax).toBeVisible();
    // Touch emulation fires `mouseleave` about 14ms after a tap; the tip must outlast it.
    await page.waitForTimeout(500);
    await expect(exactMax).toBeVisible();
    await expect(max).toHaveAccessibleDescription('$0.086250000');

    await max.tap();
    await expect(exactMax).toBeHidden();
    await expect(max, 'closed, the exact value still describes the amount').toHaveAccessibleDescription('$0.086250000');

    await max.tap();
    await expect(exactMax).toBeVisible();
    await page.getByRole('heading', { level: 1, name: 'New image' }).tap();
    await expect(exactMax).toBeHidden();
});

test('a hovered exact value stays open while the mouse moves onto it, and Escape closes it', async ({ page, hasTouch }) => {
    test.skip(hasTouch, 'mouse only: the desktop project has a hovering pointer');

    await signIn(page, DEMO_USER.email, DEMO_USER.password);
    await expect(page).toHaveURL('/dashboard');
    await expectEstimate(page, '$0.0581 – $0.0863', '$0.0722');

    const max = headline(page).getByRole('button', { name: '$0.0863' });
    const exactMax = page.getByRole('tooltip', { name: '$0.086250000' });

    await max.hover();
    await expect(exactMax).toBeVisible();

    // Straight up from the amount, across the space between it and the tip, to the tip's middle.
    const amountBox = (await max.boundingBox())!;
    const tipBox = (await exactMax.boundingBox())!;
    const x = amountBox.x + Math.min(amountBox.width, tipBox.width) / 2;
    await page.mouse.move(x, tipBox.y + tipBox.height / 2, { steps: 10 });
    await expect(exactMax).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(exactMax).toBeHidden();
    await expect(max).not.toBeFocused();
});
