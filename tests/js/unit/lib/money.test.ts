import { describe, expect, it } from 'vitest';

import { formatUsd, formatUsdExact } from '@/lib/money';

describe('formatUsd', () => {
    it.each<[number, string]>([
        [373_875_000, '$0.3739'],
        [467_343_750, '$0.4673'],
        [9_562_500, '$0.0096'],
        [0, '$0.0000'],
        [50_000, '$0.0001'],
        [60_000, '$0.0001'],
        [149_999, '$0.0001'],
        [150_000, '$0.0002'],
        [999_950_000, '$1.0000'],
        [999_999_999_999, '$1,000.0000'],
        [1_234_567_890_123, '$1,234.5679'],
        [Number.MAX_SAFE_INTEGER, '$9,007,199.2547'],
        [-14_000_000, '-$0.0140'],
        [-50_000, '-$0.0001'],
        [-0, '$0.0000'],
    ])('formats %i n$ as %s, rounding half away from zero', (nanodollars, expected) => {
        expect(formatUsd(nanodollars)).toBe(expected);
    });

    it.each([1, 40_000, 49_999])('shows %i n$ as <$0.0001, so a non-zero cost never reads as free', (nanodollars) => {
        expect(formatUsd(nanodollars)).toBe('<$0.0001');
    });

    it.each([-1, -40_000, -49_999])('shows %i n$ as -<$0.0001', (nanodollars) => {
        expect(formatUsd(nanodollars)).toBe('-<$0.0001');
    });
});

describe('formatUsdExact', () => {
    it.each<[number, string]>([
        [373_875_000, '$0.373875000'],
        [457_137_500, '$0.457137500'],
        [9_562_500, '$0.009562500'],
        [1, '$0.000000001'],
        [49_999, '$0.000049999'],
        [999_950_000, '$0.999950000'],
        [999_999_999_999, '$999.999999999'],
        [1_234_567_890_123, '$1,234.567890123'],
        [Number.MAX_SAFE_INTEGER, '$9,007,199.254740991'],
        [-40_000, '-$0.000040000'],
        [-14_000_000, '-$0.014000000'],
        [0, '$0.000000000'],
        [-0, '$0.000000000'],
    ])('formats %i n$ as %s', (nanodollars, expected) => {
        expect(formatUsdExact(nanodollars)).toBe(expected);
    });
});

describe.each([
    ['formatUsd', formatUsd],
    ['formatUsdExact', formatUsdExact],
])('%s rejects what is not an exact nanodollar amount', (_name, format) => {
    it.each([0.5, NaN, Infinity, -Infinity, 2 ** 53, -(2 ** 53)])('throws on %s', (value) => {
        expect(() => format(value)).toThrow(RangeError);
    });
});
