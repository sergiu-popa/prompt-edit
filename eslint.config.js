import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';
import prettier from 'eslint-config-prettier/flat';
import vue from 'eslint-plugin-vue';

/**
 * D-25: only resources/js/lib/money.ts formats money. `toFixed` and currency-style
 * `Intl.NumberFormat` / `toLocaleString` anywhere else fail the lint, however the name
 * is spelled (`x.toFixed`, `x['toFixed']`, `` style: `currency` ``). The core rule sees
 * scripts and the Vue rule sees template expressions, so both get the selectors.
 */
const MONEY_FORMATTING_MESSAGE = 'Format money with resources/js/lib/money.ts (D-25), never with toFixed or currency-style Intl.';

/** A name written as an identifier, a string (`['toFixed']`, `'style'`) or a template literal. */
const named = (path, name) => `:matches([${path}.name='${name}'], [${path}.value='${name}'], [${path}.quasis.0.value.cooked='${name}'])`;

const moneyFormattingSelectors = [
    { selector: `MemberExpression${named('property', 'toFixed')}`, message: MONEY_FORMATTING_MESSAGE },
    { selector: `Property${named('key', 'style')}${named('value', 'currency')}`, message: MONEY_FORMATTING_MESSAGE },
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
