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
import type { Frame } from '@playwright/test';

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

/**
 * Click an element from inside its own frame, and fail loudly if something
 * covers it.
 *
 * Playwright's `locator.click()` drives a real mouse at page coordinates, and
 * on Playground the WordPress document sits inside a nested, scoped iframe.
 * Measured against the live site: the Reject All button was visible, enabled,
 * `pointer-events: auto`, unmoved across 800ms, and `elementFromPoint` at its
 * centre returned the button itself — genuinely clickable by every property
 * that matters — while `locator.click()` still burned 26 retries and timed out.
 * The obstacle is the coordinate chain through the nested frames, not the page.
 *
 * So the click is dispatched in-frame, where the coordinates are real. The
 * check the old strict click existed for is not lost: an intercepted click is
 * still refused, and the covering element is named in the failure rather than
 * surfacing 60 seconds later as a timeout on a heading that was never coming.
 */
async function clickInsideFrame(frame: Frame, selector: string, label: string): Promise<void> {
  const outcome = await frame.evaluate((sel) => {
    const el = document.querySelector(sel) as HTMLElement | null;
    if (!el) return { ok: false, reason: 'not present in the DOM' };
    el.scrollIntoView({ block: 'center', inline: 'center' });
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return { ok: false, reason: 'has no layout box' };
    const top = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
    if (!top || (top !== el && !el.contains(top) && !top.contains(el))) {
      const who = top ? `${top.tagName}.${String(top.className).slice(0, 60)}` : 'nothing';
      return { ok: false, reason: `covered by ${who}` };
    }
    el.click();
    return { ok: true, reason: '' };
  }, selector);
  expect(outcome.ok, `${label}: ${outcome.reason}`).toBe(true);
}

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
    // Find the frame that actually holds wp-admin, at whatever depth Playground
    // nests it today. `frameLocator('iframe').last()` only reaches TOP-LEVEL
    // iframes, and Playground currently renders WordPress one level deeper
    // (outer shell iframe -> inner WP iframe). That made this test fail against
    // a Playground where everything worked: the plugin was active, the banner
    // was painted and the admin bar was on screen, one frame below where the
    // assertion was looking. Searching page.frames(), which is flat across all
    // depths, is indifferent to the nesting Playground happens to use.
    const adminFrameHandle = await (async () => {
      const deadline = Date.now() + 120_000;
      while (Date.now() < deadline) {
        for (const frame of page.frames()) {
          const bar = await frame.$('#wpadminbar').catch(() => null);
          if (bar && (await bar.isVisible().catch(() => false))) {
            return frame;
          }
        }
        await page.waitForTimeout(2_000);
      }
      return null;
    })();
    expect(adminFrameHandle, 'no frame ever rendered #wpadminbar').not.toBeNull();
    const adminFrame = adminFrameHandle!;

    // Navigate to the FAZ Cookie Manager admin page inside the Playground
    // iframe. If the plugin failed to activate (the 1.13.13/14 shape), this
    // page would either 404 or render WP's "plugin caused an error" notice.
    // The plugin's own consent banner renders over the page and is a dialog, so
    // it intercepts pointer events aimed at the admin menu behind it. Dismiss it
    // first: it is the subject under test, not an obstacle to route around.
    if (await adminFrame.$('[data-faz-tag="reject-button"]')) {
      await clickInsideFrame(adminFrame, '[data-faz-tag="reject-button"]', 'consent banner Reject All');
      await adminFrame.locator('[data-faz-tag="notice"]').waitFor({ state: 'hidden', timeout: 10_000 }).catch(() => {});
    }

    // `login: true` lands on the FRONT END, not wp-admin — the admin bar is
    // there because we are logged in, and the consent banner dismissed above is
    // itself the proof, since FAZ never renders it inside wp-admin. Looking for
    // the admin menu here found nothing, which is correct: the menu does not
    // exist on the front end. Navigate to the plugin page instead of clicking a
    // link that only exists once we are already there.
    //
    // Playground scopes every site URL (…/scope:some-name/…), and the scope is
    // minted per session, so it has to be read off the frame rather than
    // assumed.
    const frameUrl = new URL(adminFrame.url());
    const scope = frameUrl.pathname.split('/').find((seg) => seg.startsWith('scope:'));
    expect(scope, `no Playground scope in frame URL ${adminFrame.url()}`).toBeTruthy();
    const adminUrl = `${frameUrl.origin}/${scope}/wp-admin/admin.php?page=faz-cookie-manager`;
    await adminFrame.goto(adminUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    // Wait for the FAZ dashboard heading or the standard wp-admin error
    // notice that appears when a plugin fatals on activation.
    // Match the page's own root element, not its <h1>. base.php prints the h1
    // from $faz_page_title, which on this screen is "Dashboard" — so the old
    // `h1:has-text("Cookie Manager")` could never match, and reported a timeout
    // against a Playground where the dashboard had rendered in full ("Quick
    // Links", "Consent Statistics", "Per-Category Acceptance" were all on
    // screen). #faz-dashboard is the container the view itself opens with, so it
    // exists exactly when this page rendered and never when a fatal replaced it.
    const dashboardHeading = adminFrame.locator('#faz-dashboard').first();
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
