import type { Locator, Page } from '@playwright/test';
import { expect } from '@playwright/test';

export async function expectNoHorizontalScroll(page: Page): Promise<void> {
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

    expect(overflow, 'horizontal overflow in px').toBe(0);
}

/**
 * Every element `items` matches sits inside `container`'s content box (inside its border
 * and padding). Catches content that overflows a card without scrolling the page.
 */
export async function expectInsideContentBox(container: Locator, items: Locator): Promise<void> {
    const contentBox = await container.evaluate((element) => {
        const rect = element.getBoundingClientRect();
        const style = getComputedStyle(element);

        return {
            left: rect.left + parseFloat(style.borderLeftWidth) + parseFloat(style.paddingLeft),
            right: rect.right - parseFloat(style.borderRightWidth) - parseFloat(style.paddingRight),
        };
    });
    const boxes = await items.evaluateAll((elements) =>
        elements.map((element) => {
            const rect = element.getBoundingClientRect();

            return { text: (element.textContent ?? '').trim().slice(0, 40), left: rect.left, right: rect.right };
        }),
    );

    expect(boxes.length, 'elements to measure').toBeGreaterThan(0);

    const outside = boxes
        .filter((box) => box.left < contentBox.left - 0.1 || box.right > contentBox.right + 0.1)
        .map((box) => `"${box.text}" spans ${box.left}–${box.right}`);

    expect(outside, `outside the content box ${contentBox.left}–${contentBox.right}`).toStrictEqual([]);
}
