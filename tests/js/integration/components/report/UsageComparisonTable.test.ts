import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';

import UsageComparisonTable from '@/components/report/UsageComparisonTable.vue';
import type { UsageComparison, UsageComparisonDay, UsageComparisonLine, UsageDelta, UsageFigures } from '@/types/usage';

import { visibleText } from '../../support/text';

const UNAVAILABLE_NOTICE =
    'Pixelforge usage is unavailable right now. Our recorded figures are shown; the comparison will return when Pixelforge responds.';

const MISMATCH_EXPLANATION =
    "Pixelforge counts every generation made with this API key. A mismatch usually means the key was used outside this app (for example a local test), generations were recorded before a database reset, or a generation finished at Pixelforge that we haven't recorded yet (it catches up within minutes). Our recorded figures come from each generation's own usage and rates.";

function figures(generations: number, cost: number, text: number, image: number, output: number, reasoning: number): UsageFigures {
    return {
        generations,
        provider_cost_nanodollars: cost,
        input_text_tokens: text,
        input_image_tokens: image,
        output_tokens: output,
        reasoning_tokens: reasoning,
    };
}

function delta(pixelforge: UsageFigures, recorded: UsageFigures): UsageDelta {
    return {
        provider_cost_nanodollars: pixelforge.provider_cost_nanodollars - recorded.provider_cost_nanodollars,
        input_text_tokens: pixelforge.input_text_tokens - recorded.input_text_tokens,
        input_image_tokens: pixelforge.input_image_tokens - recorded.input_image_tokens,
        output_tokens: pixelforge.output_tokens - recorded.output_tokens,
        reasoning_tokens: pixelforge.reasoning_tokens - recorded.reasoning_tokens,
    };
}

function line(recorded: UsageFigures, pixelforge: UsageFigures | null): UsageComparisonLine {
    if (pixelforge === null) {
        return { recorded, pixelforge: null, delta: null, matches: null };
    }

    const difference = delta(pixelforge, recorded);

    return { recorded, pixelforge, delta: difference, matches: Object.values(difference).every((value) => value === 0) };
}

const ZERO = figures(0, 0, 0, 0, 0, 0);
/** The PE-016 worked example: G1 + G2 on 11-16 and G4 on 11-17. */
const SIXTEENTH = figures(2, 74_595_000, 14, 0, 1_380, 1_546);
const SEVENTEENTH = figures(1, 17_480_000, 6, 0, 280, 500);
const TOTAL = figures(3, 92_075_000, 20, 0, 1_660, 2_046);

/** The range 2026-11-15 to 2026-11-18, newest first, today 2026-11-18, with Pixelforge's 11-17 as given. */
function comparison(seventeenth: UsageFigures | null = SEVENTEENTH, total: UsageFigures | null = TOTAL): UsageComparison {
    const available = seventeenth !== null;
    const days: UsageComparisonDay[] = [
        { date: '2026-11-18', in_progress: true, ...line(ZERO, available ? ZERO : null) },
        { date: '2026-11-17', in_progress: false, ...line(SEVENTEENTH, seventeenth) },
        { date: '2026-11-16', in_progress: false, ...line(SIXTEENTH, available ? SIXTEENTH : null) },
        { date: '2026-11-15', in_progress: false, ...line(ZERO, available ? ZERO : null) },
    ];
    const totalLine = line(TOTAL, total);

    return {
        availability: available ? 'available' : 'unavailable',
        has_mismatch: [...days, totalLine].some((day) => day.matches === false),
        days,
        total: totalLine,
    };
}

let wrapper: VueWrapper | undefined;

function mountTable(usage: UsageComparison = comparison()): VueWrapper {
    wrapper = mount(UsageComparisonTable, { props: { usage }, attachTo: document.body });

    return wrapper;
}

function days(table: VueWrapper) {
    return table.findAll('tbody tr[data-usage-day]');
}

afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
});

describe('UsageComparisonTable', () => {
    it('titles the table, naming it, and groups its columns', () => {
        const table = mountTable();

        expect(table.get('h2').text()).toBe('Pixelforge reconciliation (per UTC day)');
        expect(table.get('table').attributes('aria-labelledby')).toBe(table.get('h2').attributes('id'));
        expect(table.findAll('thead th[scope="colgroup"]').map((header) => header.text())).toStrictEqual([
            'Generations',
            'Provider cost',
            'Tokens (text / image / output / reasoning)',
        ]);
        expect(table.findAll('thead tr:nth-child(2) th').map((header) => header.text())).toStrictEqual([
            'Pixelforge says',
            'We recorded',
            'Pixelforge says',
            'We recorded',
            'Δ',
            'Pixelforge says',
            'We recorded',
            'Δ',
        ]);
    });

    it('shows Match on every day and on the total when everything is equal (AC 6)', () => {
        const table = mountTable();

        expect(days(table).map((day) => day.get('[data-status]').text())).toStrictEqual(['Match', 'Match', 'Match', 'Match']);
        expect(table.get('tfoot [data-status]').text()).toBe('Match');
        expect(visibleText(days(table)[2]!)).toBe(
            '2026-11-16 2 2 $0.0746 $0.0746 0 14 / 0 / 1,380 / 1,546 14 / 0 / 1,380 / 1,546 0 / 0 / 0 / 0 Match',
        );
        expect(visibleText(table.get('tfoot tr'))).toBe(
            'Total 3 3 $0.0921 $0.0921 0 20 / 0 / 1,660 / 2,046 20 / 0 / 1,660 / 2,046 0 / 0 / 0 / 0 Match',
        );
        expect(table.find('[data-nonzero]').exists()).toBe(false);
        expect(table.find('[data-mismatch-explanation]').exists()).toBe(false);
        expect(table.find('[data-usage-notice]').exists()).toBe(false);
    });

    it('marks a one-nanodollar delta and shows its exact value (AC 7)', async () => {
        const pixelforge = { ...SEVENTEENTH, provider_cost_nanodollars: 17_480_001 };
        const table = mountTable(comparison(pixelforge, { ...TOTAL, provider_cost_nanodollars: 92_075_001 }));
        const costDelta = days(table)[1]!.get('[data-cost-delta]');

        expect(visibleText(costDelta)).toBe('+<$0.0001');
        expect(costDelta.find('[data-nonzero]').exists()).toBe(true);
        expect(costDelta.get('[data-nonzero]').classes()).toEqual(expect.arrayContaining(['font-medium', 'text-red-700']));

        await costDelta.get('button').trigger('focus');
        expect(costDelta.get('[role="tooltip"]').text()).toBe('$0.000000001');

        expect(days(table)[1]!.get('[data-status]').text()).toBe('Mismatch');
        expect(days(table)[2]!.get('[data-status]').text()).toBe('Match');
        expect(table.get('tfoot [data-status]').text()).toBe('Mismatch');
        expect(visibleText(table.get('[data-mismatch-explanation]'))).toBe(MISMATCH_EXPLANATION);
    });

    it('shows a generation made elsewhere as its cost and token deltas, with the explanation (AC 8, 12)', async () => {
        const table = mountTable(comparison(figures(2, 32_605_000, 11, 0, 560, 812), figures(4, 107_200_000, 25, 0, 1_940, 2_358)));
        const day = days(table)[1]!;

        expect(visibleText(day.get('[data-cost-delta]'))).toBe('+$0.0151');
        await day.get('[data-cost-delta] button').trigger('focus');
        expect(day.get('[data-cost-delta] [role="tooltip"]').text()).toBe('$0.015125000');

        expect(visibleText(day.get('[data-tokens-delta]'))).toBe('+5 / 0 / +280 / +312');
        expect(day.findAll('[data-tokens-delta] [data-nonzero]').map((part) => part.text())).toStrictEqual(['+5', '+280', '+312']);
        expect(visibleText(day.get('[data-pixelforge-tokens]'))).toBe('11 / 0 / 560 / 812');
        expect(visibleText(day.get('[data-recorded-tokens]'))).toBe('6 / 0 / 280 / 500');
        expect(day.get('[data-status]').text()).toBe('Mismatch');
        expect(visibleText(table.get('[data-mismatch-explanation]'))).toBe(MISMATCH_EXPLANATION);
    });

    it('keeps a negative delta its own minus sign', () => {
        const table = mountTable(comparison(figures(1, 17_480_000, 6, 0, 279, 500), figures(3, 92_075_000, 20, 0, 1_659, 2_046)));

        expect(visibleText(days(table)[1]!.get('[data-tokens-delta]'))).toBe('0 / 0 / -1 / 0');
    });

    it('shows "—" for Pixelforge, the deltas and the status while it is unavailable (AC 14)', () => {
        const table = mountTable(comparison(null, null));
        const day = days(table)[2]!;

        for (const cell of [
            '[data-pixelforge-generations]',
            '[data-pixelforge-cost]',
            '[data-cost-delta]',
            '[data-pixelforge-tokens]',
            '[data-tokens-delta]',
            '[data-status]',
        ]) {
            expect(day.get(cell).text()).toBe('—');
            expect(table.get(`tfoot ${cell}`).text()).toBe('—');
        }

        expect(visibleText(day.get('[data-recorded-cost]'))).toBe('$0.0746');
        expect(visibleText(day.get('[data-recorded-tokens]'))).toBe('14 / 0 / 1,380 / 1,546');
        expect(table.find('[data-status] [data-tone]').exists()).toBe(false);
        expect(table.get('[data-usage-notice]').text()).toBe(UNAVAILABLE_NOTICE);
        expect(table.get('[data-usage-notice]').attributes('role')).toBe('status');
        expect(table.find('[data-mismatch-explanation]').exists()).toBe(false);
    });

    it('shows its own notice when Pixelforge refused the range', () => {
        const table = mountTable({ ...comparison(null, null), availability: 'range_too_long' });

        expect(table.get('[data-usage-notice]').text()).toBe(
            "Pixelforge refused this range as too long, so it isn't compared. Our recorded figures are shown.",
        );
    });

    it('labels only today In progress, with its note (AC 9)', () => {
        const table = mountTable();

        expect(days(table).map((day) => day.attributes('data-in-progress') !== undefined)).toStrictEqual([true, false, false, false]);
        expect(days(table)[0]!.get('th').text()).toContain('In progress');
        expect(days(table)[1]!.get('th').text()).toBe('2026-11-17');
        expect(table.get('[data-in-progress-note]').text()).toBe(
            'Today (UTC) is in progress: generations still processing may complete later today.',
        );
    });

    it('has no In progress note when today is outside the range', () => {
        const usage = comparison();
        const table = mountTable({ ...usage, days: usage.days.map((day) => ({ ...day, in_progress: false })) });

        expect(table.find('[data-in-progress-note]').exists()).toBe(false);
    });

    it('shows both counts without a delta, and says they are never compared', () => {
        const usage = comparison();
        const table = mountTable({
            ...usage,
            days: usage.days.map((day) => (day.date === '2026-11-16' ? { ...day, pixelforge: { ...SIXTEENTH, generations: 5 } } : day)),
        });
        const day = days(table)[2]!;

        expect(day.get('[data-pixelforge-generations]').text()).toBe('5');
        expect(day.get('[data-recorded-generations]').text()).toBe('2');
        expect(day.get('[data-status]').text()).toBe('Match');
        expect(table.text()).toContain(
            "Generation counts are shown but never compared: Pixelforge doesn't say whether it counts failed generations.",
        );
    });
});
