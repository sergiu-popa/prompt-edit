import { router } from '@inertiajs/core';
import type { VueWrapper } from '@vue/test-utils';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { fixturePricing, fixturePricingWith, LIGHTHOUSE_PROMPT } from '../../../support/pricing';
import { givenSignedInAs, givenVisitsFailWith, givenVisitsStayPending } from '../../support/inertia';
import { descriptionOf, inputLabelled } from '../../support/form';
import { visibleText } from '../../support/text';

import Create from '@/pages/Generations/Create.vue';
import type { PricingTable } from '@/types/pricing';

const LIMITS = { max_prompt_characters: 4_000, max_reference_images: 4 };

function mountPage(pricing: PricingTable = fixturePricing, canSubmit = true): VueWrapper {
    return mount(Create, { props: { pricing, limits: LIMITS, can_submit: canSubmit } });
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
    return visibleText(wrapper.get('aside [data-headline]'));
}

function counter(wrapper: VueWrapper): string {
    return wrapper.get('[id$="-aside"]').text();
}

function generateButton(wrapper: VueWrapper) {
    return wrapper.get('button[type="submit"]');
}

async function addReference(wrapper: VueWrapper, url: string): Promise<void> {
    await wrapper
        .findAll('button')
        .find((button) => button.text() === 'Add reference image')!
        .trigger('click');
    await wrapper.findAll('input[type="url"]').at(-1)!.setValue(url);
}

