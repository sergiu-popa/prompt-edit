/**
 * Reference image rows on the Generate form. Pixelforge bills each reference it is
 * sent (765 input image tokens) and never downloads it, so only the URL's shape is
 * checked; an unreachable URL is not an error (requirements §6).
 *
 * PE-011's server rule must accept exactly the shapes `isHttpImageUrl` accepts:
 * after trimming, a URL the WHATWG parser accepts whose scheme is http or https.
 */

/** The non-blank rows, trimmed and in order: what is estimated and what PE-011 submits. */
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
