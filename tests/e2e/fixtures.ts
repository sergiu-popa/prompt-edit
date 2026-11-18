import { test as base, expect } from '@playwright/test';

/**
 * Every E2E test fails if the browser logs a console error or an uncaught
 * page error, even when its own assertions pass. Third-party web fonts are
 * stubbed so a slow or unreachable font CDN can never fail a test.
 */
export const test = base.extend<{ failOnBrowserErrors: void }>({
    failOnBrowserErrors: [
        async ({ page }, use) => {
            const errors: string[] = [];

            await page.route('https://fonts.bunny.net/**', (route) => route.fulfill({ status: 200, contentType: 'text/css', body: '' }));

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
