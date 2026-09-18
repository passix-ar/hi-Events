module.exports = {
  env: { browser: true, es2020: true },
  extends: [
    'eslint:recommended',
    'plugin:@typescript-eslint/recommended',
    'plugin:react-hooks/recommended',
  ],
  parser: '@typescript-eslint/parser',
  parserOptions: { ecmaVersion: 'latest', sourceType: 'module' },
  plugins: ['react-refresh', 'lingui'],
  rules: {
    "lingui/no-unlocalized-strings": 2,
    "lingui/t-call-in-function": 2,
    "lingui/no-single-variables-to-translate": 2,
    "lingui/no-expression-in-message": 2,
    "lingui/no-single-tag-to-translate": 2,
    "lingui/no-trans-inside-trans": 2,
    'react-refresh/only-export-components': 'warn',
  },
  overrides: [
    {
      // Test names and fixtures are never user-facing, so the lingui rules have nothing to say
      // about them. Leaving them on would mean wrapping every `it(...)` description in `t`.
      files: ['**/*.test.ts', '**/*.test.tsx'],
      rules: {
        'lingui/no-unlocalized-strings': 0,
      },
    },
  ],
}
