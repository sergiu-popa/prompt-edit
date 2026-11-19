/* eslint-disable vue/one-component-per-file -- stand-ins for Inertia's own components */
import type * as InertiaVue from '@inertiajs/vue3';
import { afterEach, beforeEach, vi } from 'vitest';
import { defineComponent, h } from 'vue';

import { givenAGuest, givenThePageIsAt } from './support/inertia';

/**
 * Pages are mounted outside a running Inertia app, so the parts that need one are stubbed:
 * - <Head> (which needs the app's head manager) keeps its props inspectable;
 * - usePage() returns the test-controlled page in support/inertia.ts;
 * - <Link> renders a plain <a> with an inspectable href.
 */
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { page } = await import('./support/inertia');

    return {
        ...(await importOriginal<typeof InertiaVue>()),
        Head: defineComponent({
            name: 'InertiaHead',
            props: { title: { type: String, default: '' } },
            render: () => null,
        }),
        usePage: () => page,
        Link: defineComponent({
            name: 'InertiaLink',
            props: { href: { type: String, required: true } },
            setup:
                (props, { slots }) =>
                () =>
                    h('a', { href: props.href }, slots.default?.()),
        }),
    };
});

beforeEach(() => {
    givenAGuest();
    givenThePageIsAt('/');
});

afterEach(() => {
    vi.restoreAllMocks();
});
