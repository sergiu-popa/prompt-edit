import type { Locator } from '@playwright/test';

import { expect, test } from './fixtures';
import { DEMO_USER, registerNewUser, signIn, uniqueEmail } from './support/auth';
import { expectNoHorizontalScroll } from './support/layout';

test('signs up, lands on the signed-in home and signs out', async ({ page }, testInfo) => {
    // One unbroken 60-character word: the header must truncate it, not scroll.
    const name = `Augusta${'x'.repeat(53)}`;

    await page.goto('/');
    await page.getByRole('link', { name: 'Create account' }).click();
    await expect(page).toHaveURL('/register');

    await page.getByLabel('Name').fill(name);
    await page.getByLabel('Email').fill(uniqueEmail(testInfo));
    await page.getByLabel('Password', { exact: true }).fill('correct-password');
    await page.getByLabel('Confirm password').fill('correct-password');
    await page.getByRole('button', { name: 'Create account' }).click();

    await expect(page).toHaveURL('/dashboard');
    await expect(page.getByRole('banner')).toContainText(name);
    await expect(page.getByRole('heading', { level: 1, name: 'New image' })).toBeVisible();
    await expectNoHorizontalScroll(page);

    await page.getByRole('button', { name: 'Sign out' }).click();

    await expect(page).toHaveURL('/');
    await expect(page.getByRole('link', { name: 'Sign in' })).toBeVisible();

    await page.goto('/dashboard');
    await expect(page).toHaveURL('/login');
});

test('an expired session on Generate lands on Sign in with the session-expired notice', async ({ page, context }, testInfo) => {
    await registerNewUser(page, testInfo);
    await page.getByLabel('Prompt').fill('[succeed] a lighthouse at dawn');

    // The session cookie goes, as when it expires; Chromium's same-origin fetch metadata
    // takes the guest path, which gets the same notice as a 419 (D-60).
    await context.clearCookies();
    await page.getByRole('button', { name: 'Generate' }).click();

    await expect(page).toHaveURL('/login');
    await expect(page.getByRole('status')).toHaveText('Your session expired. Please try again.');
    await expectNoHorizontalScroll(page);
});

test('shows the credentials message for a wrong password', async ({ page }, testInfo) => {
    const email = uniqueEmail(testInfo);
    const message = 'These credentials do not match our records.';

    await signIn(page, email, 'wrong-password');

    const emailInput = page.getByLabel('Email');
    await expect(emailInput).toHaveAccessibleDescription(message);
    await expect(emailInput).toHaveAttribute('aria-invalid', 'true');
    await expect(emailInput).toBeFocused();
    await expect(emailInput).toHaveValue(email);
    await expect(page.getByLabel('Password')).toHaveValue('');
    await expect(page).toHaveURL('/login');
});

test('signs the demo user in and out with the keyboard alone', async ({ page }) => {
    await page.goto('/login');

    await page.getByLabel('Email').focus();
    await page.keyboard.type(DEMO_USER.email);
    await page.keyboard.press('Tab');
    await expect(page.getByLabel('Password')).toBeFocused();
    await page.keyboard.type(DEMO_USER.password);
    await page.keyboard.press('Enter');

    await expect(page).toHaveURL('/dashboard');
    await expect(page.getByRole('banner')).toContainText(DEMO_USER.name);

    // Tab from the brand link, the header's first stop, past Generate, History and the balance
    // proves Sign out is in the tab order.
    const signOut = page.getByRole('button', { name: 'Sign out' });
    await page.getByRole('link', { name: 'PromptEdit' }).focus();
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Generate' })).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'History' })).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(page.getByRole('banner').locator('[data-balance] button')).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(signOut).toBeFocused();
    await page.keyboard.press('Enter');

    await expect(page).toHaveURL('/');
});

test('the header keeps the brand, the name and Sign out on one row, even with a long name', async ({ page }, testInfo) => {
    const name = `Augusta${'x'.repeat(53)}`;

    await page.goto('/register');
    await page.getByLabel('Name').fill(name);
    await page.getByLabel('Email').fill(uniqueEmail(testInfo));
    await page.getByLabel('Password', { exact: true }).fill('correct-password');
    await page.getByLabel('Confirm password').fill('correct-password');
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page).toHaveURL('/dashboard');

    const banner = page.getByRole('banner');
    const middleOf = async (locator: Locator): Promise<number> => {
        const box = (await locator.boundingBox())!;

        return Math.round(box.y + box.height / 2);
    };
    const brand = await middleOf(banner.getByRole('link', { name: 'PromptEdit' }));

    // On a phone the nav takes the second row; the name must not take a third.
    expect(await middleOf(banner.getByText(name)), 'the name on the brand row').toBe(brand);
    expect(await middleOf(banner.getByRole('button', { name: 'Sign out' })), 'Sign out on the brand row').toBe(brand);
    await expectNoHorizontalScroll(page);
});

test('the auth pages and the signed-in home have no horizontal scroll', async ({ page }) => {
    for (const path of ['/login', '/register']) {
        await page.goto(path);
        await expectNoHorizontalScroll(page);
    }

    await signIn(page, DEMO_USER.email, DEMO_USER.password);

    await expect(page).toHaveURL('/dashboard');
    await expectNoHorizontalScroll(page);
    await expect(page.getByRole('button', { name: 'Sign out' })).toBeInViewport();
});
