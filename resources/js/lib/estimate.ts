import type { EstimateBounds, EstimateInput, EstimateLine, GenerationCostEstimate } from '@/types/estimate';
import type { PricingTable, TokenType } from '@/types/pricing';

/**
 * The instant upfront estimate of a generation: a pure, integer-only mirror of
 * App\Domain\Billing\Actions\EstimateGenerationCostAction, held to the same
 * shared fixture (tests/fixtures/estimates.json). The server recomputes it, so
 * this figure is never trusted for billing.
 *
 * All arithmetic runs in bigint; `/` on bigint is the only division.
 */

const PERCENT = 100n;

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

/** Division rounded up, for a non-negative dividend and a positive divisor. */
function ceilDiv(dividend: bigint, divisor: bigint): bigint {
    return (dividend + divisor - 1n) / divisor;
}

/** Typical is the midpoint of min and max, rounded up to a whole token (D-16). */
function between(min: bigint, max: bigint): Bounds {
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

function toEstimateBounds(bounds: Bounds): EstimateBounds {
    return { min: Number(bounds.min), typical: Number(bounds.typical), max: Number(bounds.max) };
}

/** e = ceil(code points ÷ characters per token); the bounds are e ∓ the variance, floored and ceiled. */
function textTokens(promptCharacters: bigint, pricing: PricingTable): Bounds {
    const estimated = ceilDiv(promptCharacters, BigInt(pricing.tokens.characters_per_text_token));
    const variance = BigInt(pricing.tokens.text_token_variance_percent);

    return between((estimated * (PERCENT - variance)) / PERCENT, ceilDiv(estimated * (PERCENT + variance), PERCENT));
}

export function estimateGenerationCost(pricing: PricingTable, input: EstimateInput): GenerationCostEstimate {
    const promptCharacters = [...input.prompt].length;
    const reasoning = pricing.tokens.reasoning[input.quality];

    const tokens: Record<TokenType, Bounds> = {
        input_text_tokens: textTokens(BigInt(promptCharacters), pricing),
        input_image_tokens: exact(BigInt(pricing.tokens.per_reference_image) * BigInt(input.reference_image_count)),
        output_tokens: exact(BigInt(pricing.tokens.output[input.quality][input.size])),
        reasoning_tokens: between(BigInt(reasoning.min), BigInt(reasoning.max)),
    };

    const lines = {} as Record<TokenType, EstimateLine>;
    let providerCost = exact(0n);

    for (const type of TOKEN_TYPES) {
        const lineCost = scaled(tokens[type], BigInt(pricing.nanodollars_per_token[type]));

        providerCost = plus(providerCost, lineCost);
        lines[type] = {
            exact: KNOWN_UPFRONT[type],
            tokens: toEstimateBounds(tokens[type]),
            provider_nanodollars: toEstimateBounds(lineCost),
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
        provider_nanodollars: toEstimateBounds(providerCost),
        user_nanodollars: toEstimateBounds(userPrice),
    };
}
