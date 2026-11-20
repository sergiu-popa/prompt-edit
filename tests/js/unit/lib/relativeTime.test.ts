import { describe, expect, it } from 'vitest';

import { formatClockTime, formatElapsed, formatExactTimestamp, formatRelativeTime } from '@/lib/relativeTime';

const NOW = new Date('2026-11-19T12:00:00Z');

function secondsBefore(seconds: number): string {
    return new Date(NOW.getTime() - seconds * 1000).toISOString();
}

const MINUTE = 60;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

describe('formatRelativeTime', () => {
    it.each<[string, number, string]>([
        ['the same instant', 0, 'just now'],
        ['59 s ago', 59, 'just now'],
        ['60 s ago', MINUTE, '1 min ago'],
        ['59 min 59 s ago', HOUR - 1, '59 min ago'],
        ['1 h ago', HOUR, '1 h ago'],
        ['23 h 59 min ago', DAY - MINUTE, '23 h ago'],
        ['24 h ago', DAY, '1 d ago'],
        ['6 d 23 h ago', 7 * DAY - HOUR, '6 d ago'],
    ])('reads %s as "%s"', (_case, seconds, expected) => {
        expect(formatRelativeTime(secondsBefore(seconds), NOW, 'UTC')).toBe(expected);
    });

    it('shows the medium date from 7 days on', () => {
        expect(formatRelativeTime(secondsBefore(7 * DAY), NOW, 'UTC')).toBe('Nov 12, 2026');
        expect(formatRelativeTime('2025-03-04T08:00:00Z', NOW, 'UTC')).toBe('Mar 4, 2025');
    });

    it("shows that date in the viewer's zone", () => {
        expect(formatRelativeTime('2026-11-01T23:30:00Z', NOW, 'Europe/Bucharest')).toBe('Nov 2, 2026');
    });

    it('reads an instant in the future, such as a clock ahead of ours, as just now', () => {
        expect(formatRelativeTime('2026-11-19T12:05:00Z', NOW, 'UTC')).toBe('just now');
    });
});

describe('formatExactTimestamp', () => {
    it('shows the medium date and the long time in the given zone', () => {
        expect(formatExactTimestamp('2026-11-19T10:15:30Z', 'UTC')).toBe('Nov 19, 2026, 10:15:30 AM UTC');
    });

    it('shows the zone it converted to', () => {
        expect(formatExactTimestamp('2026-11-19T10:15:30Z', 'Europe/Bucharest')).toBe('Nov 19, 2026, 12:15:30 PM GMT+2');
    });
});

describe('formatElapsed', () => {
    it.each<[number, string]>([
        [0, '0 s'],
        [14, '14 s'],
        [119, '119 s'],
        [120, '2 min'],
        [2 * HOUR - 1, '119 min'],
        [2 * HOUR, '2 h'],
        [30 * HOUR, '30 h'],
    ])('reads %i s as "%s"', (seconds, expected) => {
        expect(formatElapsed(seconds)).toBe(expected);
    });
});

describe('formatClockTime', () => {
    it('shows only the time for an instant on the same day as now', () => {
        expect(formatClockTime('2026-11-19T00:03:00Z', NOW, 'UTC')).toBe('12:03 AM');
        expect(formatClockTime('2026-11-19T14:03:00Z', NOW, 'UTC')).toBe('2:03 PM');
    });

    it('adds the date for an instant on another day', () => {
        expect(formatClockTime('2026-11-12T14:03:00Z', NOW, 'UTC')).toBe('Nov 12, 2026, 2:03 PM');
        expect(formatClockTime('2026-11-18T23:59:59Z', NOW, 'UTC')).toBe('Nov 18, 2026, 11:59 PM');
    });

    it("decides the day in the viewer's zone", () => {
        expect(formatClockTime('2026-11-18T23:30:00Z', NOW, 'Europe/Bucharest')).toBe('1:30 AM');
    });
});
