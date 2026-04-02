# A11y Pure-JS Refactor — Design Spec

**Date:** 2026-04-02
**Branch:** feat/wcag-compliance
**Status:** Approved

## Decision

Move all accessibility DOM transforms from `class-a11y-template.php` (PHP build-time) into `a11y.js` (JS runtime). The PHP/JS split is abandoned in favour of a single JS file that owns all a11y concerns.

**Trade-off accepted:** The five structural transforms now run on every page load in the browser instead of being baked into the cached template. For a cookie banner shown once per user until consent, this is negligible — the operations are five `querySelector` calls and a handful of DOM mutations, undetectable in practice.

---

## What moves from PHP to JS

Five functions and a DOM helper are added to the `a11y.js` IIFE:

| PHP method | JS function | Action |
|---|---|---|
| `transform_banner_title` | `transformBannerTitle()` | `[data-faz-tag="title"]` → `<h2 id="faz-banner-title">`, strips `role`/`aria-level` |
| `transform_modal_title` | `transformModalTitle()` | `[data-faz-tag="detail-title"]` → `<h2 id="faz-modal-title">`, strips `role`/`aria-level` |
| `wrap_accordion_buttons_in_h3` | `wrapAccordionButtonsInH3()` | Wraps each `[data-faz-tag="detail-category-title"]` in `<h3 class="faz-accordion-heading">` |
| `add_role_switch_to_checkboxes` | `addRoleSwitchToCheckboxes()` | Sets `role="switch"` on all `[data-faz-tag="detail-category-toggle"] input[type="checkbox"]` |
| `add_description_wrapper_id` | `addDescriptionWrapperId()` | Sets `id="faz-desc-content"` on `[data-faz-tag="detail-description"]` |

A `replaceTag( node, newTag, options )` helper handles element replacement (copy attributes, strip specified ones, set id, move children, swap in tree) — the JS equivalent of the PHP `replace_tag()` private method.

### Ordering inside `init()`

The static transforms run **first**, before the existing ARIA functions. This is required because `fixBannerRole()` and `fixModalLabelledby()` reference `id="faz-banner-title"` and `id="faz-modal-title"` respectively — IDs that the transforms set.

New `init()` call order:
```
transformBannerTitle()       // sets id="faz-banner-title"
transformModalTitle()        // sets id="faz-modal-title"
wrapAccordionButtonsInH3()
addRoleSwitchToCheckboxes()
addDescriptionWrapperId()    // sets id="faz-desc-content"
fixBannerRole()              // uses faz-banner-title
fixModalLabelledby()         // uses faz-modal-title
initEscHandlers()
initCheckboxAriaLabels()
initShowHideAriaControls()   // uses faz-desc-content
```

### No idempotency guards

The assumption is that the template cache is cleared on deploy (see Deployment section). The JS functions are written without defensive "already an h2/h3" checks — the template will always serve the original markup when these run.

---

## PHP cleanup

| File | Change |
|---|---|
| `admin/modules/banners/includes/class-template.php` | Remove `A11y_Template::apply( $dom, $finder );` call and its comment |
| `frontend/includes/class-a11y-template.php` | Delete file entirely |
| `frontend/class-frontend.php` | No changes — `a11y.js` enqueue stays as-is |

---

## Test changes (`tests/e2e/specs/a11y.spec.ts`)

1. Rename `'Native a11y — PHP template fixes'` describe block to `'Native a11y — structural DOM fixes'`
2. Remove `deleteOption('faz_banner_template')` from that block's `beforeAll` — only needed to force PHP pipeline re-run, irrelevant after this refactor
3. Remove `deleteOption` import if nothing else in the file uses it

All existing assertions remain unchanged — they check the same DOM outcomes regardless of whether PHP or JS produced them. Timing is not a concern: `fazcookie_banner_loaded` fires on `DOMContentLoaded`, transforms run synchronously inside `init()`, and Playwright's retrying assertions handle any micro-delay.

---

## Files changed

| Action | File |
|---|---|
| Modify | `frontend/js/a11y.js` |
| Modify | `admin/modules/banners/includes/class-template.php` |
| Modify | `tests/e2e/specs/a11y.spec.ts` |
| Delete | `frontend/includes/class-a11y-template.php` |

---

## Deployment checklist

1. Clear `faz_banner_template` from the options table before the first page load after deploy:
   ```bash
   wp option delete faz_banner_template
   ```
   Or via WP admin → FAZ Cookie Manager → Settings → Clear Cache.
2. Hard-refresh the frontend and verify the banner and modal render with correct heading structure.
