import type { MaybeRefOrGetter, Ref } from 'vue';
import { onScopeDispose, readonly, ref, toValue, watch } from 'vue';

const TICK_MS = 1_000;

/**
 * "Now" by the server's clock, in epoch ms, ticking every second (D-42). It keeps the
 * offset between the server's time, as the last response read it, and the browser's
 * clock, so a browser clock that is minutes off never misstates an elapsed time or an
 * expiry. A new server time re-measures the offset. The tick stops when the calling
 * component or effect scope is disposed.
 *
 * @param serverTime ISO-8601, such as History's `generations.server_time`
 */
export function useServerClock(serverTime: MaybeRefOrGetter<string>): Readonly<Ref<number>> {
    let offsetMs = 0;
    const now = ref(0);

    function tick(): void {
        now.value = Date.now() + offsetMs;
    }

    watch(
        () => toValue(serverTime),
        (time) => {
            offsetMs = Date.parse(time) - Date.now();
            tick();
        },
        { immediate: true },
    );

    const interval = setInterval(tick, TICK_MS);

    onScopeDispose(() => clearInterval(interval));

    return readonly(now);
}
