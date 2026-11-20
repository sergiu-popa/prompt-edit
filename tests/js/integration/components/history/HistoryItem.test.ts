import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { visibleText } from '../../support/text';

import HistoryItem from '@/components/history/HistoryItem.vue';
import { formatExactTimestamp } from '@/lib/relativeTime';
import type { Generation, GenerationStatus } from '@/types/generation';

const LIGHTHOUSE: Generation = {
    id: '01jc2z8q4n6v0m3h7x5t9r2b1k',
    prompt: 'A lighthouse on a cliff at dawn, oil painting',
    size: '1536x1024',
    quality: 'high',
    reference_image_count: 1,
    status: 'pending',
    status_label: 'Queued',
    user_estimate_nanodollars: { min: 347_750_000, max: 457_150_000 },
    created_at: '2026-11-19T11:57:00Z',
};

function mountItem(changes: Partial<Generation> = {}, highlighted = false): VueWrapper {
    return mount(HistoryItem, { props: { generation: { ...LIGHTHOUSE, ...changes }, highlighted } });
}

function prompt(wrapper: VueWrapper) {
    return wrapper.get('[data-prompt]');
}

describe('HistoryItem', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-11-19T12:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it.each<[GenerationStatus, string, string]>([
        ['pending', 'Queued', 'neutral'],
        ['queued', 'Queued', 'neutral'],
        ['processing', 'Processing', 'info'],
        ['succeeded', 'Succeeded', 'success'],
        ['failed', 'Failed', 'danger'],
    ])('badges a %s generation "%s" in the %s tone', (status, label, tone) => {
        const badge = mountItem({ status, status_label: label }).get('[data-tone]');

        expect(badge.text()).toBe(label);
        expect(badge.attributes('data-tone')).toBe(tone);
    });

    it("shows the server's label, whatever the status", () => {
        expect(mountItem({ status: 'pending', status_label: 'Waiting' }).get('[data-tone]').text()).toBe('Waiting');
    });

    it('describes the image and counts its references', () => {
        expect(visibleText(mountItem().get('[data-meta]'))).toBe('High · 1536×1024 · 1 reference');
        expect(visibleText(mountItem({ reference_image_count: 0 }).get('[data-meta]'))).toBe('High · 1536×1024 · No references');
        expect(visibleText(mountItem({ reference_image_count: 2 }).get('[data-meta]'))).toBe('High · 1536×1024 · 2 references');
        expect(visibleText(mountItem({ quality: 'medium', size: '1024x1024' }).get('[data-meta]'))).toBe(
            'Medium · 1024×1024 · 1 reference',
        );
    });

    it('shows the estimated range the user saw, with the exact values one tip away', () => {
        const wrapper = mountItem();

        expect(visibleText(wrapper.get('[data-estimate]'))).toBe('Est. $0.3478 – $0.4572');
        expect(wrapper.findAll('[role="tooltip"]').map((tip) => tip.text())).toStrictEqual(['$0.347750000', '$0.457150000']);
    });

    it('shows one amount when the range is a single figure', () => {
        expect(visibleText(mountItem({ user_estimate_nanodollars: { min: 58_150_000, max: 58_150_000 } }).get('[data-estimate]'))).toBe(
            'Est. $0.0582',
        );
    });

    it('clamps the prompt to two lines, with the full prompt on hover', () => {
        const text = prompt(mountItem());

        expect(text.text()).toBe(LIGHTHOUSE.prompt);
        expect(text.classes()).toContain('line-clamp-2');
        expect(text.attributes('title')).toBe(LIGHTHOUSE.prompt);
    });

    it('offers no toggle for a prompt that fits', () => {
        expect(
            mountItem({ prompt: 'a'.repeat(100) })
                .find('button[aria-expanded]')
                .exists(),
        ).toBe(false);
    });

    it.each([
        ['over 100 characters', 'a'.repeat(101)],
        ['with a line break', 'A lighthouse\non a cliff'],
        ['over 100 code points of emoji', '🎨'.repeat(101)],
    ])('expands a prompt %s and collapses it again', async (_case, longPrompt) => {
        const wrapper = mountItem({ prompt: longPrompt });
        const toggle = wrapper.get('button[aria-expanded]');

        expect(toggle.text()).toBe('Show full prompt');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(toggle.attributes('aria-controls')).toBe(prompt(wrapper).attributes('id'));

        await toggle.trigger('click');

        expect(toggle.text()).toBe('Show less');
        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(prompt(wrapper).classes()).not.toContain('line-clamp-2');
        expect(prompt(wrapper).classes()).toContain('whitespace-pre-line');

        await toggle.trigger('click');

        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(prompt(wrapper).classes()).toContain('line-clamp-2');
    });

    it('does not count a 100-emoji prompt as long, though it is 200 UTF-16 units', () => {
        expect(
            mountItem({ prompt: '🎨'.repeat(100) })
                .find('button[aria-expanded]')
                .exists(),
        ).toBe(false);
    });

    it('shows when it was created as a relative time, with the exact instant on hover', () => {
        const time = mountItem().get('time');

        expect(time.text()).toBe('3 min ago');
        expect(time.attributes('datetime')).toBe('2026-11-19T11:57:00Z');
        expect(time.attributes('title')).toBe(formatExactTimestamp('2026-11-19T11:57:00Z'));
    });

    it('reads a generation created this minute as just now', () => {
        expect(mountItem({ created_at: '2026-11-19T11:59:30Z' }).get('time').text()).toBe('just now');
    });

    it('tags the just-submitted generation in words, not by colour alone', () => {
        const wrapper = mountItem({}, true);

        expect(wrapper.attributes('data-highlighted')).toBe('true');
        expect(visibleText(wrapper)).toContain('Just submitted');
    });

    it('tags no other generation', () => {
        const wrapper = mountItem();

        expect(wrapper.attributes('data-highlighted')).toBeUndefined();
        expect(wrapper.text()).not.toContain('Just submitted');
    });
});
