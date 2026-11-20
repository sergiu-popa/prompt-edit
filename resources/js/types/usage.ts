/**
 * The report's reconciliation with Pixelforge's `/v1/usage` (the `usage` prop of
 * ShowOperatorReportController, D-72). Every amount is integer nanodollars (n$) and every
 * count an integer, never a float or a decimal string.
 */

/** Whether Pixelforge's figures are there: `unavailable` and `range_too_long` show ours alone. */
export type UsageAvailability = 'available' | 'unavailable' | 'range_too_long';

/** One side of a line: what Pixelforge billed, or what we recorded. */
export interface UsageFigures {
    /** Shown but never compared: the docs don't say whether Pixelforge counts failures. */
    generations: number;
    provider_cost_nanodollars: number;
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
}

/** Pixelforge − ours, per compared figure; a positive value means Pixelforge says more. */
export interface UsageDelta {
    provider_cost_nanodollars: number;
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
}

/** A day or the total. Without Pixelforge's figures, the last three are null. */
export interface UsageComparisonLine {
    recorded: UsageFigures;
    pixelforge: UsageFigures | null;
    delta: UsageDelta | null;
    /** True only when all five compared figures are exactly equal. */
    matches: boolean | null;
}

/** One UTC day; today's is in progress, because its figures can still move. */
export interface UsageComparisonDay extends UsageComparisonLine {
    date: string;
    in_progress: boolean;
}

export interface UsageComparison {
    availability: UsageAvailability;
    /** Whether any day or the total is a Mismatch. */
    has_mismatch: boolean;
    /** Every date of the range, newest first. */
    days: UsageComparisonDay[];
    total: UsageComparisonLine;
}
