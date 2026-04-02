# Native Accessibility Implementation — faz-cookie-manager

**Date:** 2026-04-02
**Status:** Approved

## Background

We previously patched CookieYes (closed source) with an npm package (`a11y-cookie-yes`) that fixed focus trapping, ARIA attributes, and semantic markup in JavaScript at runtime. We now own an open-source fork of that plugin (`faz-cookie-manager`) and want to implement those same improvements natively — removing the npm layer entirely.

## Goal

Implement all accessibility improvements (WCAG 2.1 AA) directly inside the plugin in a clean, maintainable, and future-proof way. Prefer server-rendered fixes over JS DOM mutation where possible.

---

## Selector Map: Old (CookieYes) → New (faz-cookie-manager)

| Old selector | New selector | Notes |
|---|---|---|
| `.cky-consent-container` | `.faz-consent-container` | Banner wrapper |
| `.cky-hide` | `.faz-hide` | Hidden state class |
| `.cky-modal` | `.faz-modal` | Modal wrapper |
| `.cky-modal-open` | `faz-modal-open` (class toggle) | Modal open state |
| `.cky-preference-center` | `.faz-preference-center` | Inner modal dialog |
| `.cky-title` | `.faz-title` | Banner title (was `<h2>`, now `<p role="heading">`) |
| `.cky-preference-title` (modal) | `.faz-preference-title` | Modal title (was `<h2>`, now `<span role="heading">`) |
| `.cky-accordion` | `.faz-accordion` | Category accordion wrapper |
| `.cky-accordion-active` | n/a — class removed | Accordion open state tracked differently |
| `.cky-accordion-btn` | `.faz-accordion-btn` | Accordion toggle button |
| `.cky-accordion-body` | `.faz-accordion-body` | Accordion body |
| `.cky-btn-close` | `.faz-btn-close` | Modal close button |
| `.cky-banner-btn-close` | `.faz-banner-btn-close` | Banner close button (new in fork) |
| `.cky-switch input[type="checkbox"]` | `.faz-switch input[type="checkbox"]` | Category toggles |
| `.cky-preference-content-wrapper` | `.faz-preference-content-wrapper` | Modal description wrapper |
| `.cky-show-desc-btn` / `.cky-hide-desc-btn` | `.faz-show-desc-btn` / `.faz-hide-desc-btn` | Show/hide description toggle |
| `data-cky-tag` | `data-faz-tag` | Tag attribute prefix |
| `[data-cky-tag="notice"]` | `[data-faz-tag="notice"]` | Banner notice |
| `[data-cky-tag="detail"]` | `[data-faz-tag="detail"]` | Modal detail |
| `[data-cky-tag="detail-category-title"]` | `[data-faz-tag="detail-category-title"]` | Accordion button |
| `[data-cky-tag="detail-category-toggle"]` | `[data-faz-tag="detail-category-toggle"]` | Checkbox wrapper |
| `[data-cky-tag="detail-description"]` | `[data-faz-tag="detail-description"]` | Modal description |
| `[data-cky-tag="show-desc-button"]` | `[data-faz-tag="show-desc-button"]` | Show more button |

---

## Accessibility Behaviors (grouped by concern)

### Focus
- Focus trap (first↔last Tab loop) on consent banner — **missing for `box` type, only exists for `popup`**
- Focus trap (first↔last Tab loop) on preference modal — exists

### ARIA Roles & Attributes
- `role="dialog"` on banner container — **missing** (currently `role="region"`)
- `aria-labelledby` on banner container pointing to banner title — **missing** (uses `aria-label` only)
- `role="dialog"` + `aria-modal="true"` on preference center — exists (set by JS)
- `aria-labelledby` on preference center pointing to modal title — **missing**
- `role="switch"` on category checkboxes — **missing**
- `aria-controls` + `aria-expanded` on accordion buttons — exists
- `aria-controls` on show/hide description button — **missing**

### Semantic Elements
- Banner title `<p role="heading" aria-level="1">` → **should be `<h2>`**
- Modal title `<span role="heading" aria-level="1">` → **should be `<h2>`**
- Accordion buttons **not wrapped in `<h3>`** — should be

### Keyboard Interaction
- ESC closes banner — **missing**
- ESC closes modal — **missing**

### Dynamic Labels
- Category checkbox `aria-label` should reflect state — **missing** (`"Analytics enabled, disable analytics"` / `"Analytics disabled, enable analytics"`)
- Show/hide description button needs `aria-controls` maintained on toggle — **missing**

---

## Architecture

### Option chosen: B — Dedicated a11y files

Three moving parts, each with a single responsibility.

---

## Part 1: `frontend/includes/class-a11y.php` (new file)

**When it runs:** build time, called from `prepare_html()` in `class-template.php` just before `$dom->saveHTML()`. The result is baked into the cached template stored in the options table.

**Why here:** `prepare_html()` already has a `DOMDocument` + `DOMXPath` in scope. All added attributes (`role`, `aria-labelledby`, `id`) are in the global allowlist in `faz_allowed_html()` and survive `wp_kses` in `update()`.

**Class:** `FazCookie\Frontend\Includes\A11y_Template`
**Method:** `public static function apply( \DOMDocument $dom, \DOMXPath $finder ): void`

### Fixes applied

