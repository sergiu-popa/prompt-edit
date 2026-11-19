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

function mountPanel(prompt: string, quality: ImageQuality, size: ImageSize): VueWrapper {
    const estimate = estimateGenerationCost(fixturePricing, { prompt, quality, size, reference_image_count: 0 });

    return mount(EstimatePanel, { props: { estimate, quality, size } });
}

function mountUnavailable(): VueWrapper {
    return mount(EstimatePanel, { props: { estimate: null, quality: 'medium', size: '1024x1024' } });
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
    it("headlines the defaults' range and typical figure in a polite live region", () => {
        const wrapper = mountPanel('', 'medium', '1024x1024');

        const headline = wrapper.get('[aria-live="polite"]');
        expect(wrapper.get('h2').text()).toBe('Estimated cost');
        expect(visibleText(headline)).toBe('$0.0581 – $0.0863 typically $0.0722');
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
        expect(visibleText(wrapper.get('[aria-live="polite"]'))).toBe('$0.3382 – $0.4476 typically $0.3929');
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

        expect(visibleText(wrapper.get('[aria-live="polite"]'))).toBe(UNAVAILABLE);
        expect(wrapper.text()).not.toContain('$');
        expect(wrapper.find('table').exists()).toBe(false);
    });
});
