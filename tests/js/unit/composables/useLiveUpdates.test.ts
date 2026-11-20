import type { ReloadOptions } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import type { EffectScope } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';

import { useLiveUpdates } from '@/composables/useLiveUpdates';

/** One poll's options, so a test can finish it as Inertia would. */
let polls: ReloadOptions[] = [];
let scope: EffectScope;

function startLiveUpdates(active: boolean | (() => boolean) = true) {
    scope = effectScope();

    return scope.run(() => useLiveUpdates({ only: ['generations'], active }))!;
}

function lastPoll(): ReloadOptions {
    const poll = polls.at(-1);

    if (!poll) {
        throw new Error('No poll was made.');
    }

    return poll;
}

function succeed(poll = lastPoll()): void {
    poll.onSuccess?.({} as never);
    poll.onFinish?.({} as never);
}

function failWithHttpError(poll = lastPoll()): unknown {
    const result = poll.onHttpException?.({ status: 503 } as never);
    poll.onFinish?.({} as never);

    return result;
}

function failWithNetworkError(poll = lastPoll()): unknown {
    const result = poll.onNetworkError?.(new Error('offline'));
    poll.onFinish?.({} as never);

    return result;
}

function cancel(poll = lastPoll()): void {
    poll.onCancel?.();
    poll.onFinish?.({} as never);
}

/** Waits `ms`, and says whether exactly one new poll started at its end and none before. */
function expectNextPollAfter(ms: number): void {
    const before = polls.length;

    vi.advanceTimersByTime(ms - 1);
    expect(polls, `no poll before ${ms} ms`).toHaveLength(before);

    vi.advanceTimersByTime(1);
    expect(polls, `one poll at ${ms} ms`).toHaveLength(before + 1);
}

describe('useLiveUpdates', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        polls = [];
        vi.spyOn(router, 'reload').mockImplementation((options = {}) => {
            polls.push(options);
        });
    });

    afterEach(() => {
        scope.stop();
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('reloads only the given props 3 s after it starts, then 3 s after each success', () => {
        startLiveUpdates();

        expectNextPollAfter(3_000);
        expect(lastPoll().only).toStrictEqual(['generations']);

        succeed();

        expectNextPollAfter(3_000);
    });

    it('never polls while nothing is active', () => {
        startLiveUpdates(false);

        vi.advanceTimersByTime(60_000);

        expect(polls).toHaveLength(0);
    });

    it('stops once active turns false, as when every generation is terminal', async () => {
        const active = ref(true);
        startLiveUpdates(() => active.value);
        vi.advanceTimersByTime(3_000);

        active.value = false;
        succeed();
        await nextTick();
        vi.advanceTimersByTime(60_000);

        expect(polls).toHaveLength(1);
    });

    it('starts again when active turns true', async () => {
        const active = ref(false);
        startLiveUpdates(() => active.value);

        active.value = true;
        await nextTick();

        expectNextPollAfter(3_000);
    });

    it('never starts a second poll while one is in flight', () => {
        startLiveUpdates();
        vi.advanceTimersByTime(3_000);

        vi.advanceTimersByTime(120_000);

        expect(polls).toHaveLength(1);
    });

    it('keeps the last state without an error dialog, and backs off 6, 12, 24, then 30 s', () => {
        const { isPaused } = startLiveUpdates();
        vi.advanceTimersByTime(3_000);

        expect(failWithHttpError()).toBe(false);
        expect(isPaused.value).toBe(true);
        expectNextPollAfter(6_000);

        expect(failWithNetworkError()).toBe(false);
        expect(isPaused.value).toBe(true);
        expectNextPollAfter(12_000);

        failWithHttpError();
        expectNextPollAfter(24_000);

        failWithHttpError();
        expectNextPollAfter(30_000);

        failWithNetworkError();
        expectNextPollAfter(30_000);
        expect(isPaused.value).toBe(true);
    });

    it('counts any finish without a success as a failure, such as a version or location answer', () => {
        const { isPaused } = startLiveUpdates();
        vi.advanceTimersByTime(3_000);

        lastPoll().onFinish?.({} as never);

        expect(isPaused.value).toBe(true);
        expectNextPollAfter(6_000);
    });

    it('recovers on the next success: the note clears and the interval is 3 s again', () => {
        const { isPaused } = startLiveUpdates();
        vi.advanceTimersByTime(3_000);
        failWithHttpError();
        vi.advanceTimersByTime(6_000);
        failWithHttpError();
        vi.advanceTimersByTime(12_000);

        succeed();

        expect(isPaused.value).toBe(false);
        expectNextPollAfter(3_000);
        failWithNetworkError();
        expectNextPollAfter(6_000);
    });

    it('reschedules a cancelled poll at the current delay, without pausing', () => {
        const { isPaused } = startLiveUpdates();
        vi.advanceTimersByTime(3_000);

        cancel();
        expect(isPaused.value).toBe(false);
        expectNextPollAfter(3_000);

        failWithHttpError();
        vi.advanceTimersByTime(6_000);
        cancel();
        expect(isPaused.value).toBe(true);
        expectNextPollAfter(6_000);
    });

    it('takes a custom interval', () => {
        scope = effectScope();
        scope.run(() => useLiveUpdates({ only: ['generations'], active: true, intervalMs: 500 }));

        expectNextPollAfter(500);
    });

    it('makes no poll after its scope is disposed while waiting', () => {
        startLiveUpdates();

        scope.stop();
        vi.advanceTimersByTime(60_000);

        expect(polls).toHaveLength(0);
        expect(vi.getTimerCount()).toBe(0);
    });

    it('cancels a poll in flight when disposed, and schedules nothing when it finishes late', () => {
        const cancelToken = { cancel: vi.fn() };
        startLiveUpdates();
        vi.advanceTimersByTime(3_000);
        lastPoll().onCancelToken?.(cancelToken);

        scope.stop();
        cancel();
        vi.advanceTimersByTime(60_000);

        expect(cancelToken.cancel).toHaveBeenCalledOnce();
        expect(polls).toHaveLength(1);
        expect(vi.getTimerCount()).toBe(0);
    });
});
