import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';
import prettier from 'eslint-config-prettier/flat';
import vue from 'eslint-plugin-vue';

export default defineConfigWithVueTs(
    {
        ignores: ['vendor/**', 'node_modules/**', 'public/**', 'storage/**', 'bootstrap/**'],
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
    prettier,
);
