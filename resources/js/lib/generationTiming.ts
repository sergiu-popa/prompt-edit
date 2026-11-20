/**
 * A generation's times read against the server's clock (D-42), as History's
 * `useServerClock` keeps it. Instants are ISO-8601; `nowMs` is epoch milliseconds.
 */

/**
 * Whole seconds since `startedAt`. A start after now, from a provider clock a little
 * ahead of ours, reads 0, so the count never shows a negative time.
 */
export function elapsedSeconds(startedAt: string, nowMs: number): number {
    return Math.max(0, Math.floor((nowMs - Date.parse(startedAt)) / 1000));
}
