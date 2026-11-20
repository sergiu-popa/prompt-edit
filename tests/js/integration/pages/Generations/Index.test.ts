import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

import { givenFlash, givenSignedInAs, givenThePageIsAt } from '../../support/inertia';
import { visibleText } from '../../support/text';

import Index from '@/pages/Generations/Index.vue';
import type { Generation } from '@/types/generation';
import type { Paginated } from '@/types/pagination';

const BASE = 'http://prompt-edit.localhost/generations';

function generation(id: string, prompt: string): Generation {
    return {
        id,
        prompt,
        size: '1536x1024',
        quality: 'high',
        reference_image_count: 1,
        status: 'queued',
        status_label: 'Queued',
        user_estimate_nanodollars: { min: 347_750_000, max: 457_150_000 },
        created_at: '2026-11-19T11:57:00Z',
    };
}

function pageOf(data: Generation[], { page = 1, lastPage = 1, total = data.length } = {}): Paginated<Generation> {
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
    };
}

function mountPage(generations: Paginated<Generation>): VueWrapper {
    return mount(Index, { props: { generations } });
}

function link(wrapper: VueWrapper, text: string) {
    const found = wrapper.findAll('main a').find((candidate) => visibleText(candidate) === text);

    if (!found) {
        throw new Error(`No "${text}" link.`);
    }

    return found;
}

describe('History page', () => {
    beforeEach(() => {
        givenSignedInAs({ id: 1, name: 'Ada Lovelace', email: 'ada@example.com' });
        givenThePageIsAt('/generations');
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
});
