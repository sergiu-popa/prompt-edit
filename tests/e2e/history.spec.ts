import { expect, test } from './fixtures';
import { registerNewUser } from './support/auth';
import { expectNoHorizontalScroll } from './support/layout';

test('a new user finds History empty, marked current, and is led to Generate', async ({ page }, testInfo) => {
    await registerNewUser(page, testInfo);

    await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'History' }).click();

    await expect(page).toHaveURL('/generations');
    await expect(page).toHaveTitle(/^History/);
    await expect(page.getByRole('heading', { level: 1, name: 'History' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'History' })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByRole('link', { name: 'Generate' })).not.toHaveAttribute('aria-current');
    await expect(page.getByText('No generations yet.')).toBeVisible();
    await expect(page.getByText('Write a prompt and see the price before you pay.')).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Pagination' })).toHaveCount(0);
    await expectNoHorizontalScroll(page);

    await page.getByRole('link', { name: 'Create your first image →' }).click();

    await expect(page).toHaveURL('/dashboard');
    await expect(page.getByRole('heading', { level: 1, name: 'New image' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Generate' })).toHaveAttribute('aria-current', 'page');
});
