/**
 * What a request needs beyond the available balance, in integer nanodollars: 0 when the
 * balance covers it (D-69). The browser's check is advisory; the server decides under
 * the user-row lock (D-68). Mirrors CreditBalance::shortfallFor, held to the same
 * fixture (tests/fixtures/credit-shortfalls.json). Available may be negative.
 */
export function creditShortfall(required: number, available: number): number {
    if (!Number.isSafeInteger(required) || !Number.isSafeInteger(available)) {
        throw new RangeError(`Amounts must be safe integers of nanodollars, got ${String(required)} and ${String(available)}.`);
    }

    if (available >= required) {
        return 0;
    }

    const shortfall = BigInt(required) - BigInt(available);

    if (shortfall > BigInt(Number.MAX_SAFE_INTEGER)) {
        throw new RangeError(`A shortfall of ${shortfall.toString()} n$ is beyond a safe integer.`);
    }

    return Number(shortfall);
}
