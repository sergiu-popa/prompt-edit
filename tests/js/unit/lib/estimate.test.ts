import { describe, expect, it } from 'vitest';

import estimatesJson from '../../../fixtures/estimates.json';

import { estimateGenerationCost } from '@/lib/estimate';
import type { EstimateInput, GenerationCostEstimate } from '@/types/estimate';
import type { PricingTable } from '@/types/pricing';

type DeepPartial<T> = { [K in keyof T]?: T[K] extends object ? DeepPartial<T[K]> : T[K] };

interface FixtureCase {
    name: string;
    prompt: string;
    prompt_repeat?: number;
    quality: string;
    size: string;
    reference_image_count: number;
    pricing_overrides?: DeepPartial<PricingTable>;
    expected: GenerationCostEstimate;
}

interface EstimatesFixture {
    pricing: PricingTable;
    cases: FixtureCase[];
}

const fixture = estimatesJson as unknown as EstimatesFixture;

function isPlainObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** Replaces leaves and never removes keys, like PHP's array_replace_recursive. */
function mergeRecursive<T>(base: T, overrides: unknown): T {
    if (!isPlainObject(base) || !isPlainObject(overrides)) {
        return (overrides === undefined ? base : overrides) as T;
    }

    const merged: Record<string, unknown> = { ...base };

    for (const [key, value] of Object.entries(overrides)) {
        merged[key] = mergeRecursive(merged[key], value);
    }

    return merged as T;
}

function inputFor(testCase: FixtureCase): EstimateInput {
    return {
        prompt: testCase.prompt.repeat(testCase.prompt_repeat ?? 1),
        quality: testCase.quality as EstimateInput['quality'],
        size: testCase.size as EstimateInput['size'],
        reference_image_count: testCase.reference_image_count,
    };
}

function numbersIn(value: unknown): number[] {
    if (typeof value === 'number') {
        return [value];
    }

    if (Array.isArray(value)) {
        return value.flatMap(numbersIn);
    }

    if (isPlainObject(value)) {
        return Object.values(value).flatMap(numbersIn);
    }

    return [];
}

describe('estimateGenerationCost against the shared fixture', () => {
    it('has cases to run', () => {
        expect(fixture.cases.length).toBeGreaterThan(0);
    });

    it.each(fixture.cases)('$name', (testCase) => {
        const pricing = mergeRecursive(fixture.pricing, testCase.pricing_overrides);

        expect(estimateGenerationCost(pricing, inputFor(testCase))).toStrictEqual(testCase.expected);
    });

    it('holds every number as a safe integer, so no amount loses precision in JavaScript', () => {
        const numbers = numbersIn(estimatesJson);

        expect(numbers.length).toBeGreaterThan(0);
        expect(numbers.filter((value) => !Number.isSafeInteger(value))).toStrictEqual([]);
    });
});
