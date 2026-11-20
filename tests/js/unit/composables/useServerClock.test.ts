import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';

import { useServerClock } from '@/composables/useServerClock';

const SERVER_TIME = '2026-11-19T12:00:00.000Z';
const SERVER_MS = Date.parse(SERVER_TIME);

/** The browser's clock reads 2 minutes ahead of the server's. */
const BROWSER_SKEW_MS = 2 * 60_000;

describe('useServerClock', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(SERVER_MS + BROWSER_SKEW_MS);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("reads the server's time, not the skewed browser clock, and ticks every second", () => {
        const scope = effectScope();
        const now = scope.run(() => useServerClock(SERVER_TIME))!;

        expect(now.value).toBe(SERVER_MS);

        vi.advanceTimersByTime(999);
        expect(now.value).toBe(SERVER_MS);

        vi.advanceTimersByTime(1);
        expect(now.value).toBe(SERVER_MS + 1_000);

        scope.stop();
    });

    it('re-aligns when a new server time arrives', async () => {
        const serverTime = ref(SERVER_TIME);
        const scope = effectScope();
        const now = scope.run(() => useServerClock(serverTime))!;

        vi.advanceTimersByTime(3_000);
        // The server now reads 10 s later than when the page loaded, though only 3 s passed here.
        serverTime.value = new Date(SERVER_MS + 10_000).toISOString();
        await nextTick();

        expect(now.value).toBe(SERVER_MS + 10_000);

        vi.advanceTimersByTime(1_000);
        expect(now.value).toBe(SERVER_MS + 11_000);

        scope.stop();
    });

    it('stops ticking once its scope is disposed', () => {
        const scope = effectScope();
        const now = scope.run(() => useServerClock(SERVER_TIME))!;

        scope.stop();
        vi.advanceTimersByTime(5_000);

        expect(now.value).toBe(SERVER_MS);
        expect(vi.getTimerCount()).toBe(0);
    });
});
