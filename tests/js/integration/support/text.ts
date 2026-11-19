const BLOCK_TAGS = new Set([
    'DIV',
    'P',
    'LI',
    'UL',
    'OL',
    'H1',
    'H2',
    'H3',
    'TABLE',
    'TR',
    'TD',
    'TH',
    'SECTION',
    'ASIDE',
    'FIELDSET',
    'LEGEND',
]);

/**
 * The text a sighted user reads: like `textContent`, but skipping `display: none`
 * elements such as a closed MoneyAmount tip, and separating blocks (including
 * Tailwind's `block` class) with a space. jsdom has no `innerText`.
 */
export function visibleText(wrapper: { element: Node }): string {
    const parts: string[] = [];

    function walk(node: Node): void {
        if (node.nodeType === Node.TEXT_NODE) {
            parts.push(node.textContent ?? '');

            return;
        }

        if (!(node instanceof HTMLElement)) {
            node.childNodes.forEach(walk);

            return;
        }

        if (node.style.display === 'none') {
            return;
        }

        const isBlock = BLOCK_TAGS.has(node.tagName) || node.classList.contains('block');

        parts.push(isBlock ? ' ' : '');
        node.childNodes.forEach(walk);
        parts.push(isBlock ? ' ' : '');
    }

    walk(wrapper.element);

    return parts.join('').replace(/\s+/g, ' ').trim();
}
