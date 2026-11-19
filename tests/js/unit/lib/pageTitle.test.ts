import { describe, expect, it } from 'vitest';

import { formatPageTitle } from '@/lib/pageTitle';

describe('formatPageTitle', () => {
    it('appends the app name to a page title', () => {
        expect(formatPageTitle('History', 'PromptEdit')).toBe('History · PromptEdit');
    });

    it('falls back to the app name when the page has no title', () => {
        expect(formatPageTitle('', 'PromptEdit')).toBe('PromptEdit');
    });

    it('ignores a whitespace-only page title', () => {
        expect(formatPageTitle('   ', 'PromptEdit')).toBe('PromptEdit');
    });
});
