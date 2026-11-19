import type { ImageQuality, ImageSize, TokenType } from '@/types/pricing';

export interface EstimateInput {
    prompt: string;
    quality: ImageQuality;
    size: ImageSize;
    reference_image_count: number;
}

export interface EstimateBounds {
    min: number;
    typical: number;
    max: number;
}

export interface EstimateLine {
    exact: boolean;
    tokens: EstimateBounds;
    provider_nanodollars: EstimateBounds;
}

/** Keys match the shared estimate fixture's `expected`, so results compare with no mapper. */
export interface GenerationCostEstimate {
    prompt_characters: number;
    lines: Record<TokenType, EstimateLine>;
    provider_nanodollars: EstimateBounds;
    user_nanodollars: EstimateBounds;
}
