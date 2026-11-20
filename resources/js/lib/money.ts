/**
 * The only place the UI formats money (D-25). Amounts arrive as integer
 * nanodollars (n$, 10⁻⁹ USD) and are formatted with bigint arithmetic only,
 * so no amount ever passes through a float.
 *
 * - `formatUsd` shows 4 decimals, rounded half away from zero. A non-zero
 *   amount under $0.00005 shows as `<$0.0001`, so a cost never reads as free.
 * - `formatUsdExact` shows all 9 decimals: the amount that is held and charged.
 * - `formatUsdRoundedUp` shows 4 decimals with the magnitude rounded up, so a
 *   shortfall we state is always enough and 1 n$ reads `$0.0001` (D-69).
 * - `formatBalance` shows a balance: a negative one rounds away from zero, so a
 *   debt never reads as `-$0.0000`; any other is `formatUsd`.
 *
 * - `formatUsdCents` shows a whole number of cents to 2 decimals, such as a credit
 *   pack's `$1.00`; anything finer throws.
 *
 * A negative amount keeps the ASCII hyphen-minus sign.
 */

const NANODOLLARS_PER_DOLLAR = 1_000_000_000n;

/** One displayed unit, $0.0001. */
const NANODOLLARS_PER_DISPLAY_UNIT = 100_000n;

const DISPLAY_UNITS_PER_DOLLAR = NANODOLLARS_PER_DOLLAR / NANODOLLARS_PER_DISPLAY_UNIT;

const HALF_DISPLAY_UNIT = NANODOLLARS_PER_DISPLAY_UNIT / 2n;

const NANODOLLARS_PER_CENT = 10_000_000n;

interface SignedAmount {
    sign: '' | '-';
    magnitude: bigint;
}

function signedAmount(nanodollars: number): SignedAmount {
    if (!Number.isSafeInteger(nanodollars)) {
        throw new RangeError(`An amount must be a safe integer of nanodollars, got ${String(nanodollars)}.`);
    }

    const amount = BigInt(nanodollars);

    return amount < 0n ? { sign: '-', magnitude: -amount } : { sign: '', magnitude: amount };
}

/** en-US thousands grouping of a whole number of dollars. */
function groupedDollars(dollars: bigint): string {
    return dollars.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function dollarsAndFraction(sign: string, whole: bigint, fraction: bigint, decimals: number): string {
    return `${sign}$${groupedDollars(whole)}.${fraction.toString().padStart(decimals, '0')}`;
}

/** `$0.3739` for 373,875,000 n$. */
export function formatUsd(nanodollars: number): string {
    const { sign, magnitude } = signedAmount(nanodollars);

    if (magnitude > 0n && magnitude < HALF_DISPLAY_UNIT) {
        return `${sign}<$0.0001`;
    }

    const displayUnits = (magnitude + HALF_DISPLAY_UNIT) / NANODOLLARS_PER_DISPLAY_UNIT;

    return dollarsAndFraction(sign, displayUnits / DISPLAY_UNITS_PER_DOLLAR, displayUnits % DISPLAY_UNITS_PER_DOLLAR, 4);
}

/** `$0.0764` for 76,312,500 n$, and `$0.0001` for 1 n$. */
export function formatUsdRoundedUp(nanodollars: number): string {
    const { sign, magnitude } = signedAmount(nanodollars);
    const displayUnits = (magnitude + NANODOLLARS_PER_DISPLAY_UNIT - 1n) / NANODOLLARS_PER_DISPLAY_UNIT;

    return dollarsAndFraction(sign, displayUnits / DISPLAY_UNITS_PER_DOLLAR, displayUnits % DISPLAY_UNITS_PER_DOLLAR, 4);
}

/** `$0.9137` for 913,687,500 n$, and `-$0.0010` for −1,000,000 n$. */
export function formatBalance(nanodollars: number): string {
    return nanodollars < 0 ? formatUsdRoundedUp(nanodollars) : formatUsd(nanodollars);
}

/** `$1.00` for 1,000,000,000 n$. */
export function formatUsdCents(nanodollars: number): string {
    const { sign, magnitude } = signedAmount(nanodollars);

    if (magnitude % NANODOLLARS_PER_CENT !== 0n) {
        throw new RangeError(`An amount shown in cents must be a whole number of cents, got ${String(nanodollars)} n$.`);
    }

    const cents = magnitude / NANODOLLARS_PER_CENT;

    return dollarsAndFraction(sign, cents / 100n, cents % 100n, 2);
}

/** `$0.373875000` for 373,875,000 n$. */
export function formatUsdExact(nanodollars: number): string {
    const { sign, magnitude } = signedAmount(nanodollars);

    return dollarsAndFraction(sign, magnitude / NANODOLLARS_PER_DOLLAR, magnitude % NANODOLLARS_PER_DOLLAR, 9);
}
