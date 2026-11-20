import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';

import RadioGroup from '@/components/ui/RadioGroup.vue';

type Quality = 'low' | 'medium' | 'high';

const qualities: { value: Quality; label: string; detail?: string }[] = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High', detail: 'Most detail' },
];

function mountGroup(modelValue: Quality = 'medium', error?: string) {
    const wrapper = mount(RadioGroup<Quality>, {
        props: {
            legend: 'Quality',
            options: qualities,
            modelValue,
            error,
            'onUpdate:modelValue': (value: Quality) => wrapper.setProps({ modelValue: value }),
        },
    });

    return wrapper;
}

describe('RadioGroup', () => {
    it('is a fieldset named by its visible legend', () => {
        const wrapper = mountGroup();

        expect(wrapper.element.tagName).toBe('FIELDSET');
        expect(wrapper.get('legend').text()).toBe('Quality');
    });

    it('labels one native radio per option, all sharing one name', () => {
        const wrapper = mountGroup();

        const radios = wrapper.findAll<HTMLInputElement>('input[type="radio"]');
        expect(radios.map((radio) => radio.element.closest('label')?.textContent?.trim())).toStrictEqual([
            'Low',
            'Medium',
            'HighMost detail',
        ]);
        expect(new Set(radios.map((radio) => radio.attributes('name'))).size).toBe(1);
        expect(radios[0]?.attributes('name')).toBeTruthy();
    });

    it("checks the model's option", () => {
        const wrapper = mountGroup('high');

        expect(wrapper.findAll<HTMLInputElement>('input[type="radio"]').map((radio) => radio.element.checked)).toStrictEqual([
            false,
            false,
            true,
        ]);
    });

    it('emits the chosen option and checks it', async () => {
        const wrapper = mountGroup();

        await wrapper.findAll('input[type="radio"]')[2]?.setValue(true);

        expect(wrapper.emitted('update:modelValue')).toStrictEqual([['high']]);
        expect(wrapper.findAll<HTMLInputElement>('input[type="radio"]')[2]?.element.checked).toBe(true);
    });

    it('gives two groups on a page different names', () => {
        const group = (legend: string) => h(RadioGroup<Quality>, { legend, options: qualities, modelValue: 'low' });
        const wrapper = mount(defineComponent({ render: () => [group('Quality'), group('Other')] }));

        const names = new Set(wrapper.findAll('input[type="radio"]').map((radio) => radio.attributes('name')));
        expect(names.size).toBe(2);
    });

    it('has no description or invalid radio without an error', () => {
        const wrapper = mountGroup();

        expect(wrapper.attributes('aria-describedby')).toBeUndefined();
        expect(wrapper.find('[aria-invalid]').exists()).toBe(false);
    });

    it('describes the group with its error and marks the checked radio invalid, so focus can land on it', () => {
        const wrapper = mountGroup('high', 'Choose a quality.');

        const describedBy = wrapper.attributes('aria-describedby');
        expect(describedBy).toBeTruthy();
        expect(wrapper.get(`[id="${describedBy}"]`).text()).toBe('Choose a quality.');
        expect(wrapper.findAll('input[type="radio"]').map((radio) => radio.attributes('aria-invalid'))).toStrictEqual([
            undefined,
            undefined,
            'true',
        ]);
    });
});
