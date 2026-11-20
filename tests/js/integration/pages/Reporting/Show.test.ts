import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

import { inputLabelled } from '../../support/form';
import { givenSignedInAs } from '../../support/inertia';

import Show from '@/pages/Reporting/Show.vue';
import type { OperatorReport, ReportDay, ReportFigures } from '@/types/report';

const ZERO: ReportFigures = { succeeded: 0, spend_nanodollars: 0, provider_cost_nanodollars: 0, profit_nanodollars: 0 };

function day(date: string, figures: ReportFigures = ZERO): ReportDay {
    return { date, ...figures };
}

/** The story's worked example (AC 11, 13, 14) over 2026-11-15 to 2026-11-18. */
const WORKED_EXAMPLE: OperatorReport = {
    days: [
        day('2026-11-18'),
        day('2026-11-17', { succeeded: 1, spend_nanodollars: 0, provider_cost_nanodollars: 17_480_000, profit_nanodollars: -17_480_000 }),
        day('2026-11-16', {
            succeeded: 2,
            spend_nanodollars: 93_243_750,
            provider_cost_nanodollars: 74_595_000,
            profit_nanodollars: 18_648_750,
        }),
        day('2026-11-15'),
    ],
    users: [
        {
            user_id: 2,
            email: 'b@example.com',
            succeeded: 1,
            spend_nanodollars: 74_337_500,
            provider_cost_nanodollars: 59_470_000,
            profit_nanodollars: 14_867_500,
        },
        {
            user_id: 1,
            email: 'a@example.com',
            succeeded: 2,
            spend_nanodollars: 18_906_250,
            provider_cost_nanodollars: 32_605_000,
            profit_nanodollars: -13_698_750,
        },
    ],
    totals: { succeeded: 3, spend_nanodollars: 93_243_750, provider_cost_nanodollars: 92_075_000, profit_nanodollars: 1_168_750 },
};

function mountShow(report: OperatorReport = WORKED_EXAMPLE) {
    return mount(Show, {
        props: {
            range: { start_date: '2026-11-15', end_date: '2026-11-18' },
            limits: { max_days: 92, today: '2026-11-18' },
            report,
        },
    });
}

describe('Reporting/Show', () => {
    beforeEach(() => {
        givenSignedInAs({ id: 9, name: 'Operator', email: 'operator@promptedit.test' });
    });

    it('titles the document Report and headlines the page', () => {
        const wrapper = mountShow();

        expect(wrapper.findComponent({ name: 'InertiaHead' }).props('title')).toBe('Report');
        expect(wrapper.get('h1').text()).toBe('Operator report');
    });

    it('describes the range it shows, in UTC', () => {
        expect(mountShow().get('main header p').text()).toBe(
            'Spend, Pixelforge cost and profit per UTC day, from 2026-11-15 to 2026-11-18.',
        );
    });

    it('lets the operator choose the range, starting from the one shown', () => {
        const wrapper = mountShow();

        expect(inputLabelled(wrapper, 'From').value).toBe('2026-11-15');
        expect(inputLabelled(wrapper, 'To').value).toBe('2026-11-18');
        expect(wrapper.get('button[type="submit"]').text()).toBe('Apply');
    });
});
