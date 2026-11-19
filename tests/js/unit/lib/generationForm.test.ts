import { describe, expect, it } from 'vitest';

import { canSubmitGeneration } from '@/lib/generationForm';
import type { GenerationFormInput } from '@/types/generation';

const LIMITS = { max_prompt_characters: 4_000, max_reference_images: 4 };

const LIGHTHOUSE: GenerationFormInput = {
    prompt: 'A lighthouse on a cliff at dawn, oil painting',
    size: '1536x1024',
    quality: 'high',
    reference_images: ['https://example.com/style.png'],
};

function canSubmit(changes: Partial<GenerationFormInput> = {}, estimateAvailable = true): boolean {
    return canSubmitGeneration({ ...LIGHTHOUSE, ...changes }, LIMITS, estimateAvailable);
}

describe('canSubmitGeneration', () => {
    it('allows a valid Lighthouse request', () => {
        expect(canSubmit()).toBe(true);
    });

    it.each([
        ['empty', ''],
        ['spaces', '   '],
        ['a newline and a tab', '\n\t'],
        ['no-break spaces', '  '],
        ['ideographic spaces', '　　'],
        ['a byte order mark', '﻿'],
        ['a line separator', ' '],
    ])('refuses a prompt of %s', (_case, prompt) => {
        expect(canSubmit({ prompt })).toBe(false);
    });

    it('allows 4,000 code points and refuses 4,001, counting each emoji once', () => {
        expect(canSubmit({ prompt: 'a'.repeat(3_998) + '🎨🎨' })).toBe(true);
        expect(canSubmit({ prompt: 'a'.repeat(3_999) + '🎨🎨' })).toBe(false);
    });

    it('refuses a prompt with a lone surrogate, which the server could not count the same way', () => {
        expect(canSubmit({ prompt: 'A lighthouse \uD800' })).toBe(false);
        expect(canSubmit({ prompt: '\uDC00 A lighthouse' })).toBe(false);
    });

    it('allows a paired emoji', () => {
        expect(canSubmit({ prompt: 'A lighthouse 🎨👍🏽' })).toBe(true);
    });

    it('allows up to 4 counted references and refuses a fifth', () => {
        const url = 'https://example.com/style.png';

        expect(canSubmit({ reference_images: [url, url, url, url] })).toBe(true);
        expect(canSubmit({ reference_images: [url, url, url, url, url] })).toBe(false);
    });

    it('ignores blank rows, as the estimate does', () => {
        const url = 'https://example.com/style.png';

        expect(canSubmit({ reference_images: [url, '', '   ', url, url, url] })).toBe(true);
        expect(canSubmit({ reference_images: [] })).toBe(true);
    });

    it.each(['example.com/a.png', 'ftp://example.com/a.png', 'javascript:alert(1)', 'https://'])(
        'refuses a counted reference of the wrong shape: %s',
        (url) => {
            expect(canSubmit({ reference_images: ['https://example.com/style.png', url] })).toBe(false);
        },
    );

    it('allows a reference with spaces around it, which is sent trimmed', () => {
        expect(canSubmit({ reference_images: ['  https://example.com/style.png  '] })).toBe(true);
    });

    it('refuses while the estimate is unavailable, so the user always sees a price first', () => {
        expect(canSubmit({}, false)).toBe(false);
    });
});
