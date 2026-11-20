import { describe, expect, it } from 'vitest';

import { formatBalance, formatUsd, formatUsdExact, formatUsdRoundedUp } from '@/lib/money';

import shortfallsJson from '../../../fixtures/credit-shortfalls.json';

interface ShortfallCase {
    name: string;
    available: number;
    required: number;
    shortfall: number;
    display: string;
}

const shortfallCases: ShortfallCase[] = shortfallsJson.cases;

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

describe('formatUsdRoundedUp', () => {
    it.each<[number, string]>([
        [1, '$0.0001'],
        [100_000, '$0.0001'],
        [100_001, '$0.0002'],
        [80_000_000, '$0.0800'],
        [76_312_500, '$0.0764'],
        [0, '$0.0000'],
        [-0, '$0.0000'],
        [-1, '-$0.0001'],
        [-1_000_000, '-$0.0010'],
        [1_000_000_000, '$1.0000'],
        [Number.MAX_SAFE_INTEGER, '$9,007,199.2548'],
    ])('formats %i n$ as %s, with the magnitude rounded up', (nanodollars, expected) => {
        expect(formatUsdRoundedUp(nanodollars)).toBe(expected);
    });

    it.each(shortfallCases.map((shortfallCase) => [shortfallCase.name, shortfallCase] as const))(
        'shows the shared fixture shortfall: %s',
        (_name, shortfallCase) => {
            expect(formatUsdRoundedUp(shortfallCase.shortfall)).toBe(shortfallCase.display);
        },
    );
});

describe('formatBalance', () => {
    it.each<[number, string]>([
        [-1_000_000, '-$0.0010'],
        [-1, '-$0.0001'],
        [-1_050_000, '-$0.0011'],
        [913_687_500, '$0.9137'],
        [925_668_750, '$0.9257'],
        [6_312_500, '$0.0063'],
        [1_006_312_500, '$1.0063'],
        [0, '$0.0000'],
    ])('formats a balance of %i n$ as %s', (nanodollars, expected) => {
        expect(formatBalance(nanodollars)).toBe(expected);
    });
});

describe.each([
    ['formatUsd', formatUsd],
    ['formatUsdExact', formatUsdExact],
    ['formatUsdRoundedUp', formatUsdRoundedUp],
    ['formatBalance', formatBalance],
])('%s rejects what is not an exact nanodollar amount', (_name, format) => {
    it.each([0.5, NaN, Infinity, -Infinity, 2 ** 53, -(2 ** 53)])('throws on %s', (value) => {
        expect(() => format(value)).toThrow(RangeError);
    });
});
