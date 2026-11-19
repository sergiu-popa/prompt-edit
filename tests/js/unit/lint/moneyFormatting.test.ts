import { ESLint } from 'eslint';
import { fileURLToPath, URL } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * D-24: only resources/js/lib/money.ts formats money. These run the project's real
 * ESLint config, so the rule is proven where `make js-check` applies it.
 */
const eslint = new ESLint({ cwd: fileURLToPath(new URL('../../../..', import.meta.url)) });

const MESSAGE = 'Format money with resources/js/lib/money.ts';

/**
 * The TypeScript project service only parses files tsconfig.json includes, so `.ts` code is
 * linted as the text of a file that exists (its contents on disk are not read).
 */
const TS_FILE = 'resources/js/lib/pageTitle.ts';

/** Loading the TypeScript and Vue parsers the first time is slow. */
const TIMEOUT_MS = 30_000;

async function moneyFormattingErrors(code: string, filePath: string): Promise<string[]> {
    const [result] = await eslint.lintText(code, { filePath });
    const messages = result?.messages ?? [];
    const fatal = messages.find((message) => message.fatal);

    if (fatal) {
        throw new Error(`ESLint could not parse ${filePath}: ${fatal.message}`);
    }

    return messages.map((message) => message.message).filter((message) => message.startsWith(MESSAGE));
}

function vueFile(script: string, template = '<p />'): string {
    return `<script setup lang="ts">\n${script}\n</script>\n\n<template>\n    ${template}\n</template>\n`;
}

describe('the money-formatting lint rule', () => {
    it(
        'rejects toFixed in a TypeScript file',
        async () => {
            const errors = await moneyFormattingErrors('export const price = (1.5).toFixed(2);\n', TS_FILE);

            expect(errors).toHaveLength(1);
        },
        TIMEOUT_MS,
    );

    it(
        'rejects toFixed in a Vue script',
        async () => {
            const errors = await moneyFormattingErrors(
                vueFile('const price = (1.5).toFixed(2);', '<p>{{ price }}</p>'),
                'resources/js/components/Price.vue',
            );

            expect(errors).toHaveLength(1);
        },
        TIMEOUT_MS,
    );

    it(
        'rejects toFixed in a Vue template expression',
        async () => {
            const errors = await moneyFormattingErrors(
                vueFile('defineProps<{ amount: number }>();', '<p>{{ amount.toFixed(4) }}</p>'),
                'resources/js/components/Price.vue',
            );

            expect(errors).toHaveLength(1);
        },
        TIMEOUT_MS,
    );

    it(
        'rejects currency formatting through Intl.NumberFormat',
        async () => {
            const errors = await moneyFormattingErrors(
                "export const usd = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });\n",
                TS_FILE,
            );

            expect(errors).toHaveLength(1);
        },
        TIMEOUT_MS,
    );

    it(
        'rejects currency formatting through toLocaleString',
        async () => {
            const errors = await moneyFormattingErrors(
                "export const price = (1.5).toLocaleString('en-US', { 'style': 'currency', currency: 'USD' });\n",
                TS_FILE,
            );

            expect(errors).toHaveLength(1);
        },
        TIMEOUT_MS,
    );

    it(
        'rejects toFixed reached through a computed property',
        async () => {
            const errors = await moneyFormattingErrors(
                "export const a = (1.5)['toFixed'](2);\nexport const b = (1.5)[`toFixed`](2);\n",
                TS_FILE,
            );

            expect(errors).toHaveLength(2);
        },
        TIMEOUT_MS,
    );

    it(
        'rejects a currency style written as a template literal',
        async () => {
            const errors = await moneyFormattingErrors(
                "export const usd = new Intl.NumberFormat('en-US', { style: `currency`, currency: 'USD' });\n",
                TS_FILE,
            );

            expect(errors).toHaveLength(1);
        },
        TIMEOUT_MS,
    );

    it(
        'allows the same code in resources/js/lib/money.ts',
        async () => {
            const errors = await moneyFormattingErrors(
                "export const a = (1.5).toFixed(2);\nexport const b = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });\n",
                'resources/js/lib/money.ts',
            );

            expect(errors).toStrictEqual([]);
        },
        TIMEOUT_MS,
    );

    it(
        'allows plain number formatting',
        async () => {
            const errors = await moneyFormattingErrors(
                "export const count = new Intl.NumberFormat('en-US', { style: 'decimal' }).format(4012);\n",
                TS_FILE,
            );

            expect(errors).toStrictEqual([]);
        },
        TIMEOUT_MS,
    );
});
