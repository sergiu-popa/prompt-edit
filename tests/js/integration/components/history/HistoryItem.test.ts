import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { visibleText } from '../../support/text';

import HistoryItem from '@/components/history/HistoryItem.vue';
import ResultImage from '@/components/history/ResultImage.vue';
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
    started_at: null,
    completed_at: null,
    user_charge_nanodollars: null,
    image_url: null,
    image_url_expires_at: null,
    image_expired: false,
    failure_message: null,
};

/** The server's clock, which the browser's agrees with unless a test skews it. */
const NOW_MS = Date.parse('2026-11-19T12:00:00Z');

function mountItem(changes: Partial<Generation> = {}, highlighted = false, now = NOW_MS): VueWrapper {
    return mount(HistoryItem, { props: { generation: { ...LIGHTHOUSE, ...changes }, highlighted, now } });
}

const IMAGE_URL = 'https://pixelforge.promptedit.dev/images/gen_01.png?expires=1763596799&signature=test';

/** The docs' Lighthouse, succeeded: charged 381,887,500 n$. */
const SUCCEEDED: Partial<Generation> = {
    status: 'succeeded',
    status_label: 'Succeeded',
    started_at: '2026-11-19T11:59:42Z',
    completed_at: '2026-11-19T11:59:59Z',
    user_charge_nanodollars: 381_887_500,
    image_url: IMAGE_URL,
    image_url_expires_at: '2026-11-20T11:59:59Z',
    image_expired: false,
};

function badge(wrapper: VueWrapper) {
    return wrapper.get('[data-tone]');
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

    it('reads a processing generation with its time since it started, on the given clock', async () => {
        const wrapper = mountItem({ status: 'processing', status_label: 'Processing', started_at: '2026-11-19T11:59:46Z' });

        expect(badge(wrapper).text()).toBe('Processing · 14 s');
        expect(badge(wrapper).classes()).toContain('tabular-nums');

        await wrapper.setProps({ now: NOW_MS + 1_000 });

        expect(badge(wrapper).text()).toBe('Processing · 15 s');
    });

    it('reads a processing generation with no start time as just Processing', () => {
        expect(badge(mountItem({ status: 'processing', status_label: 'Processing', started_at: null })).text()).toBe('Processing');
    });

    it('adds no time to a queued generation', () => {
        expect(badge(mountItem({ status: 'queued', status_label: 'Queued', started_at: '2026-11-19T11:59:46Z' })).text()).toBe('Queued');
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

    it('shows a succeeded generation charged, beside the estimate it was shown', () => {
        const wrapper = mountItem(SUCCEEDED);

        expect(visibleText(wrapper.get('[data-charge]'))).toBe('Charged $0.3819');
        expect(visibleText(wrapper.get('[data-estimate]'))).toBe('Est. $0.3478 – $0.4572');
        expect(wrapper.get('[data-charge]').element.parentElement).toBe(wrapper.get('[data-estimate]').element.parentElement);
    });

    it.each(['hover', 'keyboard focus'] as const)('shows the exact charge on %s', async (how) => {
        const charge = mountItem(SUCCEEDED).get('[data-charge]');
        const tip = charge.get<HTMLElement>('[role="tooltip"]');

        expect(tip.text()).toBe('$0.381887500');
        expect(tip.element.style.display).toBe('none');

        if (how === 'hover') {
            await charge.get('[data-charge] > span').trigger('pointerenter', { pointerType: 'mouse' });
        } else {
            await charge.get('button').trigger('focus');
        }

        expect(tip.element.style.display).toBe('');
    });

    it('says when it completed, with the exact instant on hover', () => {
        const completed = mountItem(SUCCEEDED).get('[data-completed]');
        const time = completed.get('time');

        expect(visibleText(completed)).toMatch(/^Completed \d{1,2}:\d{2}\s[AP]M$/);
        expect(time.attributes('datetime')).toBe('2026-11-19T11:59:59Z');
        expect(time.attributes('title')).toBe(formatExactTimestamp('2026-11-19T11:59:59Z'));
    });

    it('shows the image of a succeeded generation, described by its prompt', () => {
        const image = mountItem(SUCCEEDED).getComponent(ResultImage);

        expect(image.props()).toStrictEqual({ src: IMAGE_URL, alt: LIGHTHOUSE.prompt });
    });

    it.each<[GenerationStatus, string]>([
        ['queued', 'Queued'],
        ['processing', 'Processing'],
    ])('shows no charge, completion or image while %s', (status, label) => {
        const wrapper = mountItem({ status, status_label: label });

        expect(wrapper.find('[data-charge]').exists()).toBe(false);
        expect(wrapper.find('[data-completed]').exists()).toBe(false);
        expect(wrapper.findComponent(ResultImage).exists()).toBe(false);
        expect(visibleText(wrapper.get('[data-estimate]'))).toBe('Est. $0.3478 – $0.4572');
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

    it("reads the created time on the server's clock, not the browser's", () => {
        vi.setSystemTime(new Date('2026-11-19T14:00:00Z'));

        expect(mountItem().get('time').text()).toBe('3 min ago');
        expect(
            mountItem({}, false, NOW_MS + 2 * 60_000)
                .get('time')
                .text(),
        ).toBe('5 min ago');
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
