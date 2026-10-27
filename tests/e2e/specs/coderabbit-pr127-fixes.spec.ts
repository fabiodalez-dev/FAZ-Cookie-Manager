/**
 * Regression tests for the CodeRabbit review findings on PR #127.
 *
 * Each test pins one fix so a future change that reintroduces the bug fails
 * loudly:
 *   #1 cookie-policy.js  — failed `settings` GET must keep Auto-detect disabled
 *                          and block the form submit (no overwrite-with-defaults).
 *   #2 gvl.js            — failed `gvl/selected` GET must keep Auto-detect
 *                          disabled and block Save (no wipe of saved vendors).
 *   #4 cookie-policy.php — the Auto-detect control ships `disabled` with a
 *                          server-rendered hydrating message in its live region.
 *   #6 script.js         — the consent "saved" announcement fires on every
 *                          consent path (close button + accept), not just the
 *                          accept/reject buttons.
 *   #7 script.js         — cross-domain consent forwarding resolves a
 *                          protocol-relative / relative target against the page
 *                          URL instead of throwing and dropping it.
 *
 * (#3/#8 — dropping the post-exact-match `continue` — is defensive: the bundled
 * domain maps currently contain no overlapping parent/child keys, so there is
 * no data that exercises it end-to-end. Covered by code review, not here.)
 */
import { test, expect } from '../fixtures/wp-fixture';

const CP_PAGE = '/wp-admin/admin.php?page=faz-cookie-manager-cookie-policy';
const GVL_PAGE = '/wp-admin/admin.php?page=faz-cookie-manager-gvl';

// The "Third-party services" block on the cookie-policy page lives inside a
// collapsed <details>, so its Auto-detect button is hidden until expanded.
// Open it programmatically before asserting on the button.
async function expandCpServices(page: import('@playwright/test').Page) {
  await page.locator('details:has(#cp-services-auto-detect)').evaluate((d) => {
    (d as HTMLDetailsElement).open = true;
  });
}

