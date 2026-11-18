import { defineConfig, devices } from '@playwright/test';

/**
 * E2E runs inside the `playwright` Docker service, which shares the app
 * container's network namespace, so the app is plain http://127.0.0.1:8080.
 * (IPv4 on purpose: `localhost` resolves to ::1 first in the container and
 * Apache only listens on IPv4.)
 */
export default defineConfig({
    testDir: 'tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8080',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'mobile', use: { ...devices['Pixel 7'] } },
    ],
});
