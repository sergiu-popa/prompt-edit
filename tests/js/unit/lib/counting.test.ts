import { describe, expect, it } from 'vitest';

import { countCodePoints, formatCount } from '@/lib/counting';

describe('countCodePoints', () => {
    it.each<[string, number]>([
        ['', 0],
        ['A lighthouse on a cliff at dawn, in oils', 40],
        ['👍', 1],
        ['👍🏽', 2],
        ['é', 1],
        ['é', 2],
    ])('counts %j as %i, like PHP mb_strlen', (text, expected) => {
        expect(countCodePoints(text)).toBe(expected);
    });
});

describe('formatCount', () => {
    it.each<[number, string]>([
        [0, '0'],
        [40, '40'],
        [4_000, '4,000'],
        [4_012, '4,012'],
        [1_234_567, '1,234,567'],
    ])('formats %i as %s', (count, expected) => {
        expect(formatCount(count)).toBe(expected);
    });
});
