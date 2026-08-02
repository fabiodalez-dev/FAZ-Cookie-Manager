# blocking-compliance: "covers every observable category-consent combination" — findings

**Status:** pre-existing on `main` (verified against `4022a59`, the 1.26.0 release prep). Not introduced by any open branch.
**Symptom:** `TimeoutError: page.waitForFunction: Timeout 30000ms exceeded` in `waitForCookie`, first hit on the consent matrix `analytics=yes, marketing=no, performance=no, functional=no`.

## It is not a consent-blocking bug

The obvious reading — "analytics was granted and the plugin still blocked the script" — is wrong, and it matters because that reading would be a release blocker.

`buildConsentCombinations()` enumerates by bitmask, so mask 0 is all-deny (nothing is awaited) and mask 1 is analytics-only. That combination is simply **the first one that waits for any cookie at all**. The failure is not specific to analytics; it is the first assertion the test makes.

Direct observation of the page under that consent state shows the provider scripts are **not present in the document**. Not blocked — absent. Only Stripe ran:

```
scripts on page : no ga-monsterinsights, no facebook-pixel, no custom providers
document.cookie : __stripe_mid=1; __stripe_sid=1
fixture hits    : {"stripe":1}
```

`_ga` never appears because nothing on the page would ever set it.

## Where it comes from

`faz-e2e-provider-matrix.php::render_matrix()`:

```php
if ( $this->is_matrix_page() ) {
    $this->print_active_scripts();      // GA, Facebook, …
    …
    return;
}
if ( 'yes' === get_option( self::WOO_OPTION, 'no' ) && $this->is_wc_checkout_or_cart() ) {
    $this->print_script_src( … stripe … );
}
```

Observing only Stripe means `is_matrix_page()` evaluated **false** and control fell through to the WooCommerce branch. With no providers printed, every `waitForCookie()` in the matrix necessarily times out.

Corroborating evidence inside the same spec: a sibling test carries
`test.skip(IS_PHP_BUILT_IN_E2E, 'Fixture page is_singular() is unreliable on the PHP built-in server.')`. The fixture's page detection is already known to be fragile; this is the same family.

## What still needs establishing

- Whether `is_matrix_page()` fails because the stored matrix-page URL/ID is stale (page recreated by another spec, option not refreshed) or because the `is_singular()` check itself misfires under nginx.
- Why `provider-matrix.spec.ts` passes 15/15 against the same fixture. Its setup path differs — that difference is the shortest route to the cause.

Note the observation above came from a reproduction that did **not** replicate this spec's full `beforeAll` (`ensureProviderMatrixPage()`, `deactivatePluginsExcept()`), so it demonstrates the failure mode rather than proving the exact trigger in the real run. The conclusion it supports — absent scripts, not blocked scripts — holds either way, because the real run fails on the same missing cookie.

## Why it was not fixed here

It is a fixture/test-infrastructure defect with no product impact: no shipped code path depends on `is_matrix_page()`. Folding it into a PR about settings sanitisation would have mixed an unrelated investigation into that review.
