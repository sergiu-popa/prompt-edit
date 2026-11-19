/**
 * The one counting rule for prompts: code points, as PHP's mb_strlen and Pixelforge
 * count them. Never `text.length` (UTF-16 units, so 👍 would count 2) and never an
 * HTML `maxlength`, which counts the same units.
 */
export function countCodePoints(text: string): number {
    return [...text].length;
}

/** A count with en-US thousands grouping: `4,012`. */
export function formatCount(count: number): string {
    return new Intl.NumberFormat('en-US').format(count);
}
