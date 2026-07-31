# Legal Documents Generator — Implementation Plan

**Branch:** `feat/legal-documents-generator` · **Baseline:** 1.25.0 · **Status:** plan only, no code
**Scope:** generalise the Cookie Policy engine into a multi-document legal generator; ship Privacy Policy first, then Terms & Conditions and a generic Disclaimer; wire policy versioning to consent revision; add footer legal links and shared organization data.

---

## 1. Verdict

**Generalise the existing engine in place. Do not build a separate module, and do not move or rename the existing one.**

I verified the claim against the code, and it holds — with one honest qualifier. The engine (`admin/modules/cookie-policy-generator/`, ~3,930 PHP LOC) is already shaped like a general document generator that happens to have one document type hardcoded into constants:

- `Generator` is pure-static and pure-function: `resolve_template_path()`, `substitute()`, `markdown_to_html()`, `policy_version_hash()` contain **zero** cookie-specific logic. The cookie-specific parts are exactly three constants (`JURISDICTIONS`, `HTML_TOKENS`, `NATIVE_LANG`) and one hardcoded directory (`templates_dir()`).
- `Section_Overrides` operates on a `$settings` array passed in — it is **already document-agnostic**. If each document gets its own settings option, overrides gain the document dimension for free, with zero re-keying of existing data.
- `Template_Translations` is parameterised by everything except `CATALOG_FILE` and the English source path — two values.
- `Renderer` is the only genuinely coupled class: `SETTINGS_OPTION`, `build_data()`'s token set, `build_cookie_list_html()`, `collect_transfer_disclosures()`, and `disclaimer()` are cookie-policy-specific. But its pipeline (resolve lang → resolve jurisdiction → load scaffold → gettext → overrides → substitute → markdown → transfers → disclaimer → **hash → wrapper → kses**) is the pipeline every document needs.

> The tail order matters and is easy to get wrong: `register_version_meta()` runs at `class-renderer.php:212`, **before** `wp_kses()` at line 235. The hash is therefore computed over the pre-sanitisation HTML. Reordering it — for instance "tidying" the wrapper and the sanitiser into one step after hashing — moves `data-faz-policy-version` on every install and breaks the byte-for-byte guarantee the golden test enforces.

**The qualifier:** "most remaining work is editorial" is true for the programme as a whole (~70% editorial by effort), but P0a specifically has a real architectural core that the brief underestimates: a Privacy Policy needs a **new admin data model** (processing activities with purposes, legal bases, data categories, recipients, retention — none of which exist today) plus per-jurisdiction **required-field gating** that today only exists for POPIA (`Generator::missing_required_settings()` returns `array()` for every other jurisdiction). That is form UI + sanitisation + refusal logic, not template text. Call P0a roughly 45% architectural / 55% editorial; P1b is genuinely ~90% editorial.

**Effort estimate (solo, focused days):**

| Work | Architectural | Editorial |
|---|---|---|
| P0a Privacy Policy (engine generalisation + admin form + refusal gating) | 5–6 d | 6–10 d (4 EN scaffolds with legal review, then translations — stageable via the existing fallback chain) |
| P0b Footer legal links | 1 d | — |
| P2a Shared organization data (co-ships with P0a) | 1.5 d | — |
| P1a Policy version ↔ consent revision | 1.5 d | — |
| P1b Terms & Conditions + Disclaimer | 1 d | 4–6 d (single `generic` jurisdiction, see §4.5) |
| P2b / P3 | 0 (rejected, see §9) | — |
| Tests + i18n resync + release mechanics | 3 d | — |
| **Total** | **~13 d** | **~10–16 d** |

