import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import { fixturePricing, LIGHTHOUSE_PROMPT } from '../../../support/pricing';
import { visibleText } from '../../support/text';

import EstimatePanel from '@/components/estimate/EstimatePanel.vue';
import { estimateGenerationCost } from '@/lib/estimate';
import type { ImageQuality, ImageSize } from '@/types/pricing';

const UNAVAILABLE = "We couldn't calculate an estimate right now.";

const COPY = [
    "You pay Pixelforge's cost + 25%.",
    'Output, reference images and the markup are exact. Prompt text and reasoning are estimated until the image finishes.',
    '“Typically” assumes the average prompt-text and reasoning tokens.',
    'The actual cost is recorded when the generation finishes, from the tokens Pixelforge actually used.',
    'Failed generations are free.',
    "The estimate uses Pixelforge's published prices.",
    'Amounts are rounded to 4 decimals. Hover, focus or tap one for the exact value.',
];

function mountPanel(prompt: string, quality: ImageQuality, size: ImageSize, referenceImageCount = 0): VueWrapper {
    const estimate = estimateGenerationCost(fixturePricing, { prompt, quality, size, reference_image_count: referenceImageCount });

    return mount(EstimatePanel, { props: { estimate, quality, size, referenceImageCount } });
}

function mountUnavailable(): VueWrapper {
    return mount(EstimatePanel, { props: { estimate: null, quality: 'medium', size: '1024x1024', referenceImageCount: 0 } });
}

/** The visible tokens and cost cells of the breakdown row headed `label`. */
function breakdownRow(wrapper: VueWrapper, label: string): string[] {
    const row = wrapper
        .findAll('tr')
        .find((candidate) => candidate.find('th[scope="row"]').exists() && candidate.get('th').text() === label);

    if (!row) {
        throw new Error(`No breakdown row "${label}".`);
    }

    return row.findAll('td').map((cell) => visibleText(cell));
}

function rowLabels(wrapper: VueWrapper): string[] {
    return wrapper.findAll('th[scope="row"]').map((header) => header.text());
}

