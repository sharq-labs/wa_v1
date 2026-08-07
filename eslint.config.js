import tsParser from '@typescript-eslint/parser';
import tsPlugin from '@typescript-eslint/eslint-plugin';
import reactHooks from 'eslint-plugin-react-hooks';

export default [
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        languageOptions: {
            parser: tsParser,
            parserOptions: {
                ecmaVersion: 'latest',
                sourceType: 'module',
                ecmaFeatures: { jsx: true },
            },
        },
        plugins: {
            '@typescript-eslint': tsPlugin,
            'react-hooks': reactHooks,
        },
        rules: {
            ...tsPlugin.configs.recommended.rules,
            ...reactHooks.configs.recommended.rules,
            '@typescript-eslint/no-explicit-any': 'off',
            '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],

            // These React Compiler-oriented rules flag several intentional UI
            // patterns in the existing builder/inbox (URL state syncing,
            // registry-provided Lucide icons and refs used as stable action
            // bridges). Keep them visible in CI without blocking releases;
            // typecheck, tests and the production build remain mandatory.
            'react-hooks/set-state-in-effect': 'warn',
            'react-hooks/refs': 'warn',
            'react-hooks/static-components': 'warn',
        },
    },
    {
        ignores: ['vendor/**', 'public/**', 'node_modules/**', 'storage/**', 'bootstrap/**'],
    },
];
