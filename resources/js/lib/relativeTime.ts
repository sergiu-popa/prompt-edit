const MINUTE_MS = 60_000;
const HOUR_MS = 60 * MINUTE_MS;
const DAY_MS = 24 * HOUR_MS;
const WEEK_MS = 7 * DAY_MS;

/**
 * How long ago an instant was, for a list: "just now" under a minute, then whole
 * minutes, hours and days ("5 min ago", "3 h ago", "6 d ago") under a week, and the
 * medium date ("Nov 12, 2026") after that. An instant in the future (a clock ahead
 * of ours) reads "just now".
 *
 * @param iso an ISO-8601 instant, such as `2026-11-19T10:15:30Z`
 * @param timeZone the zone the date is shown in; the viewer's own by default
 */
export function formatRelativeTime(iso: string, now: Date, timeZone?: string): string {
    const elapsed = now.getTime() - new Date(iso).getTime();

    if (elapsed < MINUTE_MS) {
        return 'just now';
    }

    if (elapsed < HOUR_MS) {
        return `${Math.floor(elapsed / MINUTE_MS)} min ago`;
    }

    if (elapsed < DAY_MS) {
        return `${Math.floor(elapsed / HOUR_MS)} h ago`;
    }

    if (elapsed < WEEK_MS) {
        return `${Math.floor(elapsed / DAY_MS)} d ago`;
    }

    return new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeZone }).format(new Date(iso));
}

/**
 * The exact instant, for a tooltip: "Nov 19, 2026, 10:15:30 AM UTC".
 *
 * @param timeZone the zone it is shown in; the viewer's own by default
 */
export function formatExactTimestamp(iso: string, timeZone?: string): string {
    return new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeStyle: 'long', timeZone }).format(new Date(iso));
}