test.describe('CodeRabbit PR #127 fixes', () => {
  // ── #1 cookie-policy.js hydration guard ──────────────────────────────────

  test('1. CP: a failed settings load keeps Auto-detect disabled', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    // Fail the settings GET; let everything else through.
    await page.route('**/faz/v1/cookie-policy/settings**', (route) => {
      if (route.request().method() === 'GET') {
        return route.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"boom"}' });
      }
      return route.continue();
    });
    await page.goto(CP_PAGE, { waitUntil: 'domcontentloaded' });
    await expandCpServices(page);
    const btn = page.locator('#cp-services-auto-detect');
    await expect(btn).toBeVisible();
    // After the load settles the button must remain disabled (hydration failed).
    await page.waitForTimeout(1500);
    await expect(btn).toBeDisabled();
  });

  test('2. CP: a failed settings load blocks the form submit (no POST)', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    let settingsPosted = false;
    await page.route('**/faz/v1/cookie-policy/settings**', (route) => {
      const method = route.request().method();
      if (method === 'GET') {
        return route.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"boom"}' });
      }
      if (method === 'POST') {
        settingsPosted = true; // must never happen — submit is blocked
        return route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' });
      }
      return route.continue();
    });
    await page.goto(CP_PAGE, { waitUntil: 'domcontentloaded' });
    await expandCpServices(page);
    await page.waitForTimeout(1500);
    // Submit the form directly (the Save button lives in the form).
    await page.evaluate(() => {
      const f = document.getElementById('faz-cookie-policy-form') as HTMLFormElement | null;
      if (f) f.requestSubmit ? f.requestSubmit() : f.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    });
    await page.waitForTimeout(800);
    expect(settingsPosted, 'submit must be blocked while settings never hydrated').toBe(false);
  });

  test('3. CP: a successful load enables Auto-detect and clears the hydrating hint', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(CP_PAGE, { waitUntil: 'domcontentloaded' });
    await expandCpServices(page);
    const btn = page.locator('#cp-services-auto-detect');
    await expect(btn).toBeVisible();
    // On the happy path the button becomes enabled…
    await expect(btn).toBeEnabled({ timeout: 10_000 });
    // …and the server-rendered "Loading saved selection…" hint is cleared.
    await expect(page.locator('#cp-services-auto-detect-status')).toHaveText('', { timeout: 10_000 });
  });

  // ── #4 cookie-policy.php server markup ───────────────────────────────────

  test('4. CP: the Auto-detect control ships disabled with a hydrating live message (server HTML)', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    // Fetch the raw admin HTML (no JS executed) via the authenticated context.
    const res = await page.request.get(CP_PAGE);
    const html = await res.text();
    // The button is rendered disabled…
    const btnMatch = html.match(/<button[^>]*id="cp-services-auto-detect"[^>]*>/);
    expect(btnMatch, 'auto-detect button present in server HTML').not.toBeNull();
    expect(btnMatch![0]).toContain('disabled');
    // …and its aria-live status span carries the hydrating message.
    expect(html).toMatch(/id="cp-services-auto-detect-status"[^>]*>\s*Loading saved selection/);
  });

  // ── #2 gvl.js hydration guard ────────────────────────────────────────────

  test('5. GVL: a failed selected-vendors load keeps Auto-detect disabled', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.route('**/faz/v1/gvl/selected**', (route) => {
      if (route.request().method() === 'GET') {
        return route.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"boom"}' });
      }
      return route.continue();
    });
    await page.goto(GVL_PAGE, { waitUntil: 'domcontentloaded' });
    const btn = page.locator('#faz-gvl-auto-detect');
    await expect(btn).toBeVisible();
    await page.waitForTimeout(1500);
    await expect(btn).toBeDisabled();
  });

  test('6. GVL: a failed selected-vendors load blocks Save (no POST)', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    let selectedPosted = false;
    await page.route('**/faz/v1/gvl/selected**', (route) => {
      const method = route.request().method();
      if (method === 'GET') {
        return route.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"boom"}' });
      }
      if (method === 'POST') {
        selectedPosted = true; // must never happen — Save is blocked
        return route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true,"count":0}' });
      }
      return route.continue();
    });
    await page.goto(GVL_PAGE, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await page.locator('#faz-gvl-save').click();
    await page.waitForTimeout(800);
    expect(selectedPosted, 'Save must be blocked while the saved selection never hydrated').toBe(false);
  });

  test('7. GVL: a successful load enables Auto-detect', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(GVL_PAGE, { waitUntil: 'domcontentloaded' });
    const btn = page.locator('#faz-gvl-auto-detect');
    await expect(btn).toBeVisible();
    await expect(btn).toBeEnabled({ timeout: 10_000 });
  });

  // ── #6 consent announcement covers every consent path ────────────────────
  //
  // The fix moved `_fazAnnounceConsent()` out of `_fazAcceptReject` and into the
  // central `_fazAcceptCookies()` (just past the age-gate guard), so EVERY
  // consent-recording path announces — accept, reject, the close button (which
  // calls `_fazAcceptCookies("reject")`) and per-cookie saves. We exercise the
  // reject and accept paths through the public consent fn. `_fazAnnounceConsent`
  // creates the `#faz-a11y-live` region synchronously and sets its text on the
  // next animation frame, so the message is asserted via polling.

  test('8. Frontend: the reject/close path announces the saved outcome', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => typeof (window as any)._fazConfig !== 'undefined', null, { timeout: 10_000 });
    const fired = await page.evaluate(() => {
      if (typeof (window as any)._fazAcceptCookies !== 'function') return 'no-fn';
      (window as any)._fazAcceptCookies('reject'); // same entry point the close button uses
      return document.getElementById('faz-a11y-live') ? 'ok' : 'no-region';
    });
    expect(fired, 'reject must route through _fazAcceptCookies and create the live region').toBe('ok');
    await expect(page.locator('#faz-a11y-live')).not.toHaveText('', { timeout: 3000 });
  });

  test('9. Frontend: accept also announces via the centralized path', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => typeof (window as any)._fazConfig !== 'undefined', null, { timeout: 10_000 });
    const fired = await page.evaluate(() => {
      if (typeof (window as any)._fazAcceptCookies !== 'function') return 'no-fn';
      (window as any)._fazAcceptCookies('all');
      return document.getElementById('faz-a11y-live') ? 'ok' : 'no-region';
    });
    expect(fired, 'accept must route through _fazAcceptCookies and create the live region').toBe('ok');
    await expect(page.locator('#faz-a11y-live')).not.toHaveText('', { timeout: 3000 });
  });

  // ── #7 relative / protocol-relative consent-forwarding target ────────────

  test('10. Frontend: a protocol-relative forwarding target is accepted (origin resolved against the page)', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => typeof (window as any)._fazConfig !== 'undefined', null, { timeout: 10_000 });
    // `_fazStore` is a const alias of `window._fazConfig` captured at load, so
    // mutating `window._fazConfig._consentForwarding` is visible to the message
    // handler (it re-reads `_fazStore._consentForwarding` per message). The
    // target is PROTOCOL-RELATIVE: pre-fix `new URL('//host/..')` without a
    // base threw and the target was silently dropped → message rejected; post-
    // fix it resolves against the page URL, matches event.origin, and the
    // forwarded consent cookie is written (then the handler reloads).
    await page.evaluate(() => {
      (window as any)._fazConfig._consentForwarding = {
        enabled: true,
        targets: ['//' + window.location.host + '/consent-bridge'],
      };
      // action:yes is required by the handler's anti-default guard; necessary:yes
      // keeps it a well-formed consent string.
      window.postMessage(
        { type: 'faz_consent_forward', consent: 'action:yes,necessary:yes' },
        window.location.origin,
      );
    });
    // On acceptance the handler writes `fazcookie-consent` then reloads. Poll the
    // context cookies (survives the reload) rather than the live document.
    await expect
      .poll(async () => (await context.cookies()).some((c) => c.name === 'fazcookie-consent'), {
        timeout: 5000,
      })
      .toBe(true);
  });
});