| # | Fix | XPath target | Change |
|---|-----|-------------|--------|
| 1 | Banner title → `<h2>` | `[data-faz-tag="title"]` | Replace `<p>` with `<h2 id="faz-banner-title">`, remove `role` + `aria-level` |
| 2 | Modal title → `<h2>` | `[data-faz-tag="detail-title"]` | Replace `<span>` with `<h2 id="faz-modal-title">`, remove `role` + `aria-level` |
| 3 | Accordion buttons in `<h3>` | `[data-faz-tag="detail-category-title"]` | Wrap button in `<h3>` |
| 4 | `role="switch"` on checkboxes | `[data-faz-tag="detail-category-toggle"] input[type="checkbox"]` | Add `role="switch"` |
| 5 | `id` on description wrapper | `[data-faz-tag="detail-description"]` | Add `id="faz-desc-content"` (stable target for JS `aria-controls`) |

**Tag transformation pattern** (for fixes 1 & 2): create new element, copy all attributes, move all child nodes, replace original — same logic as `transformTag()` from the npm package, now in PHP.

**Cache note:** fixes are baked into the cached template. A one-time cache flush is required after deploy. Can be done via plugin admin (Settings → Clear Cache) or WP-CLI: `wp option delete faz_banner_template`.

---

## Part 2: `script.js` — one targeted edit

In `_fazLoopFocus()` (line ~742), the focus loop for the banner notice only fires for `bannerType === "popup"`. The consent container has no focus loop for `box` type.

**Change:** `if (bannerType === "popup")` → `if (bannerType !== "classic")`

This ensures the banner has a focus loop for all interactive types (popup, box) while preserving the existing skip for classic/full-width banners.

---

## Part 3: `frontend/js/a11y.js` (new file)

**When it runs:** listens for the `fazcookie_banner_loaded` custom event dispatched by `script.js` after the banner is injected into the DOM.

**Enqueued in:** `class-frontend.php`, after `script.js`, with `wp_localize_script` providing translatable strings.

### Localized data (`fazA11yConfig`)

Passed via `wp_localize_script`:

```php
[
    'checkboxEnabled'  => __( '{name} enabled, disable {name}', 'faz-cookie-manager' ),
    'checkboxDisabled' => __( '{name} disabled, enable {name}', 'faz-cookie-manager' ),
]
```

The `{name}` token is replaced in JS with the category name from the accordion button label.

### Behaviors

| # | Behavior | Implementation |
|---|----------|---------------|
| 1 | Banner `role="dialog"` + `aria-labelledby` | On init: override `role="region"` → `role="dialog"`, add `aria-labelledby="faz-banner-title"` on `.faz-consent-container` |
| 2 | Modal `aria-labelledby` | On init: add `aria-labelledby="faz-modal-title"` to `.faz-preference-center` |
| 3 | ESC closes banner | `keydown` on `.faz-consent-container`: if banner visible and key is Escape, click `.faz-banner-btn-close` |
| 4 | ESC closes modal | `keydown` on `.faz-modal`: if key is Escape, click `.faz-btn-close` |
| 5 | Checkbox `aria-label` sync | On init + on `change` per checkbox: set label using `fazA11yConfig` strings with category name substituted |
| 6 | Show/hide button `aria-controls` | On init: set `aria-controls="faz-desc-content"` on `.faz-show-desc-btn`. `MutationObserver` on `[data-faz-tag="detail-description"]` re-applies on childList change (when show→hide button is swapped) |

### Comments requirement

Every change/addition must have a small comment above it explaining what it does. This applies to both `class-a11y.php` and `a11y.js`.

---

## What's Already in the Plugin (no action needed)

- `aria-controls` + `aria-expanded` on accordion buttons — `script.js` ✓
- `role="dialog"` + `aria-modal="true"` on `.faz-preference-center` — `script.js` ✓
- `aria-label` on `.faz-consent-container` — `script.js` ✓
- `aria-label` on `.faz-preference-center` — `script.js` ✓
- Focus loop on modal — `script.js` ✓
- `aria-label` on close buttons — template HTML ✓

---

## Risks & Edge Cases

| Risk | Mitigation |
|------|-----------|
| Plugin update changes `script.js` | `_fazLoopFocus()` edit is minimal (1 line); conflict surface is tiny |
| Plugin update changes template HTML structure | PHP uses `data-faz-tag` attributes (stable API) not class names; more resilient |
| `DOMDocument` not available on server | `prepare_html()` already has the `class_exists('DOMDocument')` guard; `A11y_Template::apply()` is inside that guard |
| `fazcookie_banner_loaded` not fired | `a11y.js` should also attach to `DOMContentLoaded` as a fallback for edge cases |
| Cache not flushed after deploy | Document in deploy checklist; add a version-keyed cache bust or note in CHANGELOG |
| `wp_kses` stripping new attributes | All required attributes (`role`, `aria-labelledby`, `id`) are already in `_faz_global_attributes()` in `class-formatting.php` ✓ |
| `aria-label` + `aria-labelledby` coexist on `.faz-preference-center` | Per ARIA spec, `aria-labelledby` takes precedence; existing `aria-label` becomes unused but harmless |
| Accordion `h3` wrapper breaks existing CSS selectors | Existing selectors target `.faz-accordion-btn` directly, not `.faz-accordion-header > button` — low risk |
| Focus loop on box-type banner | Previously untested path; verify Tab order is logical in QA |
