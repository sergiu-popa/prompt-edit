import type { TestInfo } from '@playwright/test';

/**
 * Mirrors Database\Seeders\DemoUserSeeder; `make seed` (or CI's seed step) creates it.
 */
export const DEMO_USER = {
    name: 'Demo User',
    email: 'demo@promptedit.test',
    password: 'promptedit-demo',
} as const;

/**
 * An email no other test, project, worker or run uses. Wrong-credential tests use one
 * too, so their failures never count towards the demo user's sign-in throttle.
 */
export function uniqueEmail(testInfo: TestInfo): string {
    return `e2e-${testInfo.project.name}-${testInfo.workerIndex}-${testInfo.retry}-${Date.now()}@promptedit.test`;
}