describe('EstimatePanel', () => {
    it("headlines the defaults' range and typical figure", () => {
        const wrapper = mountPanel('', 'medium', '1024x1024');

        expect(wrapper.get('h2').text()).toBe('Estimated cost');
        expect(visibleText(wrapper.get('[data-headline]'))).toBe('$0.0581 – $0.0863 typically $0.0722');
    });

    it('announces the headline from a text-only polite live region, so opening a tip is not read as an update', () => {
        const wrapper = mountPanel('', 'medium', '1024x1024');

        const liveRegions = wrapper.findAll('[aria-live]');
        expect(liveRegions).toHaveLength(1);
        expect(liveRegions[0]!.attributes('aria-live')).toBe('polite');
        expect(liveRegions[0]!.text()).toBe('Estimated cost $0.0581 to $0.0863, typically $0.0722');
        expect(liveRegions[0]!.element.children).toHaveLength(0);
    });

    it('announces the new figures when the estimate changes', async () => {
        const wrapper = mountPanel('', 'medium', '1024x1024');
        const estimate = estimateGenerationCost(fixturePricing, {
            prompt: LIGHTHOUSE_PROMPT,
            quality: 'high',
            size: '1536x1024',
            reference_image_count: 1,
        });

        await wrapper.setProps({ estimate, quality: 'high', size: '1536x1024', referenceImageCount: 1 });

        expect(wrapper.get('[aria-live="polite"]').text()).toBe('Estimated cost $0.3477 to $0.4571, typically $0.4024');
    });

    it('asks for a prompt while it is empty', () => {
        const wrapper = mountPanel('', 'medium', '1024x1024');

        expect(breakdownRow(wrapper, 'Prompt text')[0]).toBe('0 tokens. Start typing your prompt.');
    });

    it('breaks the cost down per token type, flagging exact and estimated lines', () => {
        const wrapper = mountPanel(LIGHTHOUSE_PROMPT, 'high', '1536x1024');

        expect(rowLabels(wrapper)).toStrictEqual(['Prompt text', 'Output', 'Reasoning', 'Pixelforge cost', '+ 25%', 'You pay']);
        expect(breakdownRow(wrapper, 'Prompt text')).toStrictEqual(['≈ 8–12 estimated', '<$0.0001 – $0.0001']);
        expect(breakdownRow(wrapper, 'Output')).toStrictEqual(['6,450 exact High · 1536×1024', '$0.2580']);
        expect(breakdownRow(wrapper, 'Reasoning')).toStrictEqual(['1,000–8,000 estimated', '$0.0125 – $0.1000']);
        expect(breakdownRow(wrapper, 'Pixelforge cost')).toStrictEqual(['', '$0.2705 – $0.3581']);
        expect(breakdownRow(wrapper, '+ 25%')).toStrictEqual(['', '$0.0676 – $0.0895']);
        expect(breakdownRow(wrapper, 'You pay')).toStrictEqual(['', '$0.3382 – $0.4476']);
        expect(visibleText(wrapper.get('[data-headline]'))).toBe('$0.3382 – $0.4476 typically $0.3929');
    });

    it('adds a references line and marks it exact, as in the full AC4 table', () => {
        const wrapper = mountPanel(LIGHTHOUSE_PROMPT, 'high', '1536x1024', 1);

        expect(rowLabels(wrapper)).toStrictEqual([
            'Prompt text',
            'Reference images',
            'Output',
            'Reasoning',
            'Pixelforge cost',
            '+ 25%',
            'You pay',
        ]);
        expect(breakdownRow(wrapper, 'Prompt text')).toStrictEqual(['≈ 8–12 estimated', '<$0.0001 – $0.0001']);
        expect(breakdownRow(wrapper, 'Reference images')).toStrictEqual(['765 × 1 = 765 exact', '$0.0077']);
        expect(breakdownRow(wrapper, 'Output')).toStrictEqual(['6,450 exact High · 1536×1024', '$0.2580']);
        expect(breakdownRow(wrapper, 'Reasoning')).toStrictEqual(['1,000–8,000 estimated', '$0.0125 – $0.1000']);
        expect(breakdownRow(wrapper, 'Pixelforge cost')).toStrictEqual(['', '$0.2782 – $0.3657']);
        expect(breakdownRow(wrapper, '+ 25%')).toStrictEqual(['', '$0.0695 – $0.0914']);
        expect(breakdownRow(wrapper, 'You pay')).toStrictEqual(['', '$0.3477 – $0.4571']);
        expect(visibleText(wrapper.get('[data-headline]'))).toBe('$0.3477 – $0.4571 typically $0.4024');
    });

    it('multiplies the references line by the counted rows', () => {
        const wrapper = mountPanel(LIGHTHOUSE_PROMPT, 'high', '1536x1024', 3);

        expect(breakdownRow(wrapper, 'Reference images')).toStrictEqual(['765 × 3 = 2,295 exact', '$0.0230']);
    });

    it("takes each line's exact or estimated flag from the estimate", () => {
        const estimate = estimateGenerationCost(fixturePricing, {
            prompt: LIGHTHOUSE_PROMPT,
            quality: 'high',
            size: '1536x1024',
            reference_image_count: 1,
        });
        const flipped = {
            ...estimate,
            lines: {
                input_text_tokens: { ...estimate.lines.input_text_tokens, exact: true },
                input_image_tokens: { ...estimate.lines.input_image_tokens, exact: false },
                output_tokens: { ...estimate.lines.output_tokens, exact: false },
                reasoning_tokens: { ...estimate.lines.reasoning_tokens, exact: true },
            },
        };

        const wrapper = mount(EstimatePanel, { props: { estimate: flipped, quality: 'high', size: '1536x1024', referenceImageCount: 1 } });

        expect(breakdownRow(wrapper, 'Prompt text')[0]).toBe('≈ 8–12 exact');
        expect(breakdownRow(wrapper, 'Reference images')[0]).toBe('765 × 1 = 765 estimated');
        expect(breakdownRow(wrapper, 'Output')[0]).toBe('6,450 estimated High · 1536×1024');
        expect(breakdownRow(wrapper, 'Reasoning')[0]).toBe('1,000–8,000 exact');
    });

    it('reveals the exact value of every amount it shows', () => {
        const wrapper = mountPanel(LIGHTHOUSE_PROMPT, 'high', '1536x1024');

        const tips = wrapper.findAll('[role="tooltip"]').map((tip) => tip.text());
        expect(tips).toContain('$0.447575000');
        expect(tips).toContain('$0.000040000');
        expect(wrapper.findAll('button').length).toBe(tips.length);
    });

    it('always shows the pricing copy', () => {
        for (const wrapper of [mountPanel(LIGHTHOUSE_PROMPT, 'high', '1536x1024'), mountUnavailable()]) {
            for (const statement of COPY) {
                expect(visibleText(wrapper)).toContain(statement);
            }
        }
    });

    it('never presents the max as a ceiling', () => {
        for (const wrapper of [mountPanel(LIGHTHOUSE_PROMPT, 'high', '1536x1024'), mountUnavailable()]) {
            expect(wrapper.text()).not.toMatch(/at most|never more than|guarantee/i);
        }
    });

    it('shows a message instead of numbers when the estimate is unavailable', () => {
        const wrapper = mountUnavailable();

        expect(visibleText(wrapper.get('[data-headline]'))).toBe(UNAVAILABLE);
        expect(wrapper.get('[aria-live="polite"]').text()).toBe(UNAVAILABLE);
        expect(wrapper.text()).not.toContain('$');
        expect(wrapper.find('table').exists()).toBe(false);
    });
});
