// https://docs.expo.dev/guides/using-eslint/
const { defineConfig } = require('eslint/config');
const expoConfig = require('eslint-config-expo/flat');

module.exports = defineConfig([
  expoConfig,
  {
    ignores: ['dist/*'],
  },
  // Template `app/` + `ExternalLink` use `expo-router`; this project’s runtime entry is `App.js` (no expo-router package).
  {
    files: ['app/**/*.{js,jsx,ts,tsx}', 'components/external-link.tsx'],
    rules: {
      'import/no-unresolved': 'off',
    },
  },
]);
