import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

import { givenSignedInAs } from '../../support/inertia';

import Show from '@/pages/Reporting/Show.vue';

describe('Reporting/Show', () => {
    beforeEach(() => {
        givenSignedInAs({ id: 1, name: 'Operator', email: 'operator@promptedit.test' });
    });

    it('titles the document Report and headlines the page', () => {
        const wrapper = mount(Show);

        expect(wrapper.findComponent({ name: 'InertiaHead' }).props('title')).toBe('Report');
        expect(wrapper.get('h1').text()).toBe('Operator report');
    });
});
