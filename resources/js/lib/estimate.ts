import type { EstimateBounds, EstimateInput, EstimateLine, GenerationCostEstimate } from '@/types/estimate';
import type { PricingTable, TokenType } from '@/types/pricing';

/**
 * The instant upfront estimate of a generation: a pure, integer-only mirror of
 * App\Domain\Billing\Actions\EstimateGenerationCostAction, held to the same
 * shared fixture (tests/fixtures/estimates.json). The server recomputes it, so
 * this figure is never trusted for billing.
 *
 * All arithmetic runs in bigint; `/` on bigint is the only division. Every
 * number read must be a non-negative safe integer, and every result must fit
 * in one, so a malformed prop throws an Error naming the field instead of
 * returning an imprecise price.
 */

const PERCENT = 100n;

const MAX_SAFE_INTEGER = BigInt(Number.MAX_SAFE_INTEGER);

/** User price = ceil(provider × 125 / 100), applied once to each total (D-04). */
const MARKUP_NUMERATOR = 125n;
const MARKUP_DENOMINATOR = 100n;

const TOKEN_TYPES: readonly TokenType[] = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

const KNOWN_UPFRONT: Readonly<Record<TokenType, boolean>> = {
    input_text_tokens: false,
    input_image_tokens: true,
    output_tokens: true,
    reasoning_tokens: false,
};

interface Bounds {
    min: bigint;
    typical: bigint;
    max: bigint;
}

/** Reads a count, rate or rule from the pricing table or the input as an exact bigint. */
function wholeNumber(value: unknown, field: string): bigint {
    if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 0) {
        throw new Error(`${field} must be a non-negative safe integer, got ${String(value)}.`);
    }

    return BigInt(value);
}

/** Looks a quality or size up by own key only, so `__proto__` or `toString` never resolve. */
function entryFor<T>(table: Readonly<Record<string, T>>, key: string, field: string): T {
    if (!Object.hasOwn(table, key)) {
        throw new Error(`${field} "${key}" is not in the pricing table.`);
    }

    return table[key] as T;
}

function toSafeNumber(value: bigint, field: string): number {
    if (value > MAX_SAFE_INTEGER) {
        throw new Error(`${field} ${value} exceeds Number.MAX_SAFE_INTEGER.`);
    }

    return Number(value);
}

/** Division rounded up, for a non-negative dividend and a positive divisor. */
function ceilDiv(dividend: bigint, divisor: bigint): bigint {
    return (dividend + divisor - 1n) / divisor;
}

/** Typical is the midpoint of min and max, rounded up to a whole token (D-16). */
function between(min: bigint, max: bigint, field: string): Bounds {
    if (min > max) {
        throw new Error(`${field} min ${min} exceeds max ${max}.`);
    }

    return { min, typical: ceilDiv(min + max, 2n), max };
}

function exact(count: bigint): Bounds {
    return { min: count, typical: count, max: count };
}

function scaled(bounds: Bounds, factor: bigint): Bounds {
    return { min: bounds.min * factor, typical: bounds.typical * factor, max: bounds.max * factor };
}

function plus(left: Bounds, right: Bounds): Bounds {
    return { min: left.min + right.min, typical: left.typical + right.typical, max: left.max + right.max };
}

function toEstimateBounds(bounds: Bounds, field: string): EstimateBounds {
    return {
        min: toSafeNumber(bounds.min, `${field}.min`),
        typical: toSafeNumber(bounds.typical, `${field}.typical`),
        max: toSafeNumber(bounds.max, `${field}.max`),
    };
}

/** e = ceil(code points ÷ characters per token); the bounds are e ∓ the variance, floored and ceiled. */
function textTokens(promptCharacters: bigint, pricing: PricingTable): Bounds {
    const charactersPerToken = wholeNumber(pricing.tokens.characters_per_text_token, 'tokens.characters_per_text_token');
    const variance = wholeNumber(pricing.tokens.text_token_variance_percent, 'tokens.text_token_variance_percent');

    if (charactersPerToken < 1n) {
        throw new Error('tokens.characters_per_text_token must be at least 1.');
    }

    if (variance > PERCENT) {
        throw new Error(`tokens.text_token_variance_percent must be at most 100, got ${variance}.`);
    }

    const estimated = ceilDiv(promptCharacters, charactersPerToken);

    return between((estimated * (PERCENT - variance)) / PERCENT, ceilDiv(estimated * (PERCENT + variance), PERCENT), 'input_text_tokens');
}

export function estimateGenerationCost(pricing: PricingTable, input: EstimateInput): GenerationCostEstimate {
    const promptCharacters = [...input.prompt].length;
    const outputCount = entryFor(entryFor(pricing.tokens.output, input.quality, 'quality'), input.size, 'size');
    const reasoningField = `tokens.reasoning.${input.quality}`;
    const reasoning = entryFor(pricing.tokens.reasoning, input.quality, 'quality');

    const tokens: Record<TokenType, Bounds> = {
        input_text_tokens: textTokens(BigInt(promptCharacters), pricing),
        input_image_tokens: exact(
            wholeNumber(pricing.tokens.per_reference_image, 'tokens.per_reference_image') *
                wholeNumber(input.reference_image_count, 'reference_image_count'),
        ),
        output_tokens: exact(wholeNumber(outputCount, `tokens.output.${input.quality}.${input.size}`)),
        reasoning_tokens: between(
            wholeNumber(reasoning.min, `${reasoningField}.min`),
            wholeNumber(reasoning.max, `${reasoningField}.max`),
            reasoningField,
        ),
    };

    const lines = {} as Record<TokenType, EstimateLine>;
    let providerCost = exact(0n);

    for (const type of TOKEN_TYPES) {
        const lineCost = scaled(tokens[type], wholeNumber(pricing.nanodollars_per_token[type], `nanodollars_per_token.${type}`));

        providerCost = plus(providerCost, lineCost);
        lines[type] = {
            exact: KNOWN_UPFRONT[type],
            tokens: toEstimateBounds(tokens[type], `lines.${type}.tokens`),
            provider_nanodollars: toEstimateBounds(lineCost, `lines.${type}.provider_nanodollars`),
        };
    }

    const userPrice: Bounds = {
        min: ceilDiv(providerCost.min * MARKUP_NUMERATOR, MARKUP_DENOMINATOR),
        typical: ceilDiv(providerCost.typical * MARKUP_NUMERATOR, MARKUP_DENOMINATOR),
        max: ceilDiv(providerCost.max * MARKUP_NUMERATOR, MARKUP_DENOMINATOR),
    };

    return {
        prompt_characters: promptCharacters,
        lines,
        provider_nanodollars: toEstimateBounds(providerCost, 'provider_nanodollars'),
        user_nanodollars: toEstimateBounds(userPrice, 'user_nanodollars'),
    };
}
