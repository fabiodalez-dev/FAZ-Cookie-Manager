# Dev tooling — ESLint (JS) + Psalm taint analysis (PHP)

This branch (`chore/dev-tooling-eslint-psalm`) carries optional static-analysis
tooling for the plugin. It is **not meant to be merged** into a release branch:
it is a self-contained toolbox you check out when you want to run the analyses.
None of it ships — every artefact is excluded from the wp.org / GitHub ZIPs
(`.distignore`, `scripts/build-release.sh`) and the heavy parts (`node_modules/`,
`.security-tools/vendor/`) stay out of git.

## What's here

| File | Purpose |
|------|---------|
| `eslint.config.cjs` | ESLint flat config for the hand-written frontend JS (browser globals, bug-focused rules, no style enforcement — WPCS owns PHP style; the JS is intentionally left as-is). |
| `.security-tools/composer.json` + `psalm.xml` | Isolated Psalm 6 + `humanmade/psalm-plugin-wordpress` setup for the taint-analysis pass (data-flow XSS/SQLi detection). `vendor/` is not committed. |
| `package.json` scripts | `lint:js`, `taint:php`. |

## Why not Pint / Rector

- **Pint** applies Laravel/PSR-12, which conflicts with the WordPress Coding
  Standards this plugin must follow for wordpress.org. Use PHPCS + WPCS
  (`.phpcs-tools/`) instead.
- **Rector** with its default sets introduces PHP 8+ syntax; the plugin declares
  a **PHP 7.4** floor, so unconstrained Rector would break that contract.

## Setup (once per checkout)

```bash
# JS linter — ESLint is a dev dependency
npm install

# PHP taint analyser — install Psalm into the isolated toolbox
cd .security-tools && composer install && cd ..
```

## Run

```bash
# Lint the frontend JS (0 errors expected; warnings are unused-vars on
# intentionally-global helpers)
npm run lint:js

# Taint analysis: traces untrusted input ($_GET/$_POST/…) to dangerous sinks
# (echo → XSS, $wpdb->query → SQLi). "No errors found!" is the clean result.
npm run taint:php
```

## Baseline (last run)

- `lint:js`: **0 errors**, ~43 warnings (all `no-unused-vars` on globally
  exposed helpers + 2 cosmetic `return` in `Object.defineProperty` setters).
- `taint:php`: **0 taint findings** ("No errors found!"), ~82% of the codebase
  type-inferred. This is a positive signal, not an absolute guarantee — the pass
  is only as strong as the inferred types and the WP stubs. The primary security
  posture remains the in-code `esc_*` / `$wpdb->prepare()` / nonce discipline.

## Notes

- Keep `eslint.config.cjs` as `.cjs` (not `.js`): the project `package.json`
  has `"type": "module"`, so a `.js` config would be parsed as ESM and the
  `require()` calls would fail.
- The Psalm config (`psalm.xml`) is sensitive to attribute order and rejects
  some inline comments — start from `psalm --init` if you regenerate it. The
  WordPress plugin class is `PsalmWordPress\Plugin`.
