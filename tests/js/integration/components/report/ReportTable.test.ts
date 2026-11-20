import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';

import ReportTable from '@/components/report/ReportTable.vue';
import type { ReportFigures, ReportTableRow } from '@/types/report';

import { visibleText } from '../../support/text';

/** The story's worked example (AC 11, 14). */
const SIXTEENTH: ReportFigures = {
    succeeded: 2,
    spend_nanodollars: 93_243_750,
    provider_cost_nanodollars: 74_595_000,
    profit_nanodollars: 18_648_750,
};
const SEVENTEENTH: ReportFigures = {
    succeeded: 1,
    spend_nanodollars: 0,
    provider_cost_nanodollars: 17_480_000,
    profit_nanodollars: -17_480_000,
};
const TOTALS: ReportFigures = {
    succeeded: 3,
    spend_nanodollars: 93_243_750,
    provider_cost_nanodollars: 92_075_000,
    profit_nanodollars: 1_168_750,
};

const ROWS: ReportTableRow[] = [
    { key: '2026-11-17', label: '2026-11-17', figures: SEVENTEENTH },
    { key: '2026-11-16', label: '2026-11-16', figures: SIXTEENTH },
];

let wrapper: VueWrapper | undefined;

function mountTable(props: Partial<InstanceType<typeof ReportTable>['$props']> = {}): VueWrapper {
    wrapper = mount(ReportTable, {
        props: { caption: 'Per day (by Pixelforge completion date, UTC)', labelHeader: 'Date', rows: ROWS, ...props },
        attachTo: document.body,
    });

    return wrapper;
}

function bodyRows(table: VueWrapper) {
    return table.findAll('tbody tr[data-row]');
}

afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
});

describe('ReportTable', () => {
    it('titles the table, naming it, and heads its columns', () => {
        const table = mountTable();

        expect(table.get('h2').text()).toBe('Per day (by Pixelforge completion date, UTC)');
        expect(table.get('table').attributes('aria-labelledby')).toBe(table.get('h2').attributes('id'));
        expect(table.findAll('thead th').map((header) => header.text())).toStrictEqual([
            'Date',
            'Succeeded',
            'Spend',
            'Provider cost',
            'Profit',
        ]);
    });

    it('shows a row per day, in the order given, with its count and amounts to 4 decimals', () => {
        const rows = bodyRows(mountTable());

        expect(rows.map((row) => visibleText(row))).toStrictEqual([
            '2026-11-17 1 $0.0000 $0.0175 -$0.0175',
            '2026-11-16 2 $0.0932 $0.0746 $0.0186',
        ]);
        expect(rows[1]!.get('th').attributes('scope')).toBe('row');
    });

    it('reveals the exact 9-decimal value on focus and on hover', async () => {
        const profit = bodyRows(mountTable())[1]!.get('[data-profit]');
        const tooltip = profit.get('[role="tooltip"]');

        expect(tooltip.isVisible()).toBe(false);

        await profit.get('button').trigger('focus');
        expect(tooltip.isVisible()).toBe(true);
        expect(tooltip.text()).toBe('$0.018648750');

        await profit.get('button').trigger('blur');
        expect(tooltip.isVisible()).toBe(false);

        await profit.get('span.relative').trigger('pointerenter', { pointerType: 'mouse' });
        expect(tooltip.isVisible()).toBe(true);
    });

    it('marks a negative amount beyond colour: a minus sign and medium weight', () => {
        const rows = bodyRows(mountTable());
        const loss = rows[0]!.get('[data-profit] [data-negative]');

        expect(loss.text()).toContain('-$0.0175');
        expect(loss.classes()).toContain('font-medium');
        expect(loss.classes()).toContain('text-red-700');
        expect(rows[0]!.get('[data-profit] [role="tooltip"]').text()).toBe('-$0.017480000');

        expect(rows[1]!.find('[data-profit] [data-negative]').exists()).toBe(false);
        expect(rows[0]!.find('[data-spend] [data-negative]').exists()).toBe(false);
        expect(rows[1]!.get('[data-profit] > span').classes()).not.toContain('font-medium');
    });

    it('sums the range in a Total footer', () => {
        const footer = mountTable({ totals: TOTALS }).get('tfoot tr');

        expect(visibleText(footer)).toBe('Total 3 $0.0932 $0.0921 $0.0012');
    });

    it('has no footer without totals', () => {
        expect(mountTable().find('tfoot').exists()).toBe(false);
    });

    it('shows its note under the table', () => {
        const table = mountTable({ note: 'Succeeded counts every generation Pixelforge billed.' });

        expect(table.get('p').text()).toBe('Succeeded counts every generation Pixelforge billed.');
    });

    it('shows the empty text in a single row when there are no rows', () => {
        const table = mountTable({ rows: [], emptyText: 'No billed generations in this range.' });

        expect(table.findAll('tbody tr')).toHaveLength(1);
        expect(table.get('tbody td').text()).toBe('No billed generations in this range.');
        expect(table.get('tbody td').attributes('colspan')).toBe('5');
    });

    it('truncates a long label but keeps it whole in its title', () => {
        const email = 'a-very-long-address-for-a-narrow-phone@example.com';
        const label = mountTable({ rows: [{ key: 1, label: email, figures: SIXTEENTH }] }).get('tbody th');

        expect(label.classes()).toContain('truncate');
        expect(label.attributes('title')).toBe(email);
    });
});
