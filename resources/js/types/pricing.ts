/** Image qualities Pixelforge accepts, mirroring App\Domain\Shared\Enums\ImageQuality. */
export type ImageQuality = 'low' | 'medium' | 'high';

/** Image sizes Pixelforge accepts, mirroring App\Domain\Shared\Enums\ImageSize. */
export type ImageSize = '1024x1024' | '1536x1024' | '1024x1536';

/** Token types Pixelforge prices, mirroring App\Domain\Billing\Enums\TokenType. */
export type TokenType = 'input_text_tokens' | 'input_image_tokens' | 'output_tokens' | 'reasoning_tokens';

export interface TokenRange {
    min: number;
    max: number;
}

/**
 * The pinned pricing table as integers only: PricingTable::toArray() in PHP, the
 * shared estimate fixture's `pricing` block, and the Generate page's prop.
 */
export interface PricingTable {
    nanodollars_per_token: Record<TokenType, number>;
    tokens: {
        characters_per_text_token: number;
        text_token_variance_percent: number;
        per_reference_image: number;
        output: Record<ImageQuality, Record<ImageSize, number>>;
        reasoning: Record<ImageQuality, TokenRange>;
    };
}
