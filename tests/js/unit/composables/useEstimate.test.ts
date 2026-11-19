import { describe, expect, it } from 'vitest';
import { reactive, ref } from 'vue';

import { fixturePricing, fixturePricingWith, LIGHTHOUSE_PROMPT } from '../../support/pricing';

import { useEstimate } from '@/composables/useEstimate';
import type { GenerationFormInput } from '@/types/generation';
import type { PricingTable } from '@/types/pricing';

function defaultForm(): GenerationFormInput {
    return reactive<GenerationFormInput>({ prompt: '', size: '1024x1024', quality: 'medium' });
}

describe('useEstimate', () => {
    it("estimates Pixelforge's defaults before anything is typed", () => {
        const { estimate } = useEstimate(fixturePricing, defaultForm());

        expect(estimate.value?.user_nanodollars).toStrictEqual({ min: 58_125_000, typical: 72_187_500, max: 86_250_000 });
        expect(estimate.value?.prompt_characters).toBe(0);
    });

    it('follows the prompt, then the quality, then the size', () => {
        const form = defaultForm();
        const { estimate } = useEstimate(fixturePricing, form);

        form.prompt = LIGHTHOUSE_PROMPT;
        expect(estimate.value?.prompt_characters).toBe(40);
        expect(estimate.value?.user_nanodollars).toStrictEqual({ min: 58_175_000, typical: 72_250_000, max: 86_325_000 });

        form.quality = 'high';
        expect(estimate.value?.user_nanodollars).toStrictEqual({ min: 230_675_000, typical: 285_375_000, max: 340_075_000 });

        form.size = '1536x1024';
        expect(estimate.value?.user_nanodollars).toStrictEqual({ min: 338_175_000, typical: 392_875_000, max: 447_575_000 });
    });

    it('keeps estimating a prompt over the 4,000-character limit, counted in code points', () => {
        const form = defaultForm();
        const { estimate } = useEstimate(fixturePricing, form);

        form.prompt = `${'a'.repeat(4_010)}👍🏽`;

        expect(estimate.value?.prompt_characters).toBe(4_012);
        expect(estimate.value?.user_nanodollars.max).toBeGreaterThan(86_250_000);
    });

    it('follows a pricing table given as a ref', () => {
        const pricing = ref<PricingTable>(fixturePricing);
        const { estimate } = useEstimate(pricing, defaultForm());

        pricing.value = fixturePricingWith((table) => (table.nanodollars_per_token.output_tokens = 0));

        expect(estimate.value?.lines.output_tokens.provider_nanodollars.max).toBe(0);
    });

    it.each<[string, (pricing: PricingTable) => void]>([
        ['a missing tokens block', (pricing) => delete (pricing as Partial<PricingTable>).tokens],
        ['a fractional rate', (pricing) => (pricing.nanodollars_per_token.output_tokens = 1.5)],
    ])('is unavailable, without throwing, for a pricing table with %s', (_label, change) => {
        const { estimate } = useEstimate(fixturePricingWith(change), defaultForm());

        expect(() => estimate.value).not.toThrow();
        expect(estimate.value).toBeNull();
    });
});