Why not a separate module: a parallel `legal-documents` module would duplicate the markdown pipeline, the gettext-catalog machinery, the section-override editor, and the sanitisation patterns — all of which took multiple point releases (1.16.1, 1.16.2, 1.23.1, 1.25.0) to harden against real user reports (Gooloo's empty-label lines, the curly-quote shortcode attrs, the JSON-in-category-names leak, the i18n placeholder-parity guards). Forking that code means fixing every future bug twice. The refactor cost of threading a `Document_Config` parameter through four classes is far below the cost of a second pipeline.

---

## 2. Engine audit — corrections to the working assumptions

I verified the brief against the code. Corrections and additions that materially affect the plan:

1. **`missing_required_settings()` gates only POPIA today.** The "refuse to generate when data is missing" machinery exists (`incomplete_configuration_notice()` in the renderer, wired at `class-renderer.php:94`) but is minimal. For a Privacy Policy it must become per-document × per-jurisdiction (registry-driven). This is the main reason P0a is more architectural than "mostly editorial".
2. **The `faz/cookie-policy` Gutenberg block renders the *legacy* canned policy**, not the engine. `Blocks::render_cookie_policy()` (`includes/blocks/class-blocks.php:148`) delegates to `[faz_cookie_policy]` with `site_name`/`contact` attrs — the pre-1.16.0 five-section canned text. The new documents must get a block that targets the engine; the old block stays untouched (BC).
3. **A consent-invalidation REST endpoint already exists.** `invalidate_consents()` in `admin/modules/settings/api/class-api.php` (~line 998) bumps `general.consent_revision`, re-reads the persisted (capped) value, and clears the banner template cache. P1a does not need a new bump mechanism — it needs a *trigger surface* and an *acknowledgment ledger* on top of this endpoint.
4. **Overrides can already target any BCP-47 language**, not just the 8 bundled ones: `Generator::policy_languages()` merges the Languages module catalogue, and `normalize_language_code()` accepts arbitrary well-formed codes. New documents inherit this for free — the plan must keep validating override languages against `policy_languages()`, not `LANGUAGES`.
5. **`Section_Overrides::apply()` returns the *original string* when nothing applies** — deliberately, because the effective scaffold is hashed into `data-faz-policy-version` and re-joining would bump the hash on every install. The generalisation must preserve this exactly (golden-file test, §6).
6. Minor: `_consentRevision` is read in `frontend/js/script.js` at line ~343 (not 334); `class-renderer.php` is 1,487 lines; the gettext catalogue is 447 lines. No impact.
7. The brief's pipeline order, constants, option names, LANGUAGES list, template layout (`templates/<jurisdiction>/<lang>.md`, 4×8 files verified on disk), and the `wp_footer` hook at `frontend/class-frontend.php:142` are all **correct as stated**.

---

## 3. Target architecture

### 3.1 The document-type abstraction: a registry of config value objects

A **registry** (`Document_Registry`) returning immutable **`Document_Config`** value objects. Not an interface hierarchy — the documents differ in *data*, not *behaviour*; the one behavioural difference (which tokens get built) is a callable in the config. Not a bare config array — a value object gives typed accessors, defaults, and a single place to validate registry entries at boot.

```text
FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Document_Registry
    ::get( string $slug ) : ?Document_Config
    ::all() : Document_Config[]
    ::slugs() : string[]

Document_Config (per document type):
    slug            'privacy-policy' | 'cookie-policy' | 'terms-conditions' | 'disclaimer'
    shortcode       'faz_privacy_policy_complete' | 'faz_cookie_policy_complete' | ...
    option          'faz_legal_doc_privacy_policy' | 'faz_cookie_policy_data' | ...
    templates_dir   absolute path (see §3.3)
    jurisdictions   string[]  — PER DOCUMENT (Privacy: the 4 existing; Terms/Disclaimer: ['generic'])
    native_lang     map jurisdiction → lang
    html_tokens     string[]  — tokens protected from markdown_to_html()
    gettext_catalog absolute path to the generated _x() catalogue file
    data_builder    callable( array $settings, string $jurisdiction, string $lang ) : array
    required_fields callable( string $jurisdiction, array $settings, Document_Config $doc ) : string[]
                    (missing dot-paths; $doc lets one shared callback tell the
                     documents apart — a callback declaring only the first two
                     parameters keeps working)
    wrapper_class   'faz-privacy-policy' etc. (cookie policy keeps 'faz-cookie-policy')

    LATER STEPS — deliberately NOT in the shipped Document_Config yet, because a
    validated field nothing reads is a contract with no implementation behind it:
    rest_base       'legal-documents/privacy-policy' | (cookie-policy keeps its own API)
    disclaimer_key  which default disclaimer text to use
    filter_tag      'faz_privacy_policy_data' etc. (cookie policy keeps 'faz_cookie_policy_data')
```

`native_lang` must name **exactly** the declared `jurisdictions` — no missing key, no unknown key. The constructor refuses both: a missing entry would silently resolve to `en` and publish a legal text in the wrong language, with nothing anywhere reporting it.

The registry is a hardcoded PHP array inside the class — **no** `apply_filters` on the registry itself in v1. Opening document registration to third parties before the shape is proven invites support burden and a compat contract we can't yet honour. Add a filter later if demand appears.

> **`data_builder` visibility — a real PHP constraint, not a style preference.** The cookie-policy builder needs `build_cookie_list_html()`, `collect_transfer_disclosures()` and `build_services_list()`, which are `private static` on `Renderer`. An array callable naming a private method and invoked from `Document_Registry` fails the visibility check: for string/array callables PHP resolves visibility at the *call site*, not where the callable was created. Three acceptable resolutions are:
>
> 1. **Dispatch inside `Renderer`** — the registry stores a document slug or a small identifier, and `render_for()` selects the builder internally. Simplest, keeps every private helper private, and is the recommended option.
> 2. **A dedicated builder class per document** with a `public static build()` — move the three helpers with it.
> 3. **A narrow public `Renderer::build_data()` entry point** registered as the callable; that public method may continue to compose the existing private helpers internally. This is the least disruptive option while the renderer still owns the cookie-specific inventory logic.
>
> What must NOT happen is registering `array( Renderer::class, '<private method>' )` and calling it from elsewhere. `Closure::fromCallable()` inside `Renderer` would also work (it binds scope at creation), but it buys nothing over option 1 and hides the coupling. Whichever is chosen, §6 gains a test that actually executes each registered document's builder — a visibility fatal must surface in the suite, not in production.

The shipped Cookie Policy registry slice chooses option 3: it registers the public
`Renderer::build_data()` entry point, which composes the private inventory helpers internally.

### 3.2 How the four engine classes become document-aware

Every existing public signature keeps working unchanged. The document dimension is added via new methods / optional trailing parameters that default to the cookie-policy config.

| Class | Change | BC guarantee |
|---|---|---|
| `Generator` | `resolve_template_path( $jurisdiction, $lang, ?Document_Config $doc = null )`; null → cookie-policy behaviour byte-identical (same dir, same `JURISDICTIONS`/`NATIVE_LANG` consts, which stay as the cookie-policy config's source of truth). `substitute()`, `markdown_to_html()`, `policy_version_hash()`, `normalize_language_code()` are already generic — **no change**. `missing_required_settings()` grows a `$doc` param; null keeps the current POPIA-only behaviour. | Existing constants unreferenced-from-outside stay; unit tests pass unmodified |
| `Renderer` | Extract the pipeline body of `render()` into `render_for( Document_Config $doc, array $atts )`. `render( $atts )` resolves the Cookie Policy config, guards a missing/invalid registry entry with a safe empty string, then delegates. In the shipped registry slice, settings option, template coordinates, catalogue, required-fields callback, public data builder and wrapper class read from `$doc`; `disclaimer_key` and `filter_tag` remain deferred with their config fields. The cookie-specific inventory helpers stay private behind `Renderer::build_data()`. | `Renderer::render()` output byte-identical (golden-file test); an invalid registry state never causes a public-page `TypeError` |
| `Section_Overrides` | **No change.** It already receives `$settings`; each document's own option carries its own `section_overrides[jurisdiction][lang][index]` subtree. Existing cookie-policy overrides never move, never re-key. | Untouched file |
| `Template_Translations` | `apply( $jurisdiction, $lang, $scaffold, ?Document_Config $doc = null )`; catalogue path + English source path come from `$doc`; null → current constants. `split_sections()` and the placeholder-parity guards are already generic. | Existing gettext tests pass unmodified |

New shared data builder: `Document_Data::base_tokens( $settings, $jurisdiction, $lang )` produces the tokens every document shares (COMPANY_*, DPO_*, LAST_UPDATED_DATE, PRIVACY_POLICY_URL, JURISDICTION_NAME, LANGUAGE_NAME, OFFICIAL_RESOURCES_URL, the locale-aware date/month machinery lifted from `Renderer`). Each document's `data_builder` merges its own tokens on top. Company/DPO values resolve through the P2a precedence chain (§5).

> `LAST_UPDATED_DATE` is a **volatile** token: it is derived from the current date, so a committed golden fixture containing it would go stale overnight and every document's hash would drift daily. Both halves of that are already solved and must stay solved when the token moves into `Document_Data`: the token is listed in `Generator::HASH_VOLATILE_KEYS`, so `policy_version_hash()` excludes it, and `tests/unit/test-cookie-policy-golden-render.php` pins `current_time()` to a fixed instant so the rendered date in the fixtures is deterministic. A new document that adds its own date-derived or otherwise time-varying token must do the same two things, and §6 keeps an assertion that the hash is unchanged by the date alone.

### 3.3 Directory layout

```text
admin/modules/cookie-policy-generator/          ← directory name UNCHANGED (see below)
  class-cookie-policy-generator.php             ← unchanged (cookie-policy bootstrap)
  class-legal-documents.php                     ← NEW: bootstrap for the additional documents
                                                  (registers their shortcodes, REST API, block,
                                                  frontend CSS; called from faz-cookie-manager.php
                                                  next to Cookie_Policy_Generator::init())
  includes/
    class-document-registry.php                 ← NEW
    class-document-config.php                   ← NEW
    class-document-data.php                     ← NEW (shared token builder)
    class-generator.php                         ← modified (doc param, defaults preserve BC)
    class-renderer.php                          ← modified (render_for extraction)
    class-section-overrides.php                 ← UNCHANGED
    class-template-translations.php             ← modified (catalog path via doc)
    cookie-policy-gettext-catalog.php           ← UNCHANGED
  api/
    class-cookie-policy-api.php                 ← UNCHANGED (faz/v1/cookie-policy/* frozen)
    class-legal-documents-api.php               ← NEW (faz/v1/legal-documents/*)
  templates/                                    ← UNCHANGED — cookie-policy scaffolds stay at
    gdpr-strict/{en,it,fr,de,es,pt-BR,bg,cs}.md    their exact current paths
    ccpa-california/… lgpd-brazil/… popia-southafrica/…
  documents/                                    ← NEW (index.php at every level)
    privacy-policy/
      templates/gdpr-strict/en.md …             ← same <jurisdiction>/<lang>.md convention
      templates/ccpa-california/… lgpd-brazil/… popia-southafrica/…
      gettext-catalog.php                       ← generated
    terms-conditions/
      templates/generic/en.md …                 ← single 'generic' jurisdiction (§4.5)
      gettext-catalog.php
    disclaimer/
      templates/generic/en.md …
      gettext-catalog.php
```

**Why the module directory keeps its name:** renaming `cookie-policy-generator/` → `legal-documents/` would churn the autoloader namespace map, every existing test import, the SVN history, and the Plugin Check baseline — for zero user-visible value. The *concept* is renamed in docblocks and the admin UI; the path is an implementation detail 1,000 live installs already carry.

**Why cookie-policy templates don't move:** `policy_version_hash()` feeds `sha1_file( $template_path )` when no gettext override applies. Moving the files changes nothing in the hash (content-addressed), but keeping paths stable means `resolve_template_path()` for cookie-policy needs no compatibility shim at all, and a downgrade to 1.25.0 finds everything where it expects it.

**Why `documents/<slug>/templates/<jurisdiction>/<lang>.md`:** each document owns a self-contained directory (templates + generated catalogue + any future per-doc asset), which keeps the wp.org ZIP layout reviewable and lets the per-document jurisdiction list differ (Terms has `generic/`, Privacy has the four legal regimes) without empty placeholder dirs.

### 3.4 Shortcode / REST / block surface

**Shortcodes** — one per document, mirroring the `_complete` pattern (attributes `lang`, `jurisdiction`, `show_title`, same curly-quote attr cleanup):

- `[faz_privacy_policy_complete]` — NOT `[faz_privacy_policy]`: symmetric with `[faz_cookie_policy_complete]`, and it leaves the unsuffixed name free forever (the cookie side has a legacy unsuffixed shortcode; keeping the naming rule "engine documents end in `_complete`" is worth more than a shorter name). Verified: no `faz_privacy_policy*`, `faz_terms*`, `faz_disclaimer*` shortcode exists today.
- `[faz_terms_conditions_complete]`, `[faz_disclaimer_complete]` (P1b).

**REST** — new namespace path, one generic controller registering three separate routes rather than one alternation (an un-grouped `a|b|c` in a route regex matches things nobody intends, including an empty document slug):

- `faz/v1/legal-documents/(?P<document>[a-z-]+)/settings`
- `faz/v1/legal-documents/(?P<document>[a-z-]+)/preview`
- `faz/v1/legal-documents/(?P<document>[a-z-]+)/scaffold`

The document slug is validated against `Document_Registry::slugs()` minus `cookie-policy`. The existing `faz/v1/cookie-policy/*` routes are **frozen**: same class, same responses, byte-identical. The new controller reuses the old one's `check_admin_read`/`check_admin_write` pattern, `trim_clip`/`trim_clip_multiline` helpers (moved to a shared trait or small `Api_Helpers` class), and the same sanitise-against-defaults strategy. Every **write** route (`settings`, `acknowledge`, and any added later) checks `current_user_can( 'manage_options' )` **and** verifies the nonce through `faz_verify_nonce()` — both, not either; tests cover missing nonce, invalid nonce, and an authenticated user without the capability. `suggest-services`/`detected-services` stay cookie-policy-only (they are scanner-driven and cookie-specific).

**Blocks** — one new block `faz/legal-document` with attributes `document` (enum from registry, default `privacy-policy`), `jurisdiction`, `lang`, `show_title`. Server-rendered via `Renderer::render_for()`. Registered in `includes/blocks/class-blocks.php` next to the existing three; the legacy `faz/cookie-policy` block is untouched. (`register_block_type` with array config is WP 5.0-safe — same call pattern already shipping.)

**Admin UI** — new page **Legal Documents**:

- `admin/class-admin.php` `pages[]`: add `legal-documents` entry (`slug` `faz-cookie-manager-legal-documents`, `view` `legal-documents`).
- `admin/views/base.php`: add to `$faz_nav_items` (after `cookie-policy`) and `$faz_page_descriptions`. View is a fragment per plugin convention — no `<div class="wrap">`, no `<h1>`.
- `admin/views/legal-documents.php` + `admin/assets/js/pages/legal-documents.js` (FAZ.get/FAZ.post, boot-flag pattern `fazLdBooted` mirroring `fazCpBooted` in `class-admin.php:1046`).
- The existing **Cookie Policy *editor* is not restructured** in this programme — no tabs, no fields moved, no re-layout. Deliberate: it is a shipped, hardened editor; folding it into a tabbed mega-page in the same release that introduces three new documents doubles the regression surface for pure aesthetics. The one thing that *is* added to that view is the P1a version notice (§P1a), rendered above the editor and touching none of it. Stating this here because "stays exactly as it is" and "add a notice to the Cookie Policy view" read as a contradiction otherwise. The Legal Documents page lists all documents including Cookie Policy, with Cookie Policy linking to its existing page. Nav consolidation is a candidate for a later cosmetic release, explicitly out of scope here.

The Legal Documents page structure: document switcher (Privacy Policy / Terms / Disclaimer as they ship) → per-document editor with the same building blocks as the cookie-policy page (jurisdiction select where applicable, language priority, field form, section-override editor reusing the describe/anchor semantics, live preview via the preview endpoint) → plus the two cross-document panels: **Organization** (P2a) and **Footer links** (P0b).

---

## 4. Data model and migration

### 4.1 Options (all additive — nothing existing changes shape)

| Option | Shape | Owner |
|---|---|---|
| `faz_cookie_policy_data` | **UNCHANGED, byte-for-byte.** Still owned and written exclusively by `Cookie_Policy_Api`. | existing |
| `faz_organization` | `{ legal_name, trading_name /*DBA*/, address, email, registry, contact_url, dpo: { name, email, address } }` — all strings, all default `''` | P2a |
| `faz_legal_doc_privacy_policy` | `{ jurisdiction, default_lang, language_priority, processing_activities: [ { purpose, legal_basis, data_categories[], recipients, retention } ], data_sources, automated_decisions: {enabled, text}, childrens_data: {enabled, text}, dsar: { method, url_or_email }, section_overrides: {...}, disclaimer: { show, text }, company_override: { … same keys as faz_organization, all '' = inherit } }` | P0a |
| `faz_legal_doc_terms_conditions` | `{ default_lang, language_priority, governing_law, venue, refund_note, section_overrides, disclaimer }` | P1b |
| `faz_legal_doc_disclaimer` | `{ default_lang, language_priority, section_overrides, disclaimer }` | P1b |
| `faz_legal_links` | `{ enabled: bool, items: [ { page_id: int, label: '' /*'' = page title*/ } ] }` | P0b |
| `faz_legal_doc_acknowledged` | `{ '<doc>': { hash: 'xxxxxx.yyyyyy', acknowledged_at: ts } }` — the admin-acknowledged version ledger | P1a |

Notes:

- **One option per document, not one mega-option.** This is what makes `Section_Overrides` document-aware with zero code change, keeps each REST `set_settings` payload small, and makes a partial rollback of one document's data trivial.
- Every new option is registered in `uninstall.php` under the existing `remove_data_on_uninstall` gate. Registering the new keys is necessary but not sufficient: the release gate is that uninstall removes **all** plugin data — every `wp_faz_*` table, every `faz_*` option (`faz_cookie_definitions` and `faz_cookie_definitions_meta` included, since they are written outside the settings API and are easy to miss), every `faz_*` transient, and both upload directories (`faz-cookie-manager/` and the legacy `fazcookie/`). On multisite that sweep runs per blog via `get_sites()` + `switch_to_blog()`, otherwise a network uninstall leaves every subsite's data behind. §6 asserts the sweep against a populated install rather than only checking that the new keys appear in the file.
- All autoloaded=no except `faz_legal_links` (read on every frontend request).

### 4.2 Organization data precedence (P2a, co-ships with P0a)

Resolution order for company/DPO tokens, per document:

1. Document-level override (`company_override` fields, where non-empty) — exists so a multi-brand site can publish documents for different entities.
2. `faz_organization` (where non-empty).
3. For the **cookie policy only**: `faz_cookie_policy_data.company` / `.dpo` — its own saved values keep absolute priority over the shared option, so no existing install's rendered policy can change. Only when a cookie-policy field is *empty* AND the shared org field is set (which requires an explicit 1.26.0+ admin save) does the shared value fill in.
4. `''` → the existing `strip_empty_label_lines()` / `missing_required_settings()` machinery handles absence.

**Seeding:** never automatic. When the Legal Documents page loads and `faz_organization` is absent but `faz_cookie_policy_data.company.name` is non-empty, the UI shows a prefill banner — "Copy your Cookie Policy company details into shared Organization data?" — which populates the *form*; the option is written only on explicit Save. Consistent with the existing deliberate refusal to seed from `admin_email`/`blogname` (that refusal stays: the shared org form starts empty on fresh installs, full stop).

**Downgrade:** a 1.25.0 install ignores every `faz_legal_doc_*`/`faz_organization`/`faz_legal_links` option; the cookie policy renders from `faz_cookie_policy_data` exactly as before. The one documented asymmetry: an admin who filled *only* shared org data (leaving cookie-policy company empty) and then downgrades loses those values from the rendered cookie policy — acceptable, and impossible without a 1.26.0 admin action first.

### 4.3 Activator / upgrade routine

Follows the existing pattern (`Activator::check_for_upgrade()` → `update_db_XXX()` steps, `faz_version` bumped last):

- **No DB migration is needed at all for P0a/P0b/P2a** — everything is additive options created on first save. The upgrade step for 1.26.0 does exactly one thing: `faz_clear_banner_template_cache()` already happens on every version bump (`install()` at `class-activator.php:451`); nothing else.
- 1.27.0 (P1a) adds no migration either; the acknowledgment ledger initialises lazily: on first Legal Documents page load post-upgrade, current hashes are recorded as acknowledged (so the upgrade itself never triggers a "policy changed" prompt — see §5 P1a).

### 4.4 Section overrides with the document dimension

Unchanged keying `[jurisdiction][lang][index]` with the stored `anchor` drift guard — the document is implied by which option the subtree lives in. `Section_Overrides::sanitize()` is called per-document with that document's `jurisdictions` list from the registry (for Terms/Disclaimer that list is `['generic']`). `MAX_SECTIONS=30` / `MAX_TEXT=10000` stay global — no shipped scaffold approaches them.

### 4.5 Terms & Conditions jurisdictions: `generic`, not the four regimes

The four-bucket jurisdiction model (GDPR/CCPA/LGPD/POPIA) is a *privacy-law* taxonomy. Contract terms don't vary along it — they vary by governing law and venue, which are admin-supplied facts, not template forks. Pretending otherwise would mean writing 32 T&C scaffolds whose bodies are 95% identical, then maintaining them. Decision: Terms and Disclaimer register `jurisdictions: ['generic']` (registry makes this a per-document property), with `{{GOVERNING_LAW}}` / `{{VENUE}}` placeholders and an EU-consumer-rights section that is present in the scaffold and deletable via section overrides. This cuts P1b editorial from 64 documents to 16 (2 docs × 8 languages) and is legally more honest.

---

## 5. Phase-by-phase plan

### P0a — Privacy Policy generator (1.26.0)

**Files created**

| Path | Purpose |
|---|---|
| `admin/modules/cookie-policy-generator/class-legal-documents.php` | Module bootstrap (shortcodes, REST, block wiring, frontend CSS reuse) |
| `admin/modules/cookie-policy-generator/includes/class-document-registry.php` | Registry |
| `admin/modules/cookie-policy-generator/includes/class-document-config.php` | Value object |
| `admin/modules/cookie-policy-generator/includes/class-document-data.php` | Shared token builder (extracted from Renderer helpers) |
| `admin/modules/cookie-policy-generator/api/class-legal-documents-api.php` | `faz/v1/legal-documents/*` |
| `admin/modules/cookie-policy-generator/documents/privacy-policy/templates/<jur>/<lang>.md` | Scaffolds (4 jurisdictions; EN first, translations staged) |
| `admin/modules/cookie-policy-generator/documents/privacy-policy/gettext-catalog.php` | Generated |
| `admin/views/legal-documents.php` | Admin view fragment |
| `admin/assets/js/pages/legal-documents.js` | Page JS |
| `scripts/generate-legal-doc-gettext-catalog.php` | Generalised from the cookie-policy script (takes a document slug) |
| `index.php` in every new directory | Silence is golden |

**Files modified**

| Path | Change |
|---|---|
| `includes/class-generator.php` | `$doc` params with BC defaults; registry-driven `missing_required_settings` |
| `includes/class-renderer.php` | `render_for()` extraction; `render()` delegates |
| `includes/class-template-translations.php` | catalogue path via `$doc` |
| `faz-cookie-manager.php` | `Legal_Documents::get_instance()->init()` next to the cookie-policy init |
| `admin/class-admin.php` | `pages[]` entry, script registration + boot-flag map entry |
| `admin/views/base.php` | `$faz_nav_items` + `$faz_page_descriptions` |
| `includes/blocks/class-blocks.php` | register `faz/legal-document` |
| `uninstall.php` | new options under the gate |
| `readme.txt` / `README.md` / `CHANGELOG.md` | docs (§8) |

**Public API added:** shortcode `[faz_privacy_policy_complete lang jurisdiction show_title]`; REST `faz/v1/legal-documents/privacy-policy/{settings,preview,scaffold}`; block `faz/legal-document`; PHP filter `faz_privacy_policy_data` (same contract as `faz_cookie_policy_data`).

**Privacy-policy-specific tokens** (on top of the shared base set): `PROCESSING_ACTIVITIES` (HTML token — a table of purpose / legal basis / data categories / recipients / retention, built like `build_cookie_list_html()` and registered in the doc's `html_tokens`), `DATA_SOURCES`, `DSAR_CONTACT`, `AUTOMATED_DECISIONS`, `CHILDRENS_DATA`, `DATA_SUBJECT_RIGHTS` (jurisdiction-fixed HTML list rendered from reviewed per-jurisdiction text, not admin input), plus reuse of the cookie module's transfer disclosures where flagged.

**Refusal gating (hard requirement 7).** The registry's `required_fields` for privacy-policy, every jurisdiction: `organization legal_name`, `address`, `email` (validated), `dsar.method`, and **at least one processing activity with a non-empty purpose**; GDPR and LGPD additionally require a legal basis on every activity. When missing, the shortcode/block/REST-preview render the existing `incomplete_configuration_notice()` path — the same behaviour cookie-policy POPIA has today, so no new public-facing pattern. The plugin **never** invents facts, never seeds from `admin_email`/`blogname`, and never renders a partial Privacy Policy that silently omits a mandatory section.

**Disclaimer.** The `disclaimer()` machinery is reused with a per-document default text (registry `disclaimer_key`). The privacy-policy default is stronger than the cookie one: template text is a reviewed starting point, describes only what the admin declared, is not legal advice, and the operator is responsible for its accuracy and completeness. Admin can replace/hide it exactly like today.

**Acceptance criteria**

- `[faz_privacy_policy_complete]` renders a complete GDPR policy **in English plus every language whose translation has passed review** once required fields are saved; renders the incomplete-configuration notice otherwise. Languages still awaiting review resolve through the documented fallback chain (requested → jurisdiction native → en) and must never render half-translated text. This deliberately does *not* require all 32 jurisdiction × language combinations before 1.26.0 — see the release plan (§8), which stages the remainder as editorial review completes. The two statements are the same gate, phrased once here and once there; if you change one, change both.
- `data-faz-policy-version` hash present and stable across reloads; changes when a template, an override, or material settings change; does not change with the calendar date.
- Section overrides editable per jurisdiction × language with anchor drift protection; placeholder warnings surface.
- `wp plugin check faz-cookie-manager --categories=plugin_repo` → 0 ERRORS on the wp.org-shape ZIP.
- Every existing cookie-policy unit/E2E test passes **unmodified**, and the cookie-policy golden-file render test (new, §6) proves byte-identical output.

**Done means:** an admin with zero cookie-policy configuration can install 1.26.0, fill the Organization + Privacy Policy forms, place the shortcode, and publish a jurisdiction-correct, versioned, disclaimer-carrying Privacy Policy with the server offline (no outbound HTTP anywhere in the flow) — while every 1.25.0 surface behaves byte-identically.

### P0b — Automatic legal links in the footer (1.26.0)

**Files:** `frontend/modules/legal-links/class-legal-links.php` (+ `index.php`), small CSS appended to the existing `frontend/css/faz-cookie-policy.css` enqueue or a dedicated `faz-legal-links.css` enqueued only when enabled; admin panel inside `legal-documents.php`; option `faz_legal_links`; REST handled by the legal-documents settings controller (`faz/v1/legal-documents/links`). Wired from `Frontend::init()` next to the existing `wp_footer` `banner_html` hook (priority 20, after the banner).

Rendering: `<nav class="faz-legal-links" aria-label="…">` of `<a>` items from `get_permalink()` + (`label` override or `get_the_title()`), `esc_url`/`esc_html` throughout; unpublished/trashed pages skipped at render time. Output is visitor-invariant (no per-visitor branches) — safe under Cache Compatibility Mode, and the plan explicitly adds it to the invariance test list (memory: 1.21.0 lesson — invariance must cover *every* render path).

**Acceptance:** links render on `wp_footer` for anonymous visitors, HTML identical across visitors/consent states; disabled by default; empty item list renders nothing (no empty `<nav>`). **Done means:** admin picks pages, footer shows them, page caches serve one variant.

### P1a — Policy version ↔ consent revision (1.27.0)

Admin-controlled, never automatic — by design *and* because an automatic link would be wrong: the hash bumps on translation catalogue updates and cosmetic template fixes shipped by *us*, which are not material changes to *the site's* policy. Only the operator can judge materiality; the plugin's job is to *notice and ask*.

**Mechanism**

1. **Ledger** `faz_legal_doc_acknowledged` (§4.1): per document, the last hash the admin acknowledged. Hash tracked = the document's *default* render combination (saved jurisdiction + saved/default language) — the same combination the version meta already exposes. Tracking all j×l combos would multiply prompts without changing the decision.

   **Tracking the default combination alone is not enough, so it is not what we do.** Overrides are keyed `[jurisdiction][lang][index]`; an operator who rewrites only the French text, or only a non-default jurisdiction, changes that variant's `data-faz-policy-version` and under a default-only ledger would get **no notice at all** — the published document changes and nobody is asked. So the ledger tracks **the default combination plus every jurisdiction × language pair that has a saved override**. That set is bounded by what the operator actually authored (usually zero or one or two entries), not by the 32-cell j×l grid, so the prompt volume objection does not apply. Any variant whose hash moves raises the notice, naming which one. §6 covers a non-default variant edit raising the notice, and an untouched variant staying silent.
2. **Detection**: on Legal Documents / Cookie Policy admin page load (admin-side only — zero frontend cost), compute current hash via the existing `policy_version_hash()` path; when it differs from the ledger, show a notice: *"Your Privacy Policy has changed since you last confirmed it (version a1b2c3 → d4e5f6)."* with three actions:
   - **"This was a minor change"** → records the new hash in the ledger. Nothing else.
   - **"Material change — re-ask consent"** → calls the **existing** `invalidate_consents` endpoint (settings API, verified in `admin/modules/settings/api/class-api.php:998`), which bumps `general.consent_revision` (existing frontend mechanics in `script.js` ~343 then re-show the banner to returning visitors), then records the hash. A confirm dialog states the consequence (all returning visitors re-prompted).
   - Dismiss → notice returns next page load.

   **The re-consent action is offered for cookie-consent documents ONLY.** `general.consent_revision` governs the *cookie* banner. Terms & Conditions and the generic Disclaimer are contractual documents that have nothing to do with the ePrivacy consent a visitor gave for cookies; wiring their acknowledgment to that counter would re-prompt every returning visitor for cookie consent because a contract clause changed. That is both wrong in substance and a straight path to consent fatigue, which is itself a compliance problem — a banner people dismiss reflexively is not informed consent.
   So the registry carries a per-document flag (`affects_cookie_consent`, true for cookie-policy, false for terms-conditions and disclaimer). When false, the notice offers only "record this change" and the re-consent action is not rendered at all — not rendered-and-disabled, absent. The Privacy Policy sits in between: it is where the cookie disclosures are legally framed, so it defaults to true, but the flag makes that an explicit decision rather than an accident of implementation.
   §6 gains an E2E assertion: acknowledging a *material* change to Terms leaves `consent_revision` untouched and does not re-show the banner to a visitor holding a valid consent cookie.
3. **Upgrade neutrality**: on first load after the 1.27.0 upgrade the ledger is empty → it is seeded silently with current hashes, so the feature's own arrival never generates a prompt. Two conditions on that seeding, both load-bearing:
   - **Either eligible admin page seeds** (Legal Documents *or* Cookie Policy), whichever the operator opens first. Seeding from only one of them would make the other show a false "the policy changed" notice on its first visit, which is the precise thing this step exists to prevent.
   - **Seed only after a successful render that produced a valid hash.** When required fields are missing the document does not render, there is no meaningful hash, and nothing may be written to `faz_legal_doc_acknowledged` — otherwise the operator completes the configuration later and the resulting real change is silently treated as already acknowledged. §6 covers both pages and the incomplete-configuration case.

4. **Idempotency of the material path.** "Material change — re-ask consent" is two writes: bump the revision, then record the hash. If the second fails, a retry would bump the revision *again* and re-prompt the whole audience twice for one decision; if the order were reversed, a failure would leave the ledger saying "acknowledged" while no re-prompt ever happened — the worse of the two, because it is silent. So the acknowledgment endpoint accepts the intent as **one server-side operation**: it records a pending marker, bumps the revision only if the marker is not already satisfied for that hash, then finalises. A retry after a partial failure completes the operation instead of repeating it. §6 covers the retry-after-partial-failure path explicitly, since it cannot be observed by hand.

**Files:** `includes/class-version-ledger.php` (new, inside the module), notice rendering inside `legal-documents.php` + the cookie-policy view, one new REST route `faz/v1/legal-documents/acknowledge` (POST; `manage_options` **and** `faz_verify_nonce()`, per §4), JS in `legal-documents.js`. No frontend files change at all — the consent-revision plumbing already exists end-to-end.

**Acceptance:** editing a section override surfaces the notice; "minor" silences it without touching consent; "material" demonstrably re-shows the banner to a visitor with a prior consent cookie (E2E); plugin upgrade alone never prompts. **Done means:** the operator has a one-click, fully-informed path from "policy text changed" to "visitors re-consent", and no path where it happens without them.

### P1b — Terms & Conditions + generic Disclaimer (1.27.0)

Registry entries + option shapes per §4.1/§4.5, shortcodes `[faz_terms_conditions_complete]` / `[faz_disclaimer_complete]`, `documents/terms-conditions/` + `documents/disclaimer/` scaffold trees (`generic/<lang>.md`), gettext catalogues, editor panels in the Legal Documents page. Engine work is near-zero (that is the payoff of P0a); the work is editorial: reviewed EN scaffolds (T&C: acceptance, services description placeholder, IP, user obligations, liability limitation, governing law `{{GOVERNING_LAW}}`/`{{VENUE}}`, EU consumer-rights section, changes-to-terms; Disclaimer: no-warranty, external links, professional-advice, affiliate-free) + 7 translations each. Required fields: organization legal name + email; T&C additionally `governing_law`.

**Acceptance:** both documents render with overrides/version-hash/disclaimer parity with the other documents; the T&C disclaimer explicitly flags that contract terms need counsel review. **Done means:** the competitor's remaining free-tier template set is matched or exceeded, all offline, all free.

### P2a — Shared organization data (ships **inside 1.26.0 with P0a**)

Designed in §4.2. Ships with P0a precisely because retrofitting shared identity after two documents store their own copies is the painful migration the brief warns about. Files: `includes/class-organization.php` (option access + precedence resolution, used by `Document_Data`), Organization panel in `legal-documents.php`, `faz/v1/legal-documents/organization` GET/POST. The DBA/`trading_name` field renders as `{{COMPANY_TRADING_NAME}}` where scaffolds use it ("… operating as **{{COMPANY_TRADING_NAME}}**"), stripped by the existing empty-label machinery when blank.

**Acceptance:** cookie-policy output unchanged for every pre-existing configuration (golden test); org fields flow into privacy-policy tokens; prefill-from-cookie-policy requires explicit Save. **Done means:** company identity is typed once and consumed by N documents, with the cookie policy's own saved data always winning for the cookie policy.

### P2b — Age verification content gate: **not built**

Argued honestly, as requested: this does not belong in this plugin.

1. **Mission**: FAZ manages consent and privacy documents. A content gate is access control — a different product with its own hard problems (caching, SEO cloaking, session handling). Our Cache Compatibility work (1.21.0) is built on render invariance; a server-side age gate is per-visitor branching on every page, i.e. the exact thing we spent a release eliminating.
2. **Legal reality**: a self-declared age popup verifies nothing and creates a false sense of compliance; regulators treat such gates as decorative. Where age *assurance* is legally required (AVMSD-adjacent content, UK OSA), a checkbox popup is not a compliant answer, and shipping one implies it is.
3. **We already cover the case that IS ours**: the GDPR Art. 8 age-affirmation checkbox in the banner ties age confirmation to the consent act — the only place where a cookie plugin has legitimate standing on age.

Deliverable instead: a readme FAQ entry explaining the Art. 8 checkbox and why FAZ deliberately does not gate content.

### P3 — Force agreement / affiliate disclosure / announcement banner: **assessed, rejected**

- **Force agreement**: even confined to contractual terms, the plugin cannot *enforce* acceptance anywhere meaningful without per-form-plugin integrations (WooCommerce already ships a native terms checkbox that can point at our generated Terms page; registration forms are a fragmented matrix). What remains is an unvalidated checkbox shortcode of near-zero value carrying real reputational risk: any UI proximity between "force agreement" and cookie consent invites the cookie-wall reading the EDPB treats as invalidating freely-given consent. Rejected. The Terms editor UI will instead carry a help note: *"To require acceptance at checkout, point WooCommerce's Terms and Conditions setting at this page."* — that sentence delivers the whole use case with zero code.
- **Affiliate disclosure**: content/FTC-compliance tooling, not privacy. Out of mission; a static paragraph any page can hold. Rejected.
- **Announcement banner**: entirely out of mission, huge overlap with dedicated plugins, and an announcement bar visually adjacent to a consent banner is dark-pattern-adjacent surface we do not want reviewed alongside our consent UI. Rejected.

---

## 6. Test strategy

Test runners in place: PHP units via `scripts/run-unit-tests.sh` (`npm run test:unit`, `tests/unit/*.php`), jsdom JS units (`tests/unit/js/`), Playwright E2E (`tests/e2e/specs/`), standalone compliance suites (`tests/compliance/`). Per repo policy, tests run when the change warrants it — this programme warrants all four suites at each phase gate.

**New tests**

| Suite | File | Covers |
|---|---|---|
| PHP unit | `tests/unit/test-legal-documents-registry.php` | Registry integrity: every doc's templates exist on disk for every declared jurisdiction (≥ en), `index.php` present in every new dir, html_tokens ⊆ tokens used, option names unique, shortcode names unregistered elsewhere |
| PHP unit | `tests/unit/test-privacy-policy-generator.php` | Refusal matrix per jurisdiction; token substitution incl. `PROCESSING_ACTIVITIES` sentinel round-trip; hash stability (volatile-key exclusion); fallback chain lang→native→en; disclaimer default/override |
| PHP unit | `tests/unit/test-cookie-policy-golden-render.php` | **The BC keystone**: fixture `faz_cookie_policy_data` configs (empty, full GDPR, POPIA-complete, with overrides, with gettext locale) rendered through 1.25.0 code once to produce committed golden HTML; the refactored `render()` must reproduce them byte-identically (hash attr included) |
| PHP unit | `tests/unit/test-organization-data.php` | Precedence chain (§4.2), no silent seeding, sanitisation caps, downgrade-shape safety |
| PHP unit | `tests/unit/test-legal-doc-section-overrides.php` | Overrides in a `faz_legal_doc_*` option: anchor drift, per-doc jurisdiction list (`generic`), placeholder warnings |
| PHP unit | `tests/unit/test-version-ledger.php` (P1a) | Seed-on-empty, minor vs material paths, upgrade neutrality |
| E2E | `tests/e2e/specs/legal-documents.spec.ts` | Admin page loads (via `wp-fixture` `loginAsAdmin`), settings save round-trip over REST with nonce, preview renders, frontend shortcode page shows policy / shows notice when unconfigured |
| E2E | `tests/e2e/specs/legal-footer-links.spec.ts` | Enable + pick pages → links in footer for a fresh context (`context.clearCookies()`), disabled → absent, invariance across consent states |
| E2E | `tests/e2e/specs/policy-revision-link.spec.ts` (P1a) | Edit override → notice appears → "material" → visitor with existing consent cookie sees banner again (`_consentRevision` bump observed) |
| jsdom | — none — | New frontend surface is server-rendered; no client JS logic to unit-test. `legal-documents.js` admin logic is covered E2E |
| Compliance | extend `tests/compliance/final-verification.mjs` | One check: legal-documents admin page reachable + privacy-policy REST settings GET returns schema-shaped payload |

**Regression guards (must pass unmodified, every phase)**

- PHP: `test-cookie-policy-generator.php`, `test-cookie-policy-section-overrides.php`, `test-cookie-policy-template-translations-php.php`, `test-czech-renderer-php.php`.
- E2E: `cookie-policy-integration.spec.ts`, `cookie-policy-gettext.spec.ts`, `cookie-policy-section-overrides.spec.ts`, `cookie-policy-1.16.2-regressions.spec.ts`, `cookie-policy-service-auto-detect.spec.ts`; plus `cache-compatibility-mode.spec.ts` after P0b (footer-link invariance).
- Full `npm run test:compliance` + `npm run test:verify` before each release per the standing release gate; PHP syntax sweep (`php -l` find loop) and `wp plugin check` (0 ERRORS) in the release checklist.

---

## 7. Risk register

| # | Risk | Likelihood / impact | Mitigation |
|---|---|---|---|
| R1 | **Legal**: a generated Privacy Policy asserts facts about all processing; a wrong/incomplete policy exposes the site operator (and reputationally, us) | Medium / **High** | Refusal gating (§P0a) — no render without operator-supplied mandatory facts; never invent or seed data; strengthened per-document disclaimer; jurisdiction-fixed rights text is reviewed editorial content, not generated claims; "reviewed starting point" framing everywhere in UI + readme |
| R2 | **BC break** on the shipped cookie policy during the Renderer/Generator refactor (output, hash, option shape, REST) | Medium / **High** | Golden-render fixture test (§6) as a hard gate; `Section_Overrides` and cookie-policy API/templates untouched; all new params defaulted; frozen `faz/v1/cookie-policy/*`; existing suites unmodified |
| R3 | **Plugin Check / wp.org review**: new dirs missing `index.php`, unescaped output in new views, unprepared queries, or the static WP-version rule | Medium / High | No new WP APIs beyond the 5.0-safe set already in use (no `%i`, no `wp_cache_*` newcomers — the R2-adjacent memory items); registry test asserts `index.php` presence; 0-ERRORS check is a release-blocking step; **no** new outbound HTTP anywhere, so "External Services" in readme.txt is untouched |
| R4 | **i18n drift**: 3 new gettext catalogues + admin UI strings desync .pot/.po/.mo; scaffold translations lag EN scaffold edits | High / Medium | One resync per release at the end (make-pot → msgmerge over the **UI locale catalogues** `cs_CZ de_DE fr_FR hr_HR it_IT nl_NL`, i.e. exactly the `.po` files in `languages/` → translate it_IT → msgfmt), exactly once per the standing rule. Note these are a different set from the **policy template languages** (`en it fr de es pt-BR bg cs`, `Generator::LANGUAGES`): the first is which locales the plugin's interface is translated into, the second is which languages a policy scaffold ships in. They overlap but neither contains the other, and conflating them produces a resync that touches the wrong files; catalogue generation scripted (`generate-legal-doc-gettext-catalog.php`) not hand-edited; `Template_Translations` placeholder-parity guard already rejects broken PO entries section-by-section; staged languages ride the documented fallback chain instead of shipping unreviewed text |
| R5 | **Scope creep** toward the competitor's checklist (age gate, announcement bar, force agreement, DMCA/COPPA/EULA) | High / Medium | §5 P2b/P3 rejections and §9 non-goals are part of this committed plan; registry leaves later document types cheap *if* ever justified, so "no" now costs nothing |
| R6 | **Editorial quality** of 32+ privacy scaffolds; machine-assisted translations shipping unreviewed | Medium / High | EN scaffolds first with real review; non-EN jurisdictions/languages ship only when reviewed — the fallback chain makes partial shipping safe and invisible-to-broken; per-language rollout tracked in CHANGELOG |
| R7 | **Consent-revision misuse** (P1a): operator bumps revision casually, causing mass re-prompt fatigue | Low / Medium | Explicit confirm dialog with stated consequence; "minor change" path is the visually primary action; never automatic |
| R8 | **readme.txt changelog cap** (~5,000 words, 13 entries kept) squeezed by two feature-heavy releases | High / Low | Terse entries (≤300 words), full detail in CHANGELOG.md + GitHub; run the standing `awk … \| wc -w` word-count check before SVN |
| R9 | Admin JS page bloat / boot-flag mismatch (the `fazCpBooted` watchdog pattern) | Low / Low | Mirror the existing per-page JS pattern exactly; boot-flag entry added in the same commit as the page registration |

---

## 8. Release plan

| Version | Ships | Notes |
|---|---|---|
| **1.26.0** | P0a Privacy Policy (engine generalisation, registry, admin page, REST, block, EN + reviewed translations for GDPR at minimum; other jurisdictions/languages as review completes) + **P2a** Organization data + **P0b** footer legal links | The big one. Feature-flag nothing — the new surfaces are inert until an admin configures them, which is the safest flag there is |
| **1.26.x** | Editorial-only point releases: remaining privacy-policy translations/jurisdictions as they pass review | Template-file + catalogue additions; no code churn; hash bumps only for affected combos (ledger not yet shipped, so no prompt implications) |
| **1.27.0** | P1a version↔revision link + P1b Terms & Conditions + Disclaimer | P1a ships after the documents exist so the ledger covers them all from day one |
| — | P2b, P3 | Not scheduled (rejected) |

**Per-release mechanics** (per `release.md`, followed exactly as the standing rule requires): version bump script, `wp i18n make-pot` + msgmerge + it_IT + msgfmt as the single final resync, `npm run build:min` only if frontend JS changed (P0b/P1a touch **no** frontend JS — server-rendered + existing revision plumbing — so likely skippable, verify per diff), two-ZIP build (wp.org variant sans `run-scan.php`), Plugin Check 0 ERRORS, Playground test before SVN, README.md changelog update (standing rule), readme.txt changelog trimmed to 13 entries under the word cap.

**readme.txt additions:** new shortcodes documented in the existing Shortcodes section; new FAQ entries ("Does FAZ generate a Privacy Policy?", "Why won't my Privacy Policy render?" → required fields, "Does FAZ verify visitor age?" → §P2b answer); Screenshots +1 (Legal Documents page); **External Services section unchanged** — this feature performs zero outbound HTTP.

---

## 9. Explicit non-goals

Committed rejections, with the competitor patterns we refuse:

1. **No cloud, ever.** No remote template fetching (their `WPLEGAL_API_URL` pattern), no site-URL-reporting scanner (their `app.wplegalpages.com` pattern), no SaaS dashboard, no account/API-key. Every scaffold ships in the ZIP; generation works with the server offline. This is a selling point stated in the readme, not just an internal rule.
2. **No paywalls.** Geo-targeting, TCF, A/B testing, all languages, all document types remain free. No "pro" nag surfaces.
3. **No DMCA / COPPA / Refund / EULA generators.** DMCA and COPPA are narrow US-counsel territory where a template does more harm than good; Refund policy is commerce tooling (WooCommerce's domain); EULA serves software vendors, not this plugin's users. The registry makes any of them cheap to add *if* real demand with real legal review appears; none is planned.
4. **No age-verification content gate** (§P2b), **no force-agreement mechanism**, **no affiliate disclosure**, **no announcement banner** (§P3). Force agreement in particular is one step from a cookie wall; the UI will never place terms-acceptance controls anywhere near cookie consent.
5. **No automatic consent re-prompting** from hash drift — the operator decides materiality, always (§P1a).
6. **No third-party document-type registration filter in v1** (§3.1), and **no nav/UI consolidation of the existing Cookie Policy page** in this programme (§3.4) — both deferred deliberately to keep the regression surface of a shipped, hardened editor at zero.
7. **No new Composer/npm runtime dependencies** — the lean markdown subset stays; no Parsedown.

---

## Appendix — verification notes

Claims in this plan verified directly against the code on 2026-07-31 (branch `feat/legal-documents-generator`): pipeline order in `Renderer::render()` (gettext → overrides → substitute → markdown → hash) ✔; `Section_Overrides` settings-scoped and whitespace-preserving on no-op ✔; `Template_Translations` placeholder-parity + section-count guards ✔; `policy_languages()` merging the Languages-module catalogue ✔; POPIA-only `missing_required_settings()` ✔; `invalidate_consents()` at `admin/modules/settings/api/class-api.php` ✔; `wp_footer` hook at `frontend/class-frontend.php:142` ✔; `_consentRevision` consumed at `frontend/js/script.js:343` ✔; templates 4 jurisdictions × 8 languages on disk ✔; `faz/cookie-policy` block delegating to the legacy shortcode ✔; no existing `faz_privacy_policy*` / `faz_terms*` / `faz_disclaimer*` shortcodes ✔; gettext catalogue + PO-fragment generation scripts in `scripts/` ✔.
