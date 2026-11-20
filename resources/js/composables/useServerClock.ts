import type { MaybeRefOrGetter, Ref } from 'vue';
import { onScopeDispose, readonly, ref, toValue, watch } from 'vue';

const TICK_MS = 1_000;

/**
 * The offset belongs to this browser and the server, not to a page, so it outlives the
 * page that measured it. `newestServerMs` is the latest server time it was measured from.
 */
let offsetMs = 0;
let newestServerMs = Number.NEGATIVE_INFINITY;

/**
 * "Now" by the server's clock, in epoch ms, ticking every second (D-42). It keeps the
 * offset between the server's time and the browser's clock, so a browser clock that is
 * minutes off never misstates an elapsed time or an expiry. Only a server time newer
 * than any seen before re-measures the offset: Back or Forward remounts a page from the
 * props it had then, whose server time is as old as the user's time away, and that
 * snapshot keeps the offset the last fresh response measured. A restore into a freshly
 * loaded document has no fresh offset yet, so it measures one from the snapshot; its
 * first poll, if anything is in progress, corrects it. The tick stops when the calling
 * component or effect scope is disposed.
 *
 * @param serverTime ISO-8601, such as History's `generations.server_time`
 */
export function useServerClock(serverTime: MaybeRefOrGetter<string>): Readonly<Ref<number>> {
    const now = ref(0);

    function tick(): void {
        now.value = Date.now() + offsetMs;
    }

    watch(
        () => toValue(serverTime),
        (time) => {
            const serverMs = Date.parse(time);

            if (serverMs > newestServerMs) {
                offsetMs = serverMs - Date.now();
                newestServerMs = serverMs;
            }

            tick();
        },
        { immediate: true },
    );

    const interval = setInterval(tick, TICK_MS);

    onScopeDispose(() => clearInterval(interval));

    return readonly(now);
}

/**
 * Forgets the measured offset, as a freshly loaded document starts. For tests, which
 * share one module across cases.
 */
export function forgetServerClock(): void {
    offsetMs = 0;
    newestServerMs = Number.NEGATIVE_INFINITY;
}