describe('Generate page', () => {
    beforeEach(() => {
        givenSignedInAs({ id: 1, name: 'Ada Lovelace', email: 'ada@example.com' });
    });

    it('titles the document Generate and headlines the form New image', () => {
        const wrapper = mountPage();

        expect(wrapper.findComponent({ name: 'InertiaHead' }).props('title')).toBe('Generate');
        expect(wrapper.get('h1').text()).toBe('New image');
        expect(wrapper.get('main header p').text()).toBe(
            'Describe the image, choose a size and quality, and see the price before you generate.',
        );
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
        expect(legends).toStrictEqual(['Size', 'Quality', 'Reference images (optional), 0 of 4']);
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

    it('leaves validation to its own checks and the server', () => {
        expect(mountPage().get('form').attributes('novalidate')).toBeDefined();
    });

    it('puts the estimate panel after the form', () => {
        const wrapper = mountPage();

        const form = wrapper.get('form').element;
        const panel = wrapper.get('aside').element;
        expect(form.compareDocumentPosition(panel) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('puts the submit area after the estimate panel', () => {
        const wrapper = mountPage();

        const panel = wrapper.get('aside').element;
        const submitArea = wrapper.get('[data-submit-area]').element;
        expect(panel.compareDocumentPosition(submitArea) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(generateButton(wrapper).attributes('form')).toBe(wrapper.get('form').attributes('id'));
    });

    describe('submitting', () => {
        const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

        async function fillLighthouse(wrapper: VueWrapper, referenceRows: string[] = ['https://example.com/style.png']): Promise<void> {
            await wrapper.get('textarea').setValue('A lighthouse on a cliff at dawn, oil painting');
            await radio(wrapper, 'Quality', 'High').setValue(true);
            await radio(wrapper, 'Size', 'Landscape').setValue(true);

            for (const row of referenceRows) {
                await addReference(wrapper, row);
            }
        }

        async function submit(wrapper: VueWrapper): Promise<void> {
            await wrapper.get('form').trigger('submit');
        }

        function submittedToken(post: ReturnType<typeof givenVisitsStayPending>, call: number): string {
            return (post.mock.calls[call]![1] as { submission_token: string }).submission_token;
        }

        it('posts exactly the five fields, leaving blank rows out and trimming URLs, as the estimate counted them', async () => {
            const post = givenVisitsStayPending();
            const wrapper = mountPage();

            await fillLighthouse(wrapper, ['  https://example.com/style.png  ', '   ']);
            expect(headline(wrapper)).toBe('$0.3478 – $0.4572 typically $0.4025');
            await submit(wrapper);

            expect(post).toHaveBeenCalledTimes(1);
            expect(post).toHaveBeenCalledWith(
                '/generations',
                {
                    prompt: 'A lighthouse on a cliff at dawn, oil painting',
                    size: '1536x1024',
                    quality: 'high',
                    reference_images: ['https://example.com/style.png'],
                    submission_token: expect.stringMatching(UUID),
                },
                expect.any(Object),
            );
        });

        it('sends the prompt exactly as typed', async () => {
            const post = givenVisitsStayPending();
            const wrapper = mountPage();

            await wrapper.get('textarea').setValue('    A lighthouse\n');
            await submit(wrapper);

            expect((post.mock.calls[0]![1] as { prompt: string }).prompt).toBe('    A lighthouse\n');
        });

        it('sends one token per visit, kept when the server sends the form back', async () => {
            const post = givenVisitsFailWith({ prompt: 'The prompt must be 4,000 characters or fewer.' });
            const first = mountPage();
            await fillLighthouse(first);
            await submit(first);
            await submit(first);

            const second = mountPage();
            await fillLighthouse(second);
            await submit(second);

            expect(post).toHaveBeenCalledTimes(3);
            expect(submittedToken(post, 0)).toMatch(UUID);
            expect(submittedToken(post, 1)).toBe(submittedToken(post, 0));
            expect(submittedToken(post, 2)).not.toBe(submittedToken(post, 0));
        });

        it('enables Generate for a valid form', async () => {
            const wrapper = mountPage();

            await fillLighthouse(wrapper);

            expect(generateButton(wrapper).text()).toBe('Generate');
            expect(generateButton(wrapper).attributes('disabled')).toBeUndefined();
        });

        it.each<[string, (wrapper: VueWrapper) => Promise<void>]>([
            ['the prompt is empty', async () => {}],
            ['the prompt is blank', (wrapper) => wrapper.get('textarea').setValue('\u00A0 \u3000')],
            ['the prompt is over 4,000 code points', (wrapper) => wrapper.get('textarea').setValue('a'.repeat(3_999) + '🎨🎨')],
            [
                'a reference is not an http(s) URL',
                async (wrapper) => {
                    await wrapper.get('textarea').setValue(LIGHTHOUSE_PROMPT);
                    await addReference(wrapper, 'example.com/a.png');
                },
            ],
        ])('disables Generate, and sends nothing on Enter, when %s', async (_case, arrange) => {
            const post = givenVisitsStayPending();
            const wrapper = mountPage();

            await arrange(wrapper);
            await submit(wrapper);

            expect(generateButton(wrapper).attributes('disabled')).toBeDefined();
            expect(post).not.toHaveBeenCalled();
        });

        it('disables Generate while the estimate is unavailable', async () => {
            const post = givenVisitsStayPending();
            const wrapper = mountPage(fixturePricingWith((pricing) => (pricing.nanodollars_per_token.output_tokens = 1.5)));

            await wrapper.get('textarea').setValue(LIGHTHOUSE_PROMPT);
            await submit(wrapper);

            expect(generateButton(wrapper).attributes('disabled')).toBeDefined();
            expect(post).not.toHaveBeenCalled();
        });

        it('keeps Generate closed, with a notice, until Pixelforge is configured, while still estimating', async () => {
            const post = givenVisitsStayPending();
            const wrapper = mountPage(fixturePricing, false);

            await fillLighthouse(wrapper);
            await submit(wrapper);

            expect(visibleText(wrapper.get('[data-submit-area]'))).toContain(
                'Image generation opens soon. You can already see what an image would cost.',
            );
            expect(generateButton(wrapper).attributes('disabled')).toBeDefined();
            expect(headline(wrapper)).toBe('$0.3478 – $0.4572 typically $0.4025');
            expect(post).not.toHaveBeenCalled();
        });

        it('shows no notice once Pixelforge is configured', () => {
            expect(visibleText(mountPage().get('[data-submit-area]'))).not.toContain('opens soon');
        });

        it('shows a busy, disabled Generate and sends one request however often it is submitted', async () => {
            const post = givenVisitsStayPending();
            const wrapper = mountPage();

            await fillLighthouse(wrapper);
            await submit(wrapper);
            await submit(wrapper);
            await generateButton(wrapper).trigger('click');

            expect(generateButton(wrapper).text()).toBe('Generating…');
            expect(generateButton(wrapper).attributes('disabled')).toBeDefined();
            expect(generateButton(wrapper).attributes('aria-busy')).toBe('true');
            expect(post).toHaveBeenCalledTimes(1);
        });
    });

    describe('server errors', () => {
        async function submitLighthouseRejectedWith(errors: Record<string, string>, referenceRows = ['https://example.com/style.png']) {
            givenVisitsFailWith(errors);
            const wrapper = mount(Create, {
                props: { pricing: fixturePricing, limits: LIMITS, can_submit: true },
                attachTo: document.body,
            });

            await wrapper.get('textarea').setValue('A lighthouse on a cliff at dawn, oil painting');
            for (const row of referenceRows) {
                await addReference(wrapper, row);
            }
            await wrapper.get('form').trigger('submit');
            await flushPromises();

            return wrapper;
        }

        function textarea(wrapper: VueWrapper): HTMLTextAreaElement {
            return wrapper.get<HTMLTextAreaElement>('textarea').element;
        }

        it('shows a prompt error under the prompt, keeps the input and moves focus to it', async () => {
            const wrapper = await submitLighthouseRejectedWith({ prompt: 'The prompt must be 4,000 characters or fewer.' });

            expect(textarea(wrapper).getAttribute('aria-invalid')).toBe('true');
            expect(descriptionOf(wrapper, textarea(wrapper))).toContain('The prompt must be 4,000 characters or fewer.');
            expect(textarea(wrapper).value).toBe('A lighthouse on a cliff at dawn, oil painting');
            expect(document.activeElement).toBe(textarea(wrapper));
            wrapper.unmount();
        });

        it('clears a field error as soon as that field changes', async () => {
            const wrapper = await submitLighthouseRejectedWith({ prompt: 'Enter a prompt.', size: 'Choose a size.' });

            await wrapper.get('textarea').setValue('A lighthouse at dusk');

            expect(textarea(wrapper).getAttribute('aria-invalid')).toBeNull();
            expect(visibleText(wrapper.get('form'))).not.toContain('Enter a prompt.');
            expect(visibleText(wrapper.get('form'))).toContain('Choose a size.');
            wrapper.unmount();
        });

        it('shows a size error on the size group', async () => {
            const wrapper = await submitLighthouseRejectedWith({ size: 'Choose a size.' });

            const group = wrapper.findAll('fieldset').find((fieldset) => fieldset.find('legend').text() === 'Size')!;
            expect(wrapper.get(`[id="${group.attributes('aria-describedby')}"]`).text()).toBe('Choose a size.');
            wrapper.unmount();
        });

        it('shows a reference error on the row it came from, skipping the blank rows that were not sent', async () => {
            const wrapper = await submitLighthouseRejectedWith({ 'reference_images.1': 'Enter an http:// or https:// image URL.' }, [
                'https://example.com/style.png',
                '   ',
                'https://example.com/a b.png',
            ]);

            const third = inputLabelled(wrapper, 'Reference image 3');
            expect(third.getAttribute('aria-invalid')).toBe('true');
            expect(descriptionOf(wrapper, third)).toBe('Enter an http:// or https:// image URL.');
            expect(inputLabelled(wrapper, 'Reference image 1').getAttribute('aria-invalid')).toBeNull();
            expect(third.value).toBe('https://example.com/a b.png');
            expect(document.activeElement).toBe(third);
            wrapper.unmount();
        });

        it('shows a list error on the references group', async () => {
            const wrapper = await submitLighthouseRejectedWith({ reference_images: 'Add up to 4 reference images.' });

            const group = wrapper.findAll('fieldset').find((fieldset) => fieldset.find('legend').text().startsWith('Reference images'))!;
            expect(wrapper.get(`[id="${group.attributes('aria-describedby')}"]`).text()).toBe('Add up to 4 reference images.');
            wrapper.unmount();
        });

        it.each([
            [
                'the throttle',
                { submission: "You're submitting too quickly. Try again in 42 s." },
                "You're submitting too quickly. Try again in 42 s.",
            ],
            ['a bad submission token', { submission_token: 'Reload the page and try again.' }, 'Reload the page and try again.'],
        ])('announces %s beside Generate and keeps the form as it was', async (_case, errors, message) => {
            const wrapper = await submitLighthouseRejectedWith(errors);

            expect(wrapper.get('[data-submit-area] [role="alert"]').text()).toBe(message);
            expect(textarea(wrapper).value).toBe('A lighthouse on a cliff at dawn, oil painting');
            expect(generateButton(wrapper).attributes('disabled')).toBeUndefined();
            wrapper.unmount();
        });

        it('has no alert before anything is submitted', () => {
            expect(mountPage().find('[role="alert"]').exists()).toBe(false);
        });
    });

    describe('reference images', () => {
        async function highLandscapeLighthouse(): Promise<VueWrapper> {
            const wrapper = mountPage();

            await wrapper.get('textarea').setValue(LIGHTHOUSE_PROMPT);
            await radio(wrapper, 'Quality', 'High').setValue(true);
            await radio(wrapper, 'Size', 'Landscape').setValue(true);

            return wrapper;
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
