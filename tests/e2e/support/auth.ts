import type { Page, TestInfo } from '@playwright/test';

/**
 * Mirrors Database\Seeders\DemoUserSeeder; `make seed` (or CI's seed step) creates it.
 */
export const DEMO_USER = {
    name: 'Demo User',
    email: 'demo@promptedit.test',
    password: 'promptedit-demo',
} as const;

/**
 * Mirrors Database\Seeders\OperatorUserSeeder: the local operator, who may open Report.
 */
export const OPERATOR_USER = {
    name: 'Operator',
    email: 'operator@promptedit.test',
    password: 'promptedit-operator',
} as const;

/**
 * An email no other test, project, worker or run uses. Wrong-credential tests use one
 * too, so their failures never count towards the demo user's sign-in throttle.
 */
export function uniqueEmail(testInfo: TestInfo): string {
    return `e2e-${testInfo.project.name}-${testInfo.workerIndex}-${testInfo.retry}-${Date.now()}@promptedit.test`;
}

/**
 * Signs in through the form, as a user would; it does not wait for the landing page.
 */
export async function signIn(page: Page, email: string, password: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
}

/**
 * Registers a fresh user through the form and waits for the signed-in home, so a test
 * that writes data (or expects none) never shares an account with another test.
 *
 * Dev and E2E accounts start at $0.0100, below any request's max (D-71), so by default
 * the user buys the $1.00 demo pack through the Buy credits page first, starting the
 * test with $1.0100. `topUp: false` keeps the starter alone, for the blocked flow.
 */
export async function registerNewUser(page: Page, testInfo: TestInfo, { topUp = true }: { topUp?: boolean } = {}): Promise<string> {
    const email = uniqueEmail(testInfo);

    await page.goto('/register');
    await page.getByLabel('Name').fill('E2E User');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('correct-password');
    await page.getByLabel('Confirm password').fill('correct-password');
    await page.getByRole('button', { name: 'Create account' }).click();
    await page.waitForURL('/dashboard');

    if (topUp) {
        await page.goto('/credits');
        await page.getByRole('button', { name: 'Add $1.00' }).click();
        await page.getByRole('status').filter({ hasText: '$1.00 added.' }).waitFor();
        await page.goto('/dashboard');
    }

    return email;
}
