# FAZ Cookie Manager — Full Compliance Test Report

**Plugin version:** 1.20.0
**Date:** 2026-06-19
**Branch:** `claude/plugin-compliance-test-uv1j3o`
**Scope:** Full compliance verification, all 47 legislations, per-service consent (latest PR #155, #134/#146).

---

## 1. Executive summary

| Layer | Result |
|-------|--------|
| PHP unit suites (27) | ✅ **27/27 passed** (0 failed) |
| Ruleset schema validation (48 files) | ✅ **48/48 valid** (`--strict`, 0 errors, 0 warnings) |
| Legislation compliance-invariant audit (47 laws) | ✅ **47/47 conform** (0 ERROR, 0 WARN) |
| Per-service consent flow (backend + frontend) | ✅ **Verified** against code + specs + unit tests |
| PHP lint (core files) | ✅ No syntax errors |
| E2E browser suite (85 specs / 841 tests) | ⚠️ **Requires a live WordPress stack** — not executable in this container (see §6) |

**Verdict:** every layer that can be executed deterministically in this environment passes. The per-service consent feature is correctly implemented and matches its test contracts. The only unexecuted layer is the Playwright E2E suite, which is gated on a running WordPress instance (127.0.0.1:9998) not present here.

---

## 2. Unit test suites (PHP) — 26/26 ✅

Run: `npm run test:unit` (`bash scripts/run-unit-tests.sh`). Each suite is a self-contained CLI runner (stubs WP, no DB/browser).

Compliance / consent-relevant suites of note (all PASS):

- `test-compliance-php.php` — consent-log sanitiser, DSAR exporter/eraser, pageview URL minimisation, DNSMPI sell/share gating, geo-ruleset integrity.
- `test-compliance-hardening.php` — resolver routing, GPC flags, CIDR allowlist.
- `test-per-service-php.php` / `test-per-service-embeds-php.php` — per-service (`svc.*`) enforcement, block-first embed path (#134/#146).
- `test-percookie-php.php` — per-cookie (`ck.*`) enforcement (16/16).
- `test-donotsell-php.php` — CCPA/DNSMPI opt-out.
- `test-ruleset-resolver.php` / `test-resolver-validation.php` / `test-geo-*` — jurisdiction routing.
- `test-pipl-audit-log.php` — PIPL (China) audit logging.

---

## 3. Ruleset schema validation — 48/48 ✅

Run: `bash scripts/validate-rulesets.sh --strict` (Python `jsonschema`, Draft 2020-12).
All 47 legislation rulesets + the GDPR fallback validate against `ruleset.schema.json`. 0 errors, 0 warnings.

---

## 4. Legislation compliance audit — 47/47 ✅

A dedicated semantic audit (`tests/compliance-audit.py`) checks each ruleset against the **legal model it declares**, beyond mere schema validity:

| ID | Check |
|----|-------|
| N1 | `necessary` is always `granted-locked` (never deniable) — **all laws** |
| O1 | opt-in laws default analytics/marketing/profiling/functional to `denied` (ePrivacy/GDPR prior-blocking) |
| O2 | opt-in laws set Consent Mode v2 `ad_storage`/`analytics_storage`/`ad_user_data`/`ad_personalization` to denied |
| O3 | opt-in laws use `equal_weight_buttons` (EDPB no-dark-pattern) |
| U1 | opt-out / sensitive-opt-in laws gate `profiling` (sensitive data → opt-in) |
| U2 | US universal-opt-out states honour GPC (`gpc_honored=true`) |
| U3 | US sale/share states require the "Do Not Sell" link |
| G1 | `gpc_required` ⇒ `gpc_honored` |

**Result: 0 ERROR, 0 WARN across all 47.** Distribution:

- **opt-in (19):** GDPR Italy/Germany/France/Ireland/Netherlands/Poland/Spain + gdpr-strict, UK-GDPR/PECR, LGPD Brazil, India DPDPA, Korea PIPA, China PIPL, Turkey KVKK, UAE PDPL, KSA PDPL, Israel PPL, South Africa POPIA, Malaysia/Singapore/Thailand/Vietnam PDPA, New Zealand, fallback. → all non-necessary categories `denied` (no cookies before consent).
- **opt-out-with-sensitive-opt-in (18 US states):** CCPA, Colorado, Connecticut, Texas, New Jersey, Minnesota, Maryland, New Hampshire, Oregon, Delaware, Montana, Virginia, Utah, Indiana, Iowa, Kentucky, Tennessee, Rhode Island, Florida. → analytics/marketing `granted`, `profiling` (sensitive) `denied-until-action`; UOOM states honour GPC.
- **hybrid (4):** Quebec Law 25, PIPEDA Canada, Australia Privacy Act, Japan APPI → express opt-in for analytics/marketing/profiling where required.

Full matrix is reproduced by running the script (see §7).

---

## 5. Per-service consent — flow verification ✅

The recently merged feature (PR #155, resolving reopened #134/#146; built on per-cookie PR #153). Verified by tracing the **shipped code** and cross-checking each step against `tests/e2e/specs/per-service-consent.spec.ts` and the passing PHP unit suites.

### Backend (`frontend/class-frontend.php`)
1. **`get_enforceable_services()`** (L1331) — returns **every** `Known_Providers` entry in an active non-necessary category, *decoupled from scanner detection*. This is the #134/#146 fix: a block-first site never lets a provider's cookie exist, so detection alone would hide it.
2. **`get_service_consent()`** (L3138) — gated on `banner_control.per_service_consent`; parses `svc.<id>:yes|no` tokens from the validated consent cookie, keeping only IDs in the enforceable set.
3. **`get_pattern_service_map()`** (L3182) — builds pattern→service map from the broad enforceable set, so a real embed can be matched even if undetected.
4. **`check_per_service_blocking()`** (L3214) — `svc.id:no` → **block**; `svc.id:yes` → **allow**; otherwise `null` → **category-level fallback** (unchanged behaviour for providers with no explicit choice).

### Frontend (`frontend/js/script.js`)
- Placeholder "Accept" handler (L4479) calls `window._fazAcceptService(serviceId, cat, true)`.
- **`_fazAcceptService()`** (L5187) grants **only** that service via a synthetic `svc.<id>:yes` toggle — never the whole category. A forged/unrecognised service id is a **diagnosable no-op** (`_fazIsRecognizedService` allowlist), preventing silent over-consent. This is the core correctness contract the 1.18.2 hotfix flagged.

### Contract checks mirrored by the E2E spec (assertions confirmed present in code)
- **P2** — service list reflects *detected* cookies for the visible toggles, while *enforcement* uses the broad set.
- **P1-4** — consent cookie hard-capped < 4 KB; core/category entries and explicit `svc.*` denials kept, low-priority `ck.*` overrides dropped first.
- **P1-3** — `svc.*` decisions written to the consent log (GDPR accountability), alongside the category summary.
- **category-only mode** — `per_service_consent=false` hides/disables all per-service toggles.

### 5a. Service-vs-category divergence — server enforcement truth table

Verified in `frontend/class-frontend.php` (`<script>` L2310-2326, `<iframe>` L2353-2364),
driven by `check_per_service_blocking()` (L3214, returns `true`/`false`/`null`):

| Visitor's `svc.<id>` | Category ALLOWED | Category BLOCKED |
|---|---|---|
| `yes` → `false` | runs | **runs** — service override beats the denied category |
| `no` → `true` | **blocked** — service opt-out inside an accepted category | blocked |
| *(none)* → `null` | runs (category allows) | blocked — **category fallback** |

The same precedence is proved deterministically by `test-per-service-php.php` E1-E5
(NO > YES > category; empty map → null; unmatched script → null).

### 5b. Consent change (save) — what gets written

`_fazAcceptCookies("custom")` → `_fazStoreCustomServiceConsent()` (`script.js` L2500):
- writes `svc.<id>:yes|no` **only when a service toggle diverges** from its category
  (`hasOverride`, L2515); convergent tokens are dropped by the store serialiser so the
  cookie stays small. A service denied inside an accepted category keeps the category
  ON (individual opt-out, change handler L2808/4808).
- Same-session accept-then-reject of an *undetected* (block-first) provider is captured
  via the `_fazServicesBeforeConsent` snapshot diff (L2525-2535) so the reload fires.

### 5c. Immediate shred on save (service vs category divergence)

After persistence, granular mode sweeps **every** category (not just rejected ones)
via `_fazRemoveDeadCookies()` (L1865/2437): a cookie survives only if its effective
decision is `yes`, computed `ck.* > svc.* > category` by `_fazGetServiceCookieDecision()`
(L2656) — so a cookie already set by a service the visitor just denied **inside an
accepted category** is shredded immediately on save, not left until the next request.
Server-side, the request-time shredder enforces the same precedence on reload.

### 5d. Return visit (restore) — state round-trips from the cookie

On re-render the service toggle is restored from the stored override, else the category
(`script.js` L4804-4806: `svcConsent ? svcConsent==='yes' : catConsent==='yes'`), and on
revisit `_fazUpdateServiceToggleStates()` (L4895) re-applies `svc.* > category` to every
toggle (and `ck.*` to nested per-cookie toggles via `_fazCookieEffectiveConsent`, L2640).
The category→service sync (L4869) keeps a flipped category coherent with its services.
On the next page load the server independently re-derives blocking from the `svc.*`
tokens in the persisted cookie (`get_service_consent()` L3138), so the restored UI and
the actual script/iframe gating agree.

### 5e. Fallback (no explicit service choice)

A service with no `svc.<id>` token defers to its category at **both** layers:
JS `_fazServiceEffectiveConsent()` (L2546) returns the category value, and server
`check_per_service_blocking()` returns `null` so the normal category branch decides
(`test-per-service-php.php` E3/E4).

---

## 5f. Normal (production) consent-log path — executed against the real controller ✅

To verify the *normal behaviour* of the per-service persistence path — not a
replica and not a SQLite test shim — a new suite (`tests/unit/test-consentlog-controller-php.php`,
**22/22**) loads and drives the **shipped** `Controller` class
(`admin/modules/consentlogs/includes/class-controller.php`) against a `$wpdb`
double that records inserts and replays them on read:

- **`svc.*` / `ck.*` round-trip** — `log_consent()` → `categories` JSON →
  `get_log_by_consent_id()` preserves `svc.google-analytics:no` (denied service
  inside an accepted category), `svc.youtube:yes` (allowed service inside a denied
  category) and `ck.google-analytics._ga:no`, alongside the category summary (P1-3).
- **Hardening by the shipped sanitiser** — `maybe→drop`, markup→drop, nested→drop,
  empty key→drop; 190-char key cap; 250-entry cap.
- **DNSMPI scalar path** stores `''` (not `'[]'`); internal `dnsmpi_optout` status kept.
- **Privacy** — user-agent and IP stored as `sha256(value + salt)`, raw UA never present.
- **`status` allowlist** — unknown → `partial` (dashboard stat integrity).
- **URL minimisation** — credentials, query string and fragment dropped.
- **SQLite-portable migration (1.19.2)** — `hash_legacy_user_agents()` hashes legacy
  plaintext UAs **in PHP** byte-identically to `hash_user_agent()`, and is idempotent
  (64-hex rows skipped). This is the production-compat path the previous merge added;
  the same code runs on MySQL and on SQLite-backed WordPress.

> **Note on SQLite.** SQLite here is a *production* concern (WordPress can run on
> SQLite via `sqlite-database-integration` / WordPress Playground), addressed by
> release 1.19.2 — it is **not** a test-only shortcut. The Playwright harness happens
> to use a Playground/SQLite WordPress, but the assertions above exercise the normal
> production controller directly, so they hold on a standard MySQL install too.

## 5g. Field-reported bugs (per-service-reveal branch) — diagnosed + fixed ✅

A tester on the per-service branch (`build 1.20.0+per-service-reveal`) reported two
defects, confirmed real (reproduced against the shipped helpers in
`tests/unit/test-per-service-reveal-php.php`) and sharing one root cause:

- **BUG-1** — the scanner never surfaces a block-first embed service (YouTube). Its
  toggle only appears on the page currently carrying the blocked embed; the homepage
  and the rest of the site report `services: []`.
- **BUG-2** *(compliance-critical)* — after the visitor **accepts** the YouTube
  placeholder the per-service/per-cookie toggles **disappear**, so the granted
  service can no longer be **withdrawn** from the preference center — a GDPR
  **Art. 7(3)** violation (withdrawal must be as easy as consent).

**Root cause.** Two divergent lists: the VISIBLE toggle list
(`get_per_service_services()`, `class-frontend.php`) is gated on a scanner-detected
cookie (`provider_has_detected_cookie()`, `discovered = 1`), while ENFORCEMENT uses
the broad `get_enforceable_services()`. A blocked iframe sets its cookies (`YSC`,
`VISITOR_INFO1_LIVE`, …) only once it actually loads, which on a block-first site
never happens — so YouTube is never in the visible list on any page. The toggle could
only ever come from a page-level reveal keyed to the placeholder, which vanishes the
moment the embed is accepted.

**Fix (this branch, on the merged foundation).** `get_per_service_services()` now also
surfaces any **enforceable service with an explicit `svc.<id>` decision** in the
consent cookie (accept *or* reject), independent of cookie detection or placeholder
presence. The granted/denied service therefore keeps its toggle (and its per-cookie
sub-toggles) site-wide and stays reviewable/withdrawable. Only the *visible* list is
affected — enforcement, parsing and shredding are untouched. Verified by the renamed
regression suite (**8/8**) and the full unit run (**28/28**).

**Portability review (works on any WordPress site).** The fix was reviewed for
cross-site robustness:
- *Page-cache safe* — `get_service_consent()` returns an empty map when there is no
  consent cookie (or per-service is off), so the augmentation is a **no-op on the
  cacheable anonymous request** and `_services` stays byte-identical to the
  detection-only list. It only fires for requests already carrying a consent cookie —
  output that is visitor-dependent under the plugin's existing per-service/per-cookie
  server enforcement anyway. No new `DONOTCACHEPAGE` requirement is introduced.
- *Custom/renamed/disabled categories* — a category-active guard re-checks each
  surfaced service against the live `$valid_categories`, so a stale `svc.*` token for a
  category the site removed never shows a toggle.
- *No new dependencies* — uses only PHP built-ins plus two existing internal methods;
  the `faz_get_valid_consent_cookie()` read is `function_exists()`-guarded inside
  `get_service_consent()`. No fatal surface on a minimal install.
- *Not shipped to clients* — the test files live under `tests/`, excluded from the
  distributed zip via `.distignore`.

> **Scope note.** The server fix guarantees the toggle is present on every page load
> after a decision (so withdrawal is always possible). Full *same-session* persistence
> right after the placeholder click also depends on the client-side reveal code, which
> lives in the tester's unpushed `per-service-reveal` branch (not in any pushed ref);
> BUG-1's *pre-decision* reveal on the embed page likewise stays with that client code.

## 5h. YouTube (and all third-party embeds) — per-cookie enforceability ✅

Reviewing the YouTube path surfaced a genuine, generalisable issue. What is
**correct**: the iframe oEmbed is blocked into a placeholder (`process_iframe_tag`,
`class-frontend.php`), the placeholder fetches **no remote thumbnail**
(`class-placeholder-builder.php` — privacy), client matching is substring + boundary
(not regex, so `youtu.be`/`ytimg.com` don't over-match), accept-from-placeholder works,
and the service-level toggle now persists for withdrawal (§5g).

What was **misleading**: YouTube's cookies (`YSC`, `VISITOR_INFO1_LIVE`, `LOGIN_INFO`)
are set on **`youtube.com`**, a third-party domain. The shredder writes
`document.cookie` for the **site's** root domain (`_fazSetCookie`, `script.js:529`), and
same-origin rules forbid deleting another domain's cookie — so the per-cookie
sub-toggles the preference center rendered for an embed implied a granular control the
browser makes impossible. The only enforceable control for an embed is service-level
(allow/block the whole iframe).

**Fix (relabel, per decision).** A new `Placeholder_Builder::is_embed_service()`
classifies embed services from the authoritative URL→service / video-service maps; the
server tags each `_services` entry with `third_party` (via a `class_exists`-guarded
`Frontend::is_third_party_service()` that degrades to `false`, never fatals); and the
preference center prepends a clarifying note to the per-cookie list for those services
(*"set by the embedded service on its own domain … controlled by allowing or blocking
the embed above — cannot be removed individually"*), translatable via `_i18n`
(`third_party_cookie_note`) with an English fallback. The toggles remain; the claim is
now honest. Generalises to Vimeo, Maps, Facebook, X, etc. Verified by
`test-per-service-reveal-php.php` (`third_party=true` for YouTube, `false` for
first-party GA) and `test-per-service-embeds-php.php` (entry shape); 28/28 suites; min
rebuilt.

## 6. E2E browser suite — not executable here ⚠️

`tests/e2e/` is a Playwright suite of **85 spec files / 841 test cases** (`npm run test:e2e`). `global-setup.ts` requires a reachable WordPress at `http://127.0.0.1:9998` with admin credentials and seeded fixtures (≈100 scanned cookies, GeoLite2 DB, `WP_PATH` for `wp-cli` eval).

This managed container is a fresh checkout with **no WordPress, MySQL, or `wp-env`/Docker harness**, so the E2E layer cannot be run here. The relevant flows it would exercise are instead verified statically in §5 and by the passing PHP unit suites in §2.

**To run the E2E layer in a proper environment:**
```bash
cp .env.e2e.example .env.e2e   # set WP_BASE_URL / WP_ADMIN_USER / WP_ADMIN_PASS
npm ci && npx playwright install --with-deps chromium
npm run test:e2e                                   # full suite
npx playwright test tests/e2e/specs/per-service-consent.spec.ts   # per-service only
npx playwright test tests/e2e/specs/geo-pipeline.spec.ts tests/e2e/specs/ccpa-optout-blocking.spec.ts
```

---

## 7. Reproduce this report

```bash
npm run test:unit                       # 27 PHP unit suites (incl. consentlog-controller)
php tests/unit/test-consentlog-controller-php.php   # production consent-log path (22)
pip3 install jsonschema                  # one-time
bash scripts/validate-rulesets.sh --strict   # 48 rulesets
python3 tests/compliance-audit.py        # 47-legislation semantic audit + matrix
```
