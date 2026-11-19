import { expect, test } from './fixtures';
import { expectNoHorizontalScroll } from './support/layout';

test('renders the welcome page through Inertia', async ({ page }) => {
    await page.goto('/');

    await expect(page).toHaveTitle(/^Welcome · /);
    await expect(page.getByRole('heading', { level: 1, name: 'Generate images with Pixelforge' })).toBeVisible();
});

test('has no horizontal scroll', async ({ page }) => {
    await page.goto('/');

    await expectNoHorizontalScroll(page);
});
