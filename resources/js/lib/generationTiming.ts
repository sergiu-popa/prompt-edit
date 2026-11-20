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

/**
 * Whether an image URL has stopped working: from exactly `expiresAt` on, as the
 * server decides `image_expired`. With no expiry it never expires.
 */
export function isImageExpired(expiresAt: string | null, nowMs: number): boolean {
    return expiresAt !== null && nowMs >= Date.parse(expiresAt);
}
