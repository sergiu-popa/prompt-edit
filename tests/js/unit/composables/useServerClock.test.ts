import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';

import { forgetServerClock, useServerClock } from '@/composables/useServerClock';

const SERVER_TIME = '2026-11-19T12:00:00.000Z';
const SERVER_MS = Date.parse(SERVER_TIME);

/** The browser's clock reads 2 minutes ahead of the server's. */
const BROWSER_SKEW_MS = 2 * 60_000;

describe('useServerClock', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(SERVER_MS + BROWSER_SKEW_MS);
        forgetServerClock();
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

    it('keeps the server-aligned now when a page remounts with an older, restored server time', () => {
        const fresh = effectScope();
        fresh.run(() => useServerClock(SERVER_TIME));
        fresh.stop();

        // The user is away for 10 minutes, then Back restores the page with the server time it had.
        vi.advanceTimersByTime(10 * 60_000);
        const restored = effectScope();
        const now = restored.run(() => useServerClock(SERVER_TIME))!;

        expect(now.value).toBe(SERVER_MS + 10 * 60_000);

        restored.stop();
    });

    it('re-measures from a newer server time on a later page', () => {
        const first = effectScope();
        first.run(() => useServerClock(SERVER_TIME));
        first.stop();

        // The browser's clock has since been corrected; the next response shows it.
        vi.setSystemTime(SERVER_MS + 60_000);
        const second = effectScope();
        const now = second.run(() => useServerClock(new Date(SERVER_MS + 60_000).toISOString()))!;

        expect(now.value).toBe(SERVER_MS + 60_000);

        second.stop();
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
