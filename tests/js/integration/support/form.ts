import type { VueWrapper } from '@vue/test-utils';

/**
 * The input a visible label points at, found the way a screen reader finds it.
 */
export function inputLabelled(wrapper: VueWrapper, label: string): HTMLInputElement {
    const id = wrapper
        .findAll('label')
        .find((candidate) => candidate.text() === label)
        ?.attributes('for');

    return wrapper.get<HTMLInputElement>(`input[id="${id}"]`).element;
}

export async function fillIn(wrapper: VueWrapper, label: string, value: string): Promise<void> {
    await wrapper.get(`input[id="${inputLabelled(wrapper, label).id}"]`).setValue(value);
}

/**
 * The text of everything an input's `aria-describedby` points at.
 */
export function descriptionOf(wrapper: VueWrapper, input: HTMLInputElement): string {
    return (input.getAttribute('aria-describedby') ?? '')
        .split(' ')
        .filter((id) => id !== '')
        .map((id) => wrapper.get(`[id="${id}"]`).text())
        .join(' ');
}
