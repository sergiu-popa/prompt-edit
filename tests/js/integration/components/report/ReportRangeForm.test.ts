import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import ReportRangeForm from '@/components/report/ReportRangeForm.vue';

import { descriptionOf, fillIn, inputLabelled } from '../../support/form';
import { givenGetVisitsFailWith, givenGetVisitsStayPending, page } from '../../support/inertia';

/** Attached to the document only when a test reads focus; that test unmounts it. */
function mountForm({ attached = false } = {}) {
    return mount(ReportRangeForm, {
        props: {
            range: { start_date: '2026-10-20', end_date: '2026-11-18' },
            limits: { max_days: 92, today: '2026-11-18' },
        },
        attachTo: attached ? document.body : undefined,
    });
}

describe('ReportRangeForm', () => {
    it('holds the shown range in two date fields that end today at the latest', () => {
        const wrapper = mountForm();

        for (const [label, value] of [
            ['From', '2026-10-20'],
            ['To', '2026-11-18'],
        ] as const) {
            const input = inputLabelled(wrapper, label);
            expect(input.type).toBe('date');
            expect(input.value).toBe(value);
            expect(input.max).toBe('2026-11-18');
            expect(descriptionOf(wrapper, input)).toBe('UTC dates, inclusive, up to 92 days.');
        }

        expect(wrapper.get('form').attributes('novalidate')).toBeDefined();
    });

    it('applies the range with one GET to /report, keeping the page state', async () => {
        const get = givenGetVisitsStayPending();
        const wrapper = mountForm();

        await fillIn(wrapper, 'From', '2026-11-01');
        await fillIn(wrapper, 'To', '2026-11-17');
        await wrapper.get('form').trigger('submit');

        expect(get).toHaveBeenCalledTimes(1);
        expect(get).toHaveBeenCalledWith(
            '/report',
            { start_date: '2026-11-01', end_date: '2026-11-17' },
            expect.objectContaining({ preserveState: true, preserveScroll: true }),
        );
    });

    it('shows a busy Apply and sends one request however often it is submitted', async () => {
        const get = givenGetVisitsStayPending();
        const wrapper = mountForm();

        await wrapper.get('form').trigger('submit');
        await wrapper.get('form').trigger('submit');

        const apply = wrapper.get('button[type="submit"]');
        expect(apply.text()).toBe('Applying…');
        expect(apply.attributes('aria-busy')).toBe('true');
        expect(get).toHaveBeenCalledTimes(1);
    });

    it('shows the errors under From and To, keeps the typed dates and focuses the first', async () => {
        givenGetVisitsFailWith({ start_date: 'Enter a valid date.', end_date: 'The range can be at most 92 days.' });
        const wrapper = mountForm({ attached: true });

        await fillIn(wrapper, 'From', '2026-08-01');
        await fillIn(wrapper, 'To', '2026-11-01');
        await wrapper.get('form').trigger('submit');
        await wrapper.vm.$nextTick();

        const from = inputLabelled(wrapper, 'From');
        const to = inputLabelled(wrapper, 'To');
        expect(from.getAttribute('aria-invalid')).toBe('true');
        expect(to.getAttribute('aria-invalid')).toBe('true');
        expect(descriptionOf(wrapper, from)).toBe('Enter a valid date. UTC dates, inclusive, up to 92 days.');
        expect(descriptionOf(wrapper, to)).toBe('The range can be at most 92 days. UTC dates, inclusive, up to 92 days.');
        expect(from.value).toBe('2026-08-01');
        expect(to.value).toBe('2026-11-01');
        expect(document.activeElement).toBe(from);
        expect(wrapper.get('button[type="submit"]').text()).toBe('Apply');

        wrapper.unmount();
    });

    it('shows the errors a full page load brings with it', () => {
        page.props.errors = { end_date: 'The range can be at most 92 days.' };

        const wrapper = mountForm();

        const to = inputLabelled(wrapper, 'To');
        expect(to.getAttribute('aria-invalid')).toBe('true');
        expect(descriptionOf(wrapper, to)).toBe('The range can be at most 92 days. UTC dates, inclusive, up to 92 days.');
        expect(inputLabelled(wrapper, 'From').getAttribute('aria-invalid')).toBeNull();
    });
});
