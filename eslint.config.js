import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';
import prettier from 'eslint-config-prettier/flat';
import vue from 'eslint-plugin-vue';

/**
 * D-24: only resources/js/lib/money.ts formats money. `toFixed` and currency-style
 * `Intl.NumberFormat` / `toLocaleString` anywhere else fail the lint. The core rule
 * sees scripts and the Vue rule sees template expressions, so both get the selectors.
 */
const MONEY_FORMATTING_MESSAGE = 'Format money with resources/js/lib/money.ts (D-24), never with toFixed or currency-style Intl.';

const moneyFormattingSelectors = [
    { selector: "MemberExpression[property.name='toFixed']", message: MONEY_FORMATTING_MESSAGE },
    { selector: "Property[key.name='style'][value.value='currency']", message: MONEY_FORMATTING_MESSAGE },
    { selector: "Property[key.value='style'][value.value='currency']", message: MONEY_FORMATTING_MESSAGE },
];

export default defineConfigWithVueTs(
    {
        ignores: ['vendor/**', 'node_modules/**', 'public/**', 'storage/**', 'bootstrap/**', 'playwright-report/**', 'test-results/**'],
    },
    vue.configs['flat/recommended'],
    vueTsConfigs.recommended,
    {
        rules: {
            '@typescript-eslint/consistent-type-imports': 'error',
            '@typescript-eslint/no-explicit-any': 'error',
            'vue/multi-word-component-names': 'off',
        },
    },
    {
        files: ['resources/js/**/*.{ts,vue}'],
        ignores: ['resources/js/lib/money.ts'],
        rules: {
            'no-restricted-syntax': ['error', ...moneyFormattingSelectors],
            'vue/no-restricted-syntax': ['error', ...moneyFormattingSelectors],
        },
    },
    prettier,
);
