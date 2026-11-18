import type * as InertiaVue from '@inertiajs/vue3';
import { vi } from 'vitest';
import { defineComponent } from 'vue';

/**
 * Pages are mounted outside a running Inertia app, so <Head> (which needs the
 * app's head manager) is replaced by a stub that keeps its props inspectable.
 */
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof InertiaVue>()),
    Head: defineComponent({
        name: 'InertiaHead',
        props: { title: { type: String, default: '' } },
        render: () => null,
    }),
}));
