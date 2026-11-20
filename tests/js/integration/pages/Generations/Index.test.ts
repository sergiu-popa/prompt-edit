import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

import { givenFlash, givenNoFlash, givenReloadsFail, givenReloadsSucceed, givenSignedInAs, givenThePageIsAt } from '../../support/inertia';
import { visibleText } from '../../support/text';

import Index from '@/pages/Generations/Index.vue';
import type { Generation, GenerationHistory, GenerationStatus } from '@/types/generation';

const BASE = 'http://prompt-edit.localhost/generations';

const SERVER_TIME = '2026-11-19T12:00:00.000Z';

const LABELS: Record<GenerationStatus, string> = {
    pending: 'Queued',
    queued: 'Queued',
    processing: 'Processing',
    succeeded: 'Succeeded',
    failed: 'Failed',
};

function generation(id: string, prompt: string, status: GenerationStatus = 'queued'): Generation {
    return {
        id,
        prompt,
        size: '1536x1024',
        quality: 'high',
        reference_image_count: 1,
        status,
        status_label: LABELS[status],
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
}

function pageOf(data: Generation[], { page = 1, lastPage = 1, total = data.length } = {}): GenerationHistory {
    const url = (number: number) => `${BASE}?page=${number}`;

    return {
        data,
        links: {
            first: url(1),
            last: url(lastPage),
            prev: page > 1 ? url(page - 1) : null,
            next: page < lastPage ? url(page + 1) : null,
        },
        meta: {
            current_page: page,
            from: data.length > 0 ? (page - 1) * 20 + 1 : null,
            to: data.length > 0 ? (page - 1) * 20 + data.length : null,
            last_page: lastPage,
            per_page: 20,
            total,
            path: BASE,
            links: [],
        },
        server_time: SERVER_TIME,
    };
}

function mountPage(generations: GenerationHistory): VueWrapper {
    return mount(Index, { props: { generations } });
}

function link(wrapper: VueWrapper, text: string) {
    const found = wrapper.findAll('main a').find((candidate) => visibleText(candidate) === text);

    if (!found) {
        throw new Error(`No "${text}" link.`);
    }

    return found;
}

function itemTexts(wrapper: VueWrapper): string[] {
    return wrapper.findAll('main ul > li').map((item) => visibleText(item));
}

function statusNote(wrapper: VueWrapper) {
    return wrapper.get('[role="status"]');
}

describe('History page', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(SERVER_TIME));
        givenSignedInAs({ id: 1, name: 'Ada Lovelace', email: 'ada@example.com' });
        givenThePageIsAt('/generations');
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('titles the document History and headlines the page, with a link to a new image', () => {
        const wrapper = mountPage(pageOf([generation('a', 'A lighthouse')]));

        expect(wrapper.findComponent({ name: 'InertiaHead' }).props('title')).toBe('History');
        expect(wrapper.get('h1').text()).toBe('History');
        expect(wrapper.get('main header p').text()).toBe('Your generations, newest first.');
        expect(link(wrapper, 'New image').attributes('href')).toBe('/dashboard');
    });

    it('lists the generations in the order the server sent them', () => {
        const wrapper = mountPage(pageOf([generation('c', 'Newest'), generation('b', 'Middle'), generation('a', 'Oldest')]));

        const items = wrapper.findAll('main ul > li');
        expect(items.map((item) => item.get('[data-prompt]').text())).toStrictEqual(['Newest', 'Middle', 'Oldest']);
        expect(wrapper.text()).not.toContain('No generations yet.');
    });

    it('shows the empty state, linking to Generate, when the user has no generations', () => {
        const wrapper = mountPage(pageOf([], { total: 0 }));

        expect(wrapper.find('main ul').exists()).toBe(false);
        expect(visibleText(wrapper.get('main'))).toContain('No generations yet. Write a prompt and see the price before you pay.');
        expect(link(wrapper, 'Create your first image →').attributes('href')).toBe('/dashboard');
        expect(wrapper.find('nav[aria-label="Pagination"]').exists()).toBe(false);
    });

    it('says a page past the last one is empty and links back to the first page', () => {
        const wrapper = mountPage(pageOf([], { page: 4, lastPage: 3, total: 41 }));

        expect(visibleText(wrapper.get('main'))).toContain('Nothing on this page.');
        expect(wrapper.text()).not.toContain('No generations yet.');
        expect(link(wrapper, 'Go to the first page').attributes('href')).toBe(`${BASE}?page=1`);
    });

    it('pages through the history with Previous and Next', () => {
        const wrapper = mountPage(pageOf([generation('a', 'A lighthouse')], { page: 2, lastPage: 3, total: 41 }));

        const pagination = wrapper.get('nav[aria-label="Pagination"]');
        expect(visibleText(pagination)).toBe('Previous Page 2 of 3 Next');
        expect(link(wrapper, 'Previous').attributes('href')).toBe(`${BASE}?page=1`);
        expect(link(wrapper, 'Next').attributes('href')).toBe(`${BASE}?page=3`);
    });

    it('disables Previous on the first page and Next on the last', () => {
        const first = mountPage(pageOf([generation('a', 'A lighthouse')], { page: 1, lastPage: 3, total: 41 })).get(
            'nav[aria-label="Pagination"]',
        );
        const last = mountPage(pageOf([generation('a', 'A lighthouse')], { page: 3, lastPage: 3, total: 41 })).get(
            'nav[aria-label="Pagination"]',
        );

        expect(first.findAll('a').map((anchor) => anchor.text())).toStrictEqual(['Next']);
        expect(first.get('[aria-disabled="true"]').text()).toBe('Previous');
        expect(last.findAll('a').map((anchor) => anchor.text())).toStrictEqual(['Previous']);
        expect(last.get('[aria-disabled="true"]').text()).toBe('Next');
    });

    it('hides the pagination when everything fits on one page', () => {
        const wrapper = mountPage(pageOf([generation('a', 'A lighthouse')]));

        expect(wrapper.find('nav[aria-label="Pagination"]').exists()).toBe(false);
    });

    it('highlights only the generation the server flashed after a submit', () => {
        givenFlash({ highlighted_generation_id: 'b' });

        const wrapper = mountPage(pageOf([generation('c', 'Newest'), generation('b', 'Just made'), generation('a', 'Oldest')]));

        const highlighted = wrapper.findAll('[data-highlighted="true"]');
        expect(highlighted.map((item) => item.get('[data-prompt]').text())).toStrictEqual(['Just made']);
        expect(wrapper.findAll('main ul > li').filter((item) => item.text().includes('Just submitted'))).toHaveLength(1);
    });

    it('highlights nothing without a flash, as after a reload', () => {
        const wrapper = mountPage(pageOf([generation('b', 'Just made'), generation('a', 'Oldest')]));

        expect(wrapper.findAll('[data-highlighted="true"]')).toHaveLength(0);
        expect(wrapper.text()).not.toContain('Just submitted');
    });

    describe('live updates', () => {
        it('reloads only the generations 3 s after loading while one is in progress', () => {
            const reload = givenReloadsSucceed();
            mountPage(pageOf([generation('b', 'Running', 'processing'), generation('a', 'Done', 'succeeded')]));

            vi.advanceTimersByTime(2_999);
            expect(reload).not.toHaveBeenCalled();

            vi.advanceTimersByTime(1);
            expect(reload).toHaveBeenCalledOnce();
            expect(reload.mock.calls[0]?.[0]).toMatchObject({ only: ['generations'] });
        });

        it.each<GenerationStatus>(['pending', 'queued', 'processing'])('polls while a generation is %s', (status) => {
            const reload = givenReloadsSucceed();
            mountPage(pageOf([generation('a', 'Running', status)]));

            vi.advanceTimersByTime(3_000);

            expect(reload).toHaveBeenCalledOnce();
        });

        it('never polls a page whose generations have all finished', () => {
            const reload = givenReloadsSucceed();
            mountPage(pageOf([generation('b', 'Done', 'succeeded'), generation('a', 'Broken', 'failed')]));

            vi.advanceTimersByTime(60_000);

            expect(reload).not.toHaveBeenCalled();
        });

        it('stops polling once the last one finishes', async () => {
            const reload = givenReloadsSucceed();
            const wrapper = mountPage(pageOf([generation('a', 'Running', 'processing')]));
            vi.advanceTimersByTime(3_000);

            await wrapper.setProps({ generations: pageOf([generation('a', 'Running', 'succeeded')]) });
            vi.advanceTimersByTime(60_000);

            expect(reload).toHaveBeenCalledOnce();
        });

        it('keeps every card, and an expanded prompt, when a poll changes a status', async () => {
            givenReloadsSucceed();
            const longPrompt = 'A lighthouse '.repeat(10);
            const wrapper = mountPage(pageOf([generation('b', longPrompt, 'processing'), generation('a', 'Oldest', 'succeeded')]));
            const rootsBefore = wrapper.findAll('main ul > li > div').map((item) => item.element);
            await wrapper.get('button[aria-expanded]').trigger('click');

            vi.advanceTimersByTime(3_000);
            await wrapper.setProps({
                generations: pageOf([generation('b', longPrompt, 'succeeded'), generation('a', 'Oldest', 'succeeded')]),
            });

            const rootsAfter = wrapper.findAll('main ul > li > div').map((item) => item.element);
            expect(rootsAfter).toHaveLength(2);
            rootsAfter.forEach((root, index) => expect(root).toBe(rootsBefore[index]));
            expect(wrapper.get('button[aria-expanded]').attributes('aria-expanded')).toBe('true');
            expect(itemTexts(wrapper)[0]).toContain('Succeeded');
        });

        it('keeps the just-submitted highlight after a poll clears the flash', async () => {
            givenReloadsSucceed();
            givenFlash({ highlighted_generation_id: 'a' });
            const wrapper = mountPage(pageOf([generation('a', 'Just made', 'queued')]));

            vi.advanceTimersByTime(3_000);
            givenNoFlash();
            await wrapper.setProps({ generations: pageOf([generation('a', 'Just made', 'processing')]) });

            expect(wrapper.findAll('[data-highlighted="true"]')).toHaveLength(1);
            expect(visibleText(wrapper.get('main ul'))).toContain('Just submitted');
        });

        it('says live updates are paused after a failed poll, and clears it after the next success', async () => {
            givenReloadsFail();
            const wrapper = mountPage(pageOf([generation('a', 'Running', 'processing')]));

            expect(visibleText(statusNote(wrapper))).toBe('');

            vi.advanceTimersByTime(3_000);
            await nextTick();

            expect(visibleText(statusNote(wrapper))).toBe('⟳ Live updates paused — retrying…');
            expect(itemTexts(wrapper)[0]).toContain('Running');

            vi.restoreAllMocks();
            const reload = givenReloadsSucceed();
            vi.advanceTimersByTime(6_000);
            await nextTick();

            expect(reload).toHaveBeenCalledOnce();
            expect(visibleText(statusNote(wrapper))).toBe('');
        });

        it('stops polling and the clock once the page unmounts', () => {
            const reload = givenReloadsSucceed();
            const wrapper = mountPage(pageOf([generation('a', 'Running', 'processing')]));

            wrapper.unmount();
            vi.advanceTimersByTime(60_000);

            expect(reload).not.toHaveBeenCalled();
            expect(vi.getTimerCount()).toBe(0);
        });

        it("times a processing generation on the server's clock, though the browser's is 2 min fast", async () => {
            givenReloadsSucceed();
            vi.setSystemTime(Date.parse(SERVER_TIME) + 2 * 60_000);
            const running = { ...generation('a', 'Running', 'processing'), started_at: '2026-11-19T11:59:46Z' };

            const wrapper = mountPage(pageOf([running]));

            expect(wrapper.get('[data-tone]').text()).toBe('Processing · 14 s');

            vi.advanceTimersByTime(1_000);
            await nextTick();

            expect(wrapper.get('[data-tone]').text()).toBe('Processing · 15 s');
        });
    });
});
