/**
 * Reference image rows on the Generate form. Pixelforge bills each reference it is
 * sent (765 input image tokens) and never downloads it, so only the URL's shape is
 * checked; an unreachable URL is not an error (requirements §6).
 *
 * The server is the authority: CreateGenerationRequest applies `url:http,https` and a
 * 2,048-character bound to each trimmed URL. `isHttpImageUrl` is the same check for the
 * button: after trimming, a URL the WHATWG parser accepts whose scheme is http or https.
 * A URL only the parser accepts (a raw space in its path) gets that row's server error,
 * never a different price.
 */

/** The non-blank rows, trimmed and in order: what is estimated and what is submitted. */
export function countedReferenceImages(rows: readonly string[]): string[] {
    return rows.map((row) => row.trim()).filter((row) => row !== '');
}

export function isHttpImageUrl(value: string): boolean {
    try {
        const url = new URL(value.trim());

        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
}

/**
 * The row index of each counted row, captured when the form is submitted, so a server
 * error on `reference_images.N` can be shown on the row it came from.
 */
export function submittedRowIndexes(rows: readonly string[]): number[] {
    return rows.flatMap((row, index) => (row.trim() === '' ? [] : [index]));
}

/** The server's `reference_images.N` errors, keyed by the row each submitted reference came from. */
export function referenceRowErrors(
    indexes: readonly number[],
    errors: Readonly<Record<string, string | undefined>>,
): Record<number, string> {
    const rowErrors: Record<number, string> = {};

    indexes.forEach((rowIndex, submittedIndex) => {
        const message = errors[`reference_images.${submittedIndex}`];

        if (message !== undefined) {
            rowErrors[rowIndex] = message;
        }
    });

    return rowErrors;
}
