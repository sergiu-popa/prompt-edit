import { describe, expect, it } from 'vitest';

import estimatesJson from '../../../fixtures/estimates.json';

import { estimateGenerationCost } from '@/lib/estimate';
import type { EstimateInput, GenerationCostEstimate } from '@/types/estimate';
import type { PricingTable, TokenRange } from '@/types/pricing';

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

describe('estimateGenerationCost rejects what it cannot price exactly', () => {
    const validInput: EstimateInput = { prompt: 'a cat', quality: 'low', size: '1024x1024', reference_image_count: 0 };

    function pricingWith(change: (pricing: PricingTable) => void): PricingTable {
        const pricing = structuredClone(fixture.pricing);

        change(pricing);

        return pricing;
    }

    it.each<[string, (pricing: PricingTable) => void, string]>([
        ['a fractional rate', (pricing) => (pricing.nanodollars_per_token.output_tokens = 0.5), 'nanodollars_per_token.output_tokens'],
        ['an unsafe rate', (pricing) => (pricing.nanodollars_per_token.output_tokens = 2 ** 53), 'nanodollars_per_token.output_tokens'],
        ['a negative rate', (pricing) => (pricing.nanodollars_per_token.output_tokens = -1), 'nanodollars_per_token.output_tokens'],
        ['a NaN rate', (pricing) => (pricing.nanodollars_per_token.input_text_tokens = NaN), 'nanodollars_per_token.input_text_tokens'],
        ['a fractional output count', (pricing) => (pricing.tokens.output.low['1024x1024'] = 280.5), 'tokens.output.low.1024x1024'],
        ['a reasoning min above its max', (pricing) => (pricing.tokens.reasoning.low = { min: 600, max: 500 }), 'tokens.reasoning.low'],
        ['zero characters per token', (pricing) => (pricing.tokens.characters_per_text_token = 0), 'tokens.characters_per_text_token'],
        ['the live float variance', (pricing) => (pricing.tokens.text_token_variance_percent = 0.15), 'tokens.text_token_variance_percent'],
        ['a variance above 100', (pricing) => (pricing.tokens.text_token_variance_percent = 101), 'tokens.text_token_variance_percent'],
        ['a fractional reference image size', (pricing) => (pricing.tokens.per_reference_image = 765.5), 'tokens.per_reference_image'],
        [
            'a null reasoning range',
            (pricing) => (pricing.tokens.reasoning.low = null as unknown as TokenRange),
            'tokens.reasoning.low must be an object, got null.',
        ],
        [
            'a numeric reasoning range',
            (pricing) => (pricing.tokens.reasoning.low = 500 as unknown as TokenRange),
            'tokens.reasoning.low must be an object, got number.',
        ],
        [
            'a null output table for the quality',
            (pricing) => (pricing.tokens.output.low = null as unknown as PricingTable['tokens']['output']['low']),
            'tokens.output.low must be an object, got null.',
        ],
        [
            'an output table given as a list',
            (pricing) => (pricing.tokens.output.low = [280] as unknown as PricingTable['tokens']['output']['low']),
            'tokens.output.low must be an object, got array.',
        ],
    ])('rejects %s and names the field', (_label, change, field) => {
        expect(() => estimateGenerationCost(pricingWith(change), validInput)).toThrow(field);
    });

    it.each<[string, (pricing: PricingTable) => void, string]>([
        [
            'a null tokens block',
            (pricing) => (pricing.tokens = null as unknown as PricingTable['tokens']),
            'tokens must be an object, got null.',
        ],
        [
            'a missing rates block',
            (pricing) => (pricing.nanodollars_per_token = undefined as unknown as PricingTable['nanodollars_per_token']),
            'nanodollars_per_token must be an object, got undefined.',
        ],
        [
            'a null output table',
            (pricing) => (pricing.tokens.output = null as unknown as PricingTable['tokens']['output']),
            'tokens.output must be an object, got null.',
        ],
        [
            'a reasoning table given as a list',
            (pricing) => (pricing.tokens.reasoning = [] as unknown as PricingTable['tokens']['reasoning']),
            'tokens.reasoning must be an object, got array.',
        ],
    ])('rejects %s in the pricing prop and names the field', (_label, change, message) => {
        expect(() => estimateGenerationCost(pricingWith(change), validInput)).toThrow(message);
    });

    it.each([1.5, -1, NaN, 2 ** 53])('rejects a reference image count of %s', (count) => {
        expect(() => estimateGenerationCost(fixture.pricing, { ...validInput, reference_image_count: count })).toThrow(
            'reference_image_count',
        );
    });

    it.each<[string, unknown]>([
        ['null', null],
        ['undefined', undefined],
        ['a number', 5],
        ['a list of characters', ['a', 'b']],
    ])('rejects a prompt that is %s', (_label, prompt) => {
        expect(() => estimateGenerationCost(fixture.pricing, { ...validInput, prompt: prompt as string })).toThrow(
            'prompt must be a string',
        );
    });

    it.each(['ultra', 'LOW', '__proto__', 'constructor'])('rejects the quality "%s"', (quality) => {
        expect(() => estimateGenerationCost(fixture.pricing, { ...validInput, quality: quality as EstimateInput['quality'] })).toThrow(
            'quality',
        );
    });

    it.each(['512x512', '1024X1024', 'toString', '__proto__'])('rejects the size "%s"', (size) => {
        expect(() => estimateGenerationCost(fixture.pricing, { ...validInput, size: size as EstimateInput['size'] })).toThrow('size');
    });

    it('throws instead of returning an imprecise total', () => {
        const pricing = pricingWith((table) => (table.nanodollars_per_token.output_tokens = Number.MAX_SAFE_INTEGER));

        expect(() => estimateGenerationCost(pricing, validInput)).toThrow('Number.MAX_SAFE_INTEGER');
    });
});
