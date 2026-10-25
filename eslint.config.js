import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import pluginVue from 'eslint-plugin-vue';

export default tseslint.config(
    {
        ignores: ['node_modules/**', 'public/build/**', 'vendor/**', 'storage/**', 'coverage/**', 'playwright-report/**', 'test-results/**', 'docs/**'],
    },
    js.configs.recommended,
    tseslint.configs.recommended,
    pluginVue.configs['flat/recommended'],
    {
        // Config files run in Node and read process.env.
        files: ['vite.config.js'],
        languageOptions: {
            globals: { process: 'readonly' },
        },
    },
    {
        files: ['**/*.vue'],
        languageOptions: {
            parserOptions: {
                parser: tseslint.parser,
                extraFileExtensions: ['.vue'],
            },
        },
        rules: {
            // TypeScript (vue-tsc) already checks undefined names; core no-undef does not know DOM types like
            // HTMLElement in <script lang="ts">. typescript-eslint recommends turning it off for TS code.
            'no-undef': 'off',
        },
    },
);
