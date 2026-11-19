import type { Page } from '@playwright/test';
import { expect } from '@playwright/test';

export async function expectNoHorizontalScroll(page: Page): Promise<void> {
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

    expect(overflow, 'horizontal overflow in px').toBe(0);
}
