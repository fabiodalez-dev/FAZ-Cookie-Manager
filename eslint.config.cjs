// Dev-only ESLint flat config for the frontend JavaScript.
//
// Scope: the hand-written source files in frontend/js/ (the *.min.js builds are
// generated and excluded). These are browser scripts loaded as globals (no ES
// modules), so sourceType is "script" and the FAZ/runtime globals are declared
// below to keep no-undef meaningful instead of noisy.
//
// Purpose is BUG-finding, not style: eslint's "recommended" set catches
// no-undef, no-dupe-keys, no-unreachable, no-redeclare, no-constant-condition,
// etc. It does not enforce formatting (Prettier/Pint territory), which would
// fight the existing code. Not shipped — added to .distignore / build excludes.
//
// Run:  npx eslint -c eslint.config.js "frontend/js/**/*.js"

'use strict';

const js = require('@eslint/js');
const globals = require('globals');

module.exports = [
  {
    files: ['frontend/js/**/*.js'],
    ignores: ['frontend/js/**/*.min.js'],
    languageOptions: {
      ecmaVersion: 2021,
      sourceType: 'script',
      globals: {
        ...globals.browser,
        // Plugin runtime globals (set via wp_localize_script or by the scripts
        // themselves) and third-party tag globals the consent code talks to.
        FAZ: 'writable',
        fazcookie: 'writable',
        _fazConfig: 'writable',
        _fazCfg: 'writable',
        _fazGcm: 'writable',
        gtag: 'readonly',
        dataLayer: 'writable',
        __tcfapi: 'writable',
        jQuery: 'readonly',
        wp: 'readonly',
        clarity: 'readonly',
        uetq: 'writable',
        // Injected by wp_add_inline_script / wp_localize_script (PHP) and read
        // defensively with typeof guards by the scripts.
        _fazGsk: 'readonly',
        getFazConsent: 'readonly',
        wp_set_consent: 'readonly',
      },
    },
    rules: {
      ...js.configs.recommended.rules,
      // Functions/handlers are frequently exposed on globals or referenced from
      // HTML, so an "unused" local is usually intentional plumbing — downgrade
      // to a warning and ignore leading-underscore args by convention.
      'no-unused-vars': ['warn', { args: 'none', varsIgnorePattern: '^_' }],
      'no-empty': ['warn', { allowEmptyCatch: true }],
      // Style-only noise on this hand-tuned codebase, not bugs — silenced so the
      // report stays focused on real defects: redundant regex/string escapes are
      // harmless; hasOwnProperty is called on plain config objects; the
      // double-negation and the ignored `return true` inside
      // Object.defineProperty setters are cosmetic.
      'no-useless-escape': 'off',
      'no-prototype-builtins': 'off',
      'no-extra-boolean-cast': 'off',
      'no-setter-return': 'warn',
    },
  },
];
