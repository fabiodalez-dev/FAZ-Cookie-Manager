# blocking-compliance: granted-category cookie removed by granular shredder

**Status:** pre-existing product bug on `main` (verified against `4022a59`, the 1.26.0 release prep). Not introduced by any open branch.
**Symptom:** `TimeoutError: page.waitForFunction: Timeout 30000ms exceeded` in `waitForCookie`, first hit on the consent matrix `analytics=yes, marketing=no, performance=no, functional=no`.

## What actually happens

`buildConsentCombinations()` enumerates by bitmask, so mask 0 is all-deny (nothing is awaited) and mask 1 is analytics-only. That combination is simply the first one that waits for any cookie.

Instrumenting the real failing test proves that the provider scripts are present, unblocked, and executed. The GA fixture sets `window.__fazProviderMatrixLoaded["ga-monsterinsights"]`, writes `_ga` and `_gid`, and reports its collect hit. `_ga` is then removed, while the one-shot fixture guard prevents a rewrite.

The relevant runtime state is `window.fazcookie._fazConsentStore`, not a property named `_acceptedCategories`. It contains `analytics:yes`; the earlier `_acceptedCategories: null` diagnostic queried a non-existent property.

## Root cause

The test installation has both `per_service_consent` and `per_cookie_consent` enabled. In granular mode `_fazRemoveAllDeadCookies()` intentionally sweeps every category, including accepted ones, so explicit `svc.*:no` and `ck.*:no` overrides can be enforced inside an accepted category.

`_fazRemoveDeadCookies()` correctly checked explicit granular decisions first, but when `_fazGetServiceCookieDecision()` returned `""` (no explicit override) it deleted any present cookie without consulting the category. A service that follows its accepted category normally has no redundant `svc.<id>:yes` token, so its cookies were treated as implicitly denied.

That violates the intended precedence:

1. explicit per-cookie decision (`ck.*`),
2. explicit per-service decision (`svc.*`),
3. category consent fallback.

Stripe survived only because its administrator-enabled payment-gateway cookie patterns were whitelisted. That exemption masked the same missing category fallback for Stripe; it did not make the general behavior correct.

## Fix

`_fazRemoveDeadCookies()` now preserves a cookie when there is no explicit granular denial and its category is accepted. Explicit `ck.*:no` and `svc.*:no` still delete; explicit `svc.*:yes` still preserves inside a denied category.

A jsdom regression test covers all four precedence cases, and the original 16-combination E2E matrix is the end-to-end verification.

## Release assessment

The failure is pre-existing and does not come from the 1.26.0 release branch, but the affected path is shipped product code. It should be fixed in a dedicated change rather than weakening the E2E assertion or increasing its timeout.
