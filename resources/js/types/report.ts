/**
 * The operator report's props (ShowOperatorReportController). Every amount is integer
 * nanodollars (n$), never a float or a decimal string. A JS `number` holds an integer
 * exactly up to `Number.MAX_SAFE_INTEGER`, about 9.0 × 10^15 n$ or $9.0M, which is ample
 * for a report of at most 92 days.
 */

/** UTC dates (`YYYY-MM-DD`), both inclusive, named as `/v1/usage` names them. */
export interface ReportRange {
    start_date: string;
    end_date: string;
}

/** What the range form may choose: at most `max_days`, ending no later than `today` (UTC). */
export interface ReportLimits {
    max_days: number;
    today: string;
}

export interface ReportFigures {
    /** Generations Pixelforge billed, including any we failed locally and never charged (D-09). */
    succeeded: number;
    /** What users were charged: their `charge` ledger entries only. */
    spend_nanodollars: number;
    provider_cost_nanodollars: number;
    /** Spend − provider cost; negative on a day of D-09 costs. */
    profit_nanodollars: number;
}

/** One UTC day, by Pixelforge's completion date. */
export interface ReportDay extends ReportFigures {
    date: string;
}

export interface ReportUser extends ReportFigures {
    user_id: number;
    email: string;
}

export interface OperatorReport {
    /** Every date of the range, newest first, zeros included. */
    days: ReportDay[];
    /** Users with a billed generation in the range, by spend descending, then email. */
    users: ReportUser[];
    /** The sum of the days, which equals the sum of the users. */
    totals: ReportFigures;
}
