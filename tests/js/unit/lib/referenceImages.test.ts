import { describe, expect, it } from 'vitest';

import { countedReferenceImages, isHttpImageUrl } from '@/lib/referenceImages';

describe('isHttpImageUrl', () => {
    it.each(['https://example.com/style.png', 'http://x.test/a', 'HTTPS://EXAMPLE.COM/A.PNG', '  https://example.com/a.png  '])(
        'accepts %j',
        (value) => {
            expect(isHttpImageUrl(value)).toBe(true);
        },
    );

    it.each(['example.com/cat.png', 'ftp://example.com/a.png', 'javascript:alert(1)', 'https://', 'https://exa mple.com', ''])(
        'rejects %j',
        (value) => {
            expect(isHttpImageUrl(value)).toBe(false);
        },
    );
});

describe('countedReferenceImages', () => {
    it('drops blank and whitespace-only rows and keeps the order of the rest, trimmed', () => {
        expect(
            countedReferenceImages(['', 'https://b.test/b.png', '   ', 'example.com/cat.png', '\t', ' https://a.test/a.png ']),
        ).toStrictEqual(['https://b.test/b.png', 'example.com/cat.png', 'https://a.test/a.png']);
    });

    it('counts an invalid URL, so the estimate shows what submitting will cost once it is fixed', () => {
        expect(countedReferenceImages(['example.com/cat.png'])).toHaveLength(1);
    });

    it('counts nothing for no rows', () => {
        expect(countedReferenceImages([])).toStrictEqual([]);
    });
});
