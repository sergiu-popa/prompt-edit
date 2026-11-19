import { router } from '@inertiajs/core';
import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { fixturePricing, fixturePricingWith, LIGHTHOUSE_PROMPT } from '../../../support/pricing';
import { givenSignedInAs } from '../../support/inertia';
import { visibleText } from '../../support/text';

import Create from '@/pages/Generations/Create.vue';
import type { PricingTable } from '@/types/pricing';

const LIMITS = { max_prompt_characters: 4_000, max_reference_images: 4 };

function mountPage(pricing: PricingTable = fixturePricing): VueWrapper {
    return mount(Create, { props: { pricing, limits: LIMITS } });
}

function radio(wrapper: VueWrapper, legend: string, label: string) {
    const group = wrapper.findAll('fieldset').find((fieldset) => fieldset.find('legend').text() === legend);
    const option = group?.findAll('label').find((candidate) => candidate.find('span').text() === label);

    if (!option) {
        throw new Error(`No "${label}" option in "${legend}".`);
    }

    return option.get<HTMLInputElement>('input[type="radio"]');
}

function headline(wrapper: VueWrapper): string {
    return visibleText(wrapper.get('aside [aria-live="polite"]'));
}

function counter(wrapper: VueWrapper): string {
    return wrapper.get('[id$="-aside"]').text();
}

describe('Generate page', () => {
    beforeEach(() => {
        givenSignedInAs({ id: 1, name: 'Ada Lovelace', email: 'ada@example.com' });
    });

    it('titles the document Generate and headlines the form New image', () => {
        const wrapper = mountPage();

        expect(wrapper.findComponent({ name: 'InertiaHead' }).props('title')).toBe('Generate');
        expect(wrapper.get('h1').text()).toBe('New image');
    });

    it("starts from Pixelforge's defaults and estimates them at once", () => {
        const wrapper = mountPage();

        expect(wrapper.get('textarea').element.value).toBe('');
        expect(radio(wrapper, 'Size', 'Square').element.checked).toBe(true);
        expect(radio(wrapper, 'Quality', 'Medium').element.checked).toBe(true);
        expect(counter(wrapper)).toBe('0 / 4,000');
        expect(headline(wrapper)).toBe('$0.0581 – $0.0863 typically $0.0722');
        expect(visibleText(wrapper.get('aside'))).toContain('0 tokens. Start typing your prompt.');
    });

    it('offers every size and quality in labelled groups', () => {
        const wrapper = mountPage();

        const legends = wrapper.findAll('legend').map((legend) => legend.text());
        expect(legends).toStrictEqual(['Size', 'Quality', 'Reference images (optional)']);
        for (const label of ['Square', 'Landscape', 'Portrait']) {
            expect(radio(wrapper, 'Size', label).attributes('type')).toBe('radio');
        }
        for (const label of ['Low', 'Medium', 'High']) {
            expect(radio(wrapper, 'Quality', label).attributes('type')).toBe('radio');
        }
    });

    it('updates the estimate as the prompt, quality and size change, with no request', async () => {
        const visit = vi.spyOn(router, 'visit');
        const wrapper = mountPage();

        await wrapper.get('textarea').setValue(LIGHTHOUSE_PROMPT);
        expect(counter(wrapper)).toBe('40 / 4,000');
        expect(headline(wrapper)).toBe('$0.0582 – $0.0863 typically $0.0723');

        await radio(wrapper, 'Quality', 'High').setValue(true);
        expect(headline(wrapper)).toBe('$0.2307 – $0.3401 typically $0.2854');

        await radio(wrapper, 'Size', 'Landscape').setValue(true);
        expect(headline(wrapper)).toBe('$0.3382 – $0.4476 typically $0.3929');
        expect(visibleText(wrapper.get('aside'))).toContain('High · 1536×1024');

        expect(visit).not.toHaveBeenCalled();
    });

    it('keeps the form working when the estimate cannot be computed', async () => {
        const wrapper = mountPage(fixturePricingWith((pricing) => (pricing.nanodollars_per_token.output_tokens = 1.5)));

        expect(headline(wrapper)).toBe("We couldn't calculate an estimate right now.");

        await wrapper.get('textarea').setValue(LIGHTHOUSE_PROMPT);
        await radio(wrapper, 'Quality', 'High').setValue(true);

        expect(counter(wrapper)).toBe('40 / 4,000');
        expect(radio(wrapper, 'Quality', 'High').element.checked).toBe(true);
        expect(headline(wrapper)).toBe("We couldn't calculate an estimate right now.");
    });

    it('never submits the form yet', () => {
        const wrapper = mountPage();

        const submit = new Event('submit', { cancelable: true });
        wrapper.get('form').element.dispatchEvent(submit);

        expect(submit.defaultPrevented).toBe(true);
        expect(wrapper.get('form').attributes('novalidate')).toBeDefined();
    });

    it('puts the estimate panel after the form', () => {
        const wrapper = mountPage();

        const form = wrapper.get('form').element;
        const panel = wrapper.get('aside').element;
        expect(form.compareDocumentPosition(panel) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    describe('reference images', () => {
        async function highLandscapeLighthouse(): Promise<VueWrapper> {
            const wrapper = mountPage();

            await wrapper.get('textarea').setValue(LIGHTHOUSE_PROMPT);
            await radio(wrapper, 'Quality', 'High').setValue(true);
            await radio(wrapper, 'Size', 'Landscape').setValue(true);

            return wrapper;
        }

        async function addReference(wrapper: VueWrapper, url: string): Promise<void> {
            await wrapper
                .findAll('button')
                .find((button) => button.text() === 'Add reference image')!
                .trigger('click');
            await wrapper.findAll('input[type="url"]').at(-1)!.setValue(url);
        }

        it('adds each non-blank reference to the estimate', async () => {
            const wrapper = await highLandscapeLighthouse();

            await addReference(wrapper, 'https://example.com/style.png');

            expect(headline(wrapper)).toBe('$0.3477 – $0.4571 typically $0.4024');
            expect(visibleText(wrapper.get('aside'))).toContain('765 × 1 = 765');
        });

        it('ignores a blank row', async () => {
            const wrapper = await highLandscapeLighthouse();

            await addReference(wrapper, '   ');

            expect(headline(wrapper)).toBe('$0.3382 – $0.4476 typically $0.3929');
            expect(visibleText(wrapper.get('aside'))).not.toContain('Reference images');
        });

        it('counts a row that is not a valid URL yet', async () => {
            const wrapper = await highLandscapeLighthouse();

            await addReference(wrapper, 'example.com/cat.png');

            expect(headline(wrapper)).toBe('$0.3477 – $0.4571 typically $0.4024');
        });

        it('goes back to the estimate without references once the row is removed', async () => {
            const wrapper = await highLandscapeLighthouse();

            await addReference(wrapper, 'https://example.com/style.png');
            await wrapper.get('button[aria-label="Remove reference image 1"]').trigger('click');

            expect(headline(wrapper)).toBe('$0.3382 – $0.4476 typically $0.3929');
        });

        it('shows what one more reference adds', async () => {
            const wrapper = await highLandscapeLighthouse();

            expect(visibleText(wrapper.get('form'))).toContain('+$0.0096 each');
        });
    });
});
