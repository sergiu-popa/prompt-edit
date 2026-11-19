import { nextTick, useTemplateRef } from 'vue';

/**
 * After the server rejects a form, focus its first invalid field, so a keyboard or screen
 * reader user lands on it and hears its message (`aria-describedby`). Without this, focus
 * stays on the submit button that went disabled while busy, which drops it to the page.
 *
 * `formRef` is the template ref of the `<form>`; call the returned function from `onError`.
 */
export function useFocusFirstInvalidField(formRef: string): () => Promise<void> {
    const form = useTemplateRef<HTMLFormElement>(formRef);

    return async function focusFirstInvalidField(): Promise<void> {
        await nextTick();
        form.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
    };
}
