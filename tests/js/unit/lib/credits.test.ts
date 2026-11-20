import { describe, expect, it } from 'vitest';

import { creditShortfall } from '@/lib/credits';

import shortfallsJson from '../../../fixtures/credit-shortfalls.json';

interface ShortfallCase {
    name: string;
    available: number;
    required: number;
    shortfall: number;
}

const cases: ShortfallCase[] = shortfallsJson.cases;

describe('creditShortfall', () => {
    it.each(cases.map((shortfallCase) => [shortfallCase.name, shortfallCase] as const))(
        'matches the shared fixture, as PHP does: %s',
        (_name, shortfallCase) => {
            expect(creditShortfall(shortfallCase.required, shortfallCase.available)).toBe(shortfallCase.shortfall);
        },
    );

    it('is exact where a float subtraction would round', () => {
        expect(creditShortfall(Number.MAX_SAFE_INTEGER, 1)).toBe(Number.MAX_SAFE_INTEGER - 1);
    });

    it.each<[number, number]>([
        [0.5, 0],
        [86_312_500, NaN],
        [2 ** 53, 0],
        [Number.MAX_SAFE_INTEGER, -1],
    ])('throws on %s against %s, which is not an exact shortfall', (required, available) => {
        expect(() => creditShortfall(required, available)).toThrow(RangeError);
    });
});
