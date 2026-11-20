import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

import { inputLabelled } from '../../support/form';
import { givenSignedInAs } from '../../support/inertia';
import { visibleText } from '../../support/text';

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

    it('sums the range at the top', () => {
        const summary = mountShow().get('dl');

        expect(summary.findAll('dt').map((term) => term.text())).toStrictEqual(['Succeeded', 'Spend', 'Provider cost', 'Profit']);
        expect(summary.findAll('dd').map((value) => visibleText(value))).toStrictEqual(['3', '$0.0932', '$0.0921', '$0.0012']);
    });

    it('lists every day newest first, zero days included, with the total and the D-09 note', () => {
        const daily = mountShow().findAll('section')[0]!;

        expect(daily.get('caption').text()).toBe('Per day (by Pixelforge completion date, UTC)');
        expect(daily.findAll('tbody tr').map((row) => visibleText(row))).toStrictEqual([
            '2026-11-18 0 $0.0000 $0.0000 $0.0000',
            '2026-11-17 1 $0.0000 $0.0175 -$0.0175',
            '2026-11-16 2 $0.0932 $0.0746 $0.0186',
            '2026-11-15 0 $0.0000 $0.0000 $0.0000',
        ]);
        expect(visibleText(daily.get('tfoot'))).toBe('Total 3 $0.0932 $0.0921 $0.0012');
        expect(daily.get('p').text()).toBe(
            'Succeeded counts every generation Pixelforge billed, including any we failed locally and never charged.',
        );
    });

    it('lists the users in the order the server sent them', () => {
        const perUser = mountShow().findAll('section')[1]!;

        expect(perUser.get('caption').text()).toBe('Per user');
        expect(perUser.get('thead th').text()).toBe('User');
        expect(perUser.findAll('tbody tr').map((row) => visibleText(row))).toStrictEqual([
            'b@example.com 1 $0.0743 $0.0595 $0.0149',
            'a@example.com 2 $0.0189 $0.0326 -$0.0137',
        ]);
        expect(perUser.find('tfoot').exists()).toBe(false);
    });

    it('still lists every day with zeros, and says no user was billed, when nothing succeeded (AC 16)', () => {
        const wrapper = mountShow({
            days: WORKED_EXAMPLE.days.map((reportDay) => day(reportDay.date)),
            users: [],
            totals: ZERO,
        });
        const [daily, perUser] = wrapper.findAll('section');

        expect(daily!.findAll('tbody tr').map((row) => visibleText(row))).toStrictEqual([
            '2026-11-18 0 $0.0000 $0.0000 $0.0000',
            '2026-11-17 0 $0.0000 $0.0000 $0.0000',
            '2026-11-16 0 $0.0000 $0.0000 $0.0000',
            '2026-11-15 0 $0.0000 $0.0000 $0.0000',
        ]);
        expect(visibleText(perUser!.get('tbody'))).toBe('No billed generations in this range.');
    });
});
