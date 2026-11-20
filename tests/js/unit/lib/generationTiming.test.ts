import { describe, expect, it } from 'vitest';

import { elapsedSeconds } from '@/lib/generationTiming';

const STARTED_AT = '2026-11-19T12:00:00Z';
const STARTED_MS = Date.parse(STARTED_AT);

describe('elapsedSeconds', () => {
    it('counts whole seconds since the start', () => {
        expect(elapsedSeconds(STARTED_AT, STARTED_MS)).toBe(0);
        expect(elapsedSeconds(STARTED_AT, STARTED_MS + 14_900)).toBe(14);
        expect(elapsedSeconds(STARTED_AT, STARTED_MS + 15_000)).toBe(15);
    });

    it('reads a start after now, from a provider clock ahead of ours, as 0', () => {
        expect(elapsedSeconds(STARTED_AT, STARTED_MS - 2_500)).toBe(0);
    });
});
