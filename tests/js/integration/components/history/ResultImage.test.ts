import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import { visibleText } from '../../support/text';

import ResultImage from '@/components/history/ResultImage.vue';

const SRC = 'https://pixelforge.promptedit.dev/images/gen_01.png?expires=1763596799&signature=test';
const PROMPT = 'A lighthouse on a cliff at dawn, oil painting';

function mountImage(props: Partial<{ src: string; alt: string }> = {}): VueWrapper {
    return mount(ResultImage, { props: { src: SRC, alt: PROMPT, ...props } });
}

describe('ResultImage', () => {
    it('links the thumbnail to the full-size image in a new tab, saying so to screen readers', () => {
        const link = mountImage().get('a');

        expect(link.attributes('href')).toBe(SRC);
        expect(link.attributes('target')).toBe('_blank');
        expect(link.attributes('rel')).toBe('noopener noreferrer');
        expect(link.get('.sr-only').text()).toBe('(opens in a new tab)');
    });

    it('shows the image with the prompt as its alt text, loaded lazily', () => {
        const image = mountImage().get('img');

        expect(image.attributes('src')).toBe(SRC);
        expect(image.attributes('alt')).toBe(PROMPT);
        expect(image.attributes('loading')).toBe('lazy');
        expect(image.attributes('decoding')).toBe('async');
        expect(image.classes()).toContain('object-cover');
    });

    it('fixes its box before the image loads: 3:2 across a phone, a square from sm', () => {
        const wrapper = mountImage();

        expect(wrapper.attributes('data-state')).toBe('image');
        expect(wrapper.classes()).toEqual(expect.arrayContaining(['aspect-3/2', 'w-full', 'sm:size-34', 'sm:aspect-square']));
        expect(visibleText(wrapper)).toBe('(opens in a new tab)');
    });
});
