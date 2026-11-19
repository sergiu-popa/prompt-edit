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
