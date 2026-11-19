import type { ImageQuality, ImageSize } from '@/types/pricing';

export interface ImageSizeOption {
    value: ImageSize;
    label: string;
    /** Width × height in pixels, as shown to the user. */
    detail: string;
}

export interface ImageQualityOption {
    value: ImageQuality;
    label: string;
}

export const SIZE_OPTIONS: readonly ImageSizeOption[] = [
    { value: '1024x1024', label: 'Square', detail: '1024×1024' },
    { value: '1536x1024', label: 'Landscape', detail: '1536×1024' },
    { value: '1024x1536', label: 'Portrait', detail: '1024×1536' },
];

export const QUALITY_OPTIONS: readonly ImageQualityOption[] = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
];

/** Pixelforge's defaults when a generation names no size or quality. */
export const DEFAULT_SIZE: ImageSize = '1024x1024';
export const DEFAULT_QUALITY: ImageQuality = 'medium';

/** "High · 1536×1024". */
export function describeImage(quality: ImageQuality, size: ImageSize): string {
    const qualityLabel = QUALITY_OPTIONS.find((option) => option.value === quality)?.label ?? quality;
    const dimensions = SIZE_OPTIONS.find((option) => option.value === size)?.detail ?? size;

    return `${qualityLabel} · ${dimensions}`;
}
