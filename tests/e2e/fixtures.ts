import { test as base, expect } from '@playwright/test';

/**
 * Every E2E test fails if the browser logs a console error or an uncaught
 * page error, even when its own assertions pass.
 */
export const test = base.extend<{ failOnBrowserErrors: void }>({
    failOnBrowserErrors: [
        async ({ page }, use) => {
            const errors: string[] = [];

            page.on('pageerror', (error) => errors.push(error.message));
            page.on('console', (message) => {
                if (message.type() === 'error') {
                    errors.push(message.text());
                }
            });

            await use();

            expect(errors, 'browser console errors').toEqual([]);
        },
        { auto: true },
    ],
});

export { expect };
