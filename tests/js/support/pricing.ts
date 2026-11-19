import estimatesJson from '../../fixtures/estimates.json';

import type { PricingTable } from '@/types/pricing';

/**
 * The shared estimate fixture's `pricing` block: the pinned snapshot the Generate page's
 * `pricing` prop carries (GenerationFormPageTest proves they are identical).
 */
export const fixturePricing: PricingTable = estimatesJson.pricing as PricingTable;

/** A deep copy, so a test can break it without touching the others. */
export function fixturePricingWith(change: (pricing: PricingTable) => void): PricingTable {
    const pricing = structuredClone(fixturePricing);

    change(pricing);

    return pricing;
}

/** AC3's 40-code-point prompt. */
export const LIGHTHOUSE_PROMPT = 'A lighthouse on a cliff at dawn, in oils';
