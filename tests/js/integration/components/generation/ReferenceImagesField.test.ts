import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';

import { descriptionOf } from '../../support/form';
import { visibleText } from '../../support/text';

import ReferenceImagesField from '@/components/generation/ReferenceImagesField.vue';

const HINT = 'Enter an http:// or https:// image URL.';

let wrapper: VueWrapper | undefined;

function mountField(modelValue: string[] = [], perReferenceNanodollars: number | null = 9_562_500): VueWrapper {
    const field = mount(ReferenceImagesField, {
        props: {
            modelValue,
            max: 4,
            perReferenceNanodollars,
            'onUpdate:modelValue': (value: string[] | undefined) => field.setProps({ modelValue: value }),
        },
        attachTo: document.body,
    });
    wrapper = field;

    return field;
}

function rowInputs(field: VueWrapper) {
    return field.findAll<HTMLInputElement>('input');
}

function button(field: VueWrapper, name: string) {
    const match = field.findAll('button').find((candidate) => (candidate.attributes('aria-label') ?? candidate.text()) === name);

    if (!match) {
        throw new Error(`No "${name}" button.`);
    }

    return match;
}

afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
});

describe('ReferenceImagesField', () => {
    it('is an optional group with no rows to start with', () => {
        const field = mountField();

        expect(field.get('legend').text()).toBe('Reference images (optional), 0 of 4');
        expect(rowInputs(field)).toHaveLength(0);
        expect(visibleText(field)).toContain('0 of 4');
    });

    it('names the count in the legend, and hides the visible copy outside the group from assistive technology', async () => {
        const field = mountField();

        const visibleCount = field.findAll('p').find((paragraph) => paragraph.text() === '0 of 4');
        expect(visibleCount?.attributes('aria-hidden')).toBe('true');
        expect(visibleCount?.element.closest('fieldset')).toBeNull();

        await button(field, 'Add reference image').trigger('click');

        expect(field.get('legend').text()).toBe('Reference images (optional), 1 of 4');
    });

    it('adds a labelled row and focuses it', async () => {
        const field = mountField();

        await button(field, 'Add reference image').trigger('click');
        await nextTick();

        const [input] = rowInputs(field);
        expect(input).toBeDefined();
        expect(document.activeElement).toBe(input?.element);
        expect(field.get(`label[for="${input?.element.id}"]`).text()).toBe('Reference image 1');
        expect(field.emitted('update:modelValue')).toStrictEqual([[['']]]);
        expect(visibleText(field)).toContain('1 of 4');
    });

    it('updates the row as the user types', async () => {
        const field = mountField(['']);

        await rowInputs(field)[0]?.setValue('https://example.com/style.png');

        expect(field.emitted('update:modelValue')?.at(-1)).toStrictEqual([['https://example.com/style.png']]);
    });

    it('labels each remove control by its row and removes that row', async () => {
        const field = mountField(['https://a.test/a.png', 'https://b.test/b.png']);

        expect(button(field, 'Remove reference image 1').exists()).toBe(true);

        await button(field, 'Remove reference image 2').trigger('click');

        expect(field.emitted('update:modelValue')?.at(-1)).toStrictEqual([['https://a.test/a.png']]);
        expect(rowInputs(field).map((input) => input.element.value)).toStrictEqual(['https://a.test/a.png']);
    });

    it('moves focus to Add reference image after a row is removed', async () => {
        const field = mountField(['https://a.test/a.png']);

        await button(field, 'Remove reference image 1').trigger('click');
        await nextTick();

        expect(document.activeElement).toBe(button(field, 'Add reference image').element);
    });

    it('shows what each reference adds while below the cap', () => {
        const field = mountField(['https://a.test/a.png']);

        const each = field.findAll('p').find((paragraph) => visibleText(paragraph).endsWith('each'));
        expect(each && visibleText(each)).toBe('+$0.0096 each');
        expect(each?.get('[role="tooltip"]').text()).toBe('$0.009562500');
    });

    it('stops at 4 rows with a hint', () => {
        const field = mountField(['a', 'b', 'c', 'd']);

        expect(button(field, 'Add reference image').attributes('disabled')).toBeDefined();
        expect(visibleText(field)).toContain('Up to 4 reference images.');
        expect(visibleText(field)).toContain('4 of 4');
        expect(visibleText(field)).not.toContain('each');
    });

    it('asks for an http(s) URL once a row that is not one loses focus', async () => {
        const field = mountField(['']);
        const input = rowInputs(field)[0]!;

        await input.trigger('focus');
        await input.setValue('example.com/cat.png');
        expect(visibleText(field)).not.toContain(HINT);

        await input.trigger('blur');

        expect(input.attributes('aria-invalid')).toBe('true');
        expect(descriptionOf(field, input.element)).toBe(HINT);
    });

    it('never flashes the hint while https:// is being typed', async () => {
        const field = mountField(['']);
        const input = rowInputs(field)[0]!;

        await input.trigger('focus');
        await input.setValue('https://');

        expect(visibleText(field)).not.toContain(HINT);
        expect(input.attributes('aria-invalid')).toBeUndefined();
    });

    it.each(['Add reference image', 'Remove reference image 1'])(
        'keeps focus in the row when %s is pressed, so a hint appearing on blur cannot move the button from under the click',
        (name) => {
            const field = mountField(['example.com/cat.png']);
            const mousedown = new MouseEvent('mousedown', { bubbles: true, cancelable: true });

            button(field, name).element.dispatchEvent(mousedown);

            expect(mousedown.defaultPrevented).toBe(true);
        },
    );

    it.each([[''], ['   '], ['https://example.com/style.png']])('shows no hint for %j', (value) => {
        const field = mountField([value]);

        expect(visibleText(field)).not.toContain(HINT);
        expect(rowInputs(field)[0]?.attributes('aria-invalid')).toBeUndefined();
    });
});
