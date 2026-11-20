import type { CancelToken } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import type { MaybeRefOrGetter, Ref } from 'vue';
import { onScopeDispose, readonly, ref, toValue, watch } from 'vue';

/** The wait between one successful poll's end and the next poll's start (AC 1). */
export const LIVE_UPDATE_INTERVAL_MS = 3_000;

/** The waits after the 1st, 2nd, 3rd and every later failed poll in a row (AC 4). */
export const BACKOFF_MS = [6_000, 12_000, 24_000, 30_000] as const;

export interface LiveUpdatesOptions {
    /** The props each poll reloads, such as `['generations']`. */
    only: string[];
    /** Whether to poll at all, such as while anything shown is still in progress. */
    active: MaybeRefOrGetter<boolean>;
    intervalMs?: number;
}

export interface LiveUpdates {
    /** True from a failed poll until the next successful one. */
    isPaused: Readonly<Ref<boolean>>;
}

/**
 * Keeps a page's props live with partial reloads from our own server (D-11, D-41),
 * only while `active` is true. The next poll is scheduled when the previous one
 * finishes, so two never overlap. A poll that finishes without success (an HTTP
 * error, a network error, or any answer that isn't the page) keeps the last props
 * on screen with no error dialog, pauses, and backs off by BACKOFF_MS; the first
 * success resets the interval. A cancelled poll changes nothing and is tried again
 * at the current delay. Disposing the calling scope clears the timer and cancels a
 * poll in flight.
 */
export function useLiveUpdates({ only, active, intervalMs = LIVE_UPDATE_INTERVAL_MS }: LiveUpdatesOptions): LiveUpdates {
    const isPaused = ref(false);
    let failuresInARow = 0;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let isPolling = false;
    let inFlight: CancelToken | null = null;
    let isDisposed = false;

    function currentDelay(): number {
        if (failuresInARow === 0) {
            return intervalMs;
        }

        return BACKOFF_MS[Math.min(failuresInARow, BACKOFF_MS.length) - 1] ?? BACKOFF_MS[3];
    }

    function clearTimer(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    function schedule(): void {
        clearTimer();

        if (isDisposed || isPolling || !toValue(active)) {
            return;
        }

        timer = setTimeout(poll, currentDelay());
    }

    function poll(): void {
        timer = null;
        isPolling = true;
        let succeeded = false;
        let cancelled = false;

        router.reload({
            only,
            onCancelToken: (token) => {
                inFlight = token;
            },
            onSuccess: () => {
                succeeded = true;
            },
            onHttpException: () => false,
            onNetworkError: () => false,
            onCancel: () => {
                cancelled = true;
            },
            onFinish: () => {
                isPolling = false;
                inFlight = null;

                if (isDisposed) {
                    return;
                }

                if (succeeded) {
                    failuresInARow = 0;
                    isPaused.value = false;
                } else if (!cancelled) {
                    failuresInARow += 1;
                    isPaused.value = true;
                }

                schedule();
            },
        });
    }

    watch(
        () => toValue(active),
        (isActive) => {
            if (isActive) {
                schedule();
            } else {
                clearTimer();
            }
        },
        { immediate: true },
    );

    onScopeDispose(() => {
        isDisposed = true;
        clearTimer();
        inFlight?.cancel();
    });

    return { isPaused: readonly(isPaused) };
}
