/**
 * WordPress Playground compatibility smoke test.
 *
 * Tests that the wp.org-published version of faz-cookie-manager installs and
 * boots cleanly in WordPress Playground (PHP WASM in the browser). Catches the
 * class of regression that hit 1.13.13 / 1.13.14 — a `wp_salt()` call inside
 * a controller constructor that fataled because Playground loads plugins
 * before `pluggable.php`.
 *
 * The test is OPT-IN: it requires network access to playground.wordpress.net
 * and the wp.org CDN, the WASM bootstrap takes ~30s on a cold start, and it
 * reads the version published to wp.org (not the current branch). It is
 * therefore gated behind `RUN_PLAYGROUND_TEST=1` so the default suite stays
 * deterministic and fast.
 *
 * When does it actually catch a regression?
 *
 *   - **Post-release**: after `scripts/svn-release.sh` ships a new version
 *     to wp.org, run this test to confirm the public ZIP boots on Playground
 *     before announcing the release. The 1.13.13/14 crashes shipped to wp.org
 *     because this step was skipped — release.md §5b promotes it from
 *     "recommended" to "mandatory" but it was still manual.
 *
 *   - **Pre-release**: this test does NOT exercise the current branch
 *     (Playground pulls from wp.org SVN, which lags HEAD). For pre-release
 *     coverage, see the static-analysis Playground boot-order test inside
 *     `plugin-lifecycle.spec.ts` — it greps PHP source for the unguarded
 *     wp_salt() / __construct table-create patterns and runs in every CI
 *     pass.
 *
 * To run locally:
 *
 *   RUN_PLAYGROUND_TEST=1 npm run test:e2e -- playground-compat.spec.ts
 *
 * Expected runtime: 90-120s for the single test (WASM cold-start dominates).
 */

import { test, expect } from '@playwright/test';

const SHOULD_RUN = process.env.RUN_PLAYGROUND_TEST === '1';

// Blueprint: install faz-cookie-manager from wp.org, PHP 8.3, latest WP, auto-login as admin.
// WHAT VERSION THIS ACTUALLY TESTS — read before trusting a green run.
//
// The blueprint installs the plugin by its wordpress.org SLUG, so Playground
// downloads whatever the directory is serving at that moment. Run before the
// SVN commit — which is how release.md §5b used to order it — this validates
// the PREVIOUS release and cannot say anything about the candidate. It is a
// post-publish smoke test of the artefact wp.org is really handing to users,
// and §5b now says so.
//
// Making it test the candidate would need the release ZIP reachable at a public
// URL for Playground to fetch; a local file is not addressable from the WASM
// runtime. Until that exists, ordering is the honest fix rather than pretending
// the gate covers something it cannot see.
//
// Same blueprint as documented in release.md §5b. Decoded shape:
//   {
//     "plugins": ["faz-cookie-manager"],
//     "steps": [],
//     "preferredVersions": { "php": "8.3", "wp": "latest" },
//     "features": {},
//     "login": true
//   }
const PLAYGROUND_URL =
  'https://playground.wordpress.net/?plugin=faz-cookie-manager' +
  '#ewogICJwbHVnaW5zIjogWwogICAgImZhei1jb29raWUtbWFuYWdlciIKICBdLAogICJzdGVwcyI6IFtdLAogICJwcmVmZXJyZWRWZXJzaW9ucyI6IHsKICAgICJwaHAiOiAiOC4zIiwKICAgICJ3cCI6ICJsYXRlc3QiCiAgfSwKICAiZmVhdHVyZXMiOiB7fSwKICAibG9naW4iOiB0cnVlCn0=';

test.describe('Playground compatibility (online — RUN_PLAYGROUND_TEST=1 to enable)', () => {
  test.skip(!SHOULD_RUN, 'opt-in only: set RUN_PLAYGROUND_TEST=1 to run');

  // WASM cold-start can run past Playwright's default 30s test timeout.
  test.setTimeout(180_000);

  test('faz-cookie-manager activates on Playground without fatal errors and renders the admin dashboard', async ({ page }) => {
    // Capture console errors so a `wp_salt()` undefined fatal would surface
    // here rather than just stalling the page silently.
    const consoleErrors: string[] = [];
    page.on('pageerror', (err) => { consoleErrors.push(String(err)); });
    page.on('console', (msg) => {
      if (msg.type() === 'error') consoleErrors.push(msg.text());
    });

    await page.goto(PLAYGROUND_URL, { waitUntil: 'domcontentloaded', timeout: 60_000 });

    // Playground renders its UI in nested iframes — the actual WordPress
    // admin lands inside `wp-playground` → an inner iframe with the WP UI.
    // The blueprint's `login: true` auto-logs us in as admin, so once
    // bootstrap finishes we should see the WP admin chrome (#wpadminbar).
    //
    // The bootstrap can take 30-90s on a cold start (download PHP WASM,
    // install WP, install + activate the plugin). Poll with a generous
    // budget rather than a fixed wait.
    const adminFrame = page.frameLocator('iframe').last();
    await expect(adminFrame.locator('#wpadminbar')).toBeVisible({ timeout: 120_000 });

    // Navigate to the FAZ Cookie Manager admin page inside the Playground
    // iframe. If the plugin failed to activate (the 1.13.13/14 shape), this
    // page would either 404 or render WP's "plugin caused an error" notice.
    // The plugin's own consent banner renders over the page and is a dialog, so
    // it intercepts pointer events aimed at the admin menu behind it. Dismiss it
    // first: it is the subject under test, not an obstacle to route around.
    const consentReject = adminFrame.locator('button:has-text("Reject All")').first();
    if (await consentReject.isVisible().catch(() => false)) {
      await consentReject.click();
      await adminFrame.locator('[data-faz-tag="notice"]').waitFor({ state: 'hidden', timeout: 10_000 }).catch(() => {});
    }

    // No swallowing catch here. A click that cannot land is the single most
    // useful signal this test produces — an intercepted click used to be
    // discarded silently, and the run then died 60s later on a heading that was
    // never going to appear, reporting a timeout instead of the interception.
    await adminFrame.locator('a[href*="page=faz-cookie-manager"]').first().click();
    // Wait for the FAZ dashboard heading or the standard wp-admin error
    // notice that appears when a plugin fatals on activation.
    const dashboardHeading = adminFrame.locator('h1:has-text("FAZ Cookie Manager"), h1:has-text("Cookie Manager")').first();
    const pluginErrorNotice = adminFrame.locator('text=/plugin (caused|could not be activated)/i').first();

    // Race the two — whichever appears first tells us what state Playground
    // is in.
    const winner = await Promise.race([
      dashboardHeading.waitFor({ state: 'visible', timeout: 60_000 }).then(() => 'dashboard'),
      pluginErrorNotice.waitFor({ state: 'visible', timeout: 60_000 }).then(() => 'plugin-error'),
    ]).catch(() => 'timeout');

    expect(winner, 'Playground must render the FAZ admin dashboard, not a plugin-error notice').toBe('dashboard');

    // Final invariant: no page-level JavaScript errors and no "Fatal error"
    // text in the Playground document body. The wp_salt() bug surfaced as
    // a PHP fatal printed inline by WP's error handler.
    const bodyText = await page.locator('body').textContent();
    expect(bodyText ?? '', 'Playground body must not contain a PHP fatal').not.toMatch(/Fatal error.*wp_salt/i);
    expect(consoleErrors.filter((e) => /fatal|undefined function|uncaught/i.test(e)), 'no console-level fatals').toEqual([]);
  });
});
