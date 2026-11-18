import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        projects: [
            {
                extends: true,
                test: {
                    name: 'unit',
                    include: ['tests/js/unit/**/*.test.ts'],
                    environment: 'node',
                },
            },
            {
                extends: true,
                test: {
                    name: 'integration',
                    include: ['tests/js/integration/**/*.test.ts'],
                    environment: 'jsdom',
                },
            },
        ],
    },
});
