import { defineConfig, devices } from '@playwright/test';

/**
 * The opt-in production smoke (`make smoke-production`). It lives apart from
 * `tests/e2e`, so `make e2e` and CI's `npx playwright test` never run it.
 *
 * It refuses to load without an https:// base URL and the reviewer
 * credentials, so it can't fall back to the local app. Traces and videos are
 * off because a trace records `fill()` values, which would store the
 * reviewer password.
 *
 * For the same reason the failure page snapshot is off: on a failed test,
 * Playwright writes an ARIA snapshot of the page to
 * `test-results/…/error-context.md`, and that snapshot holds every input's
 * value, the password field's included. `PLAYWRIGHT_NO_COPY_PROMPT` is the
 * switch Playwright 1.65 checks before taking it; workers inherit it.
 */
process.env.PLAYWRIGHT_NO_COPY_PROMPT = '1';

const baseURL = process.env.E2E_BASE_URL ?? '';

if (!baseURL.startsWith('https://')) {
    throw new Error('Set E2E_BASE_URL to the https:// production URL to run the production smoke.');
}

if (!process.env.E2E_REVIEWER_EMAIL || !process.env.E2E_REVIEWER_PASSWORD) {
    throw new Error('Set E2E_REVIEWER_EMAIL and E2E_REVIEWER_PASSWORD to run the production smoke.');
}

export default defineConfig({
    testDir: 'tests/smoke',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: true,
    reporter: 'list',
    use: {
        baseURL,
        trace: 'off',
        video: 'off',
        screenshot: 'only-on-failure',
    },
    projects: [{ name: 'production', use: { ...devices['Desktop Chrome'] } }],
});
