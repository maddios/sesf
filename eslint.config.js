import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import pluginVue from 'eslint-plugin-vue';

export default tseslint.config(
    {
        ignores: ['node_modules/**', 'public/build/**', 'vendor/**', 'storage/**', 'coverage/**', 'playwright-report/**', 'test-results/**'],
    },
    js.configs.recommended,
    tseslint.configs.recommended,
    pluginVue.configs['flat/recommended'],
    {
        files: ['**/*.vue'],
        languageOptions: {
            parserOptions: {
                parser: tseslint.parser,
                extraFileExtensions: ['.vue'],
            },
        },
    },
);
