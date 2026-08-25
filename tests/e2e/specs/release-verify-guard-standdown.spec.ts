/**
 * Release verification — the outgoing Set-Cookie guard stands down wherever
 * a visitor cannot consent.
 *
 * What was broken: `server_cookie_guard_enabled()` omitted the checks its two
 * sibling layers performed. With `banner_control.status = false` (or geo
 * no-banner routing) no page ever shows a banner, so the visitor can never
 * record consent — yet the guard stripped their Set-Cookie headers
 * permanently. Under Cache Compatibility Mode `get_blocked_categories()`
 * returns every non-necessary slug WITHOUT reading the consent cookie, so a
 * visitor who HAD consented lost every classifiable cookie anyway.
 *
 * The claimed fix (frontend/class-frontend.php): ONE gate —
 * `server_cookie_guard_enabled()` → `server_cookie_guard_has_consent_context()`
 * — consulted by the output buffer, the REST/redirect transport boundaries AND
 * `shred_non_consented_cookies()`. It stands down on cache-compat and on any
 * no-consent-context page, while REST/admin-ajax coverage survives because the
 * non-front-end branch re-evaluates `is_banner_disabled_by_settings()` and
 * `is_geo_banner_disabled()` instead of gating on the structurally-null
 * `$this->template`.
 *
 * These tests exercise the INSTALLED release through HTTP only. The observable
 * surface is the `faz-e2e-scan-lab` fixture plugin's admin-ajax endpoint
 * (`action=faz_e2e_scan_ajax_cookie`), which emits `brikpanel_vid` — a cookie
 * the plugin's built-in Cookie_Database classifies as `analytics`, i.e. one
 * the guard is both able and (pre-consent, opt-in law) obliged to strip —
 * or, with `&necessary=1`, `wp_woocommerce_session_fazlab` which classifies
 * as necessary and must always pass. The JSON body echoes the cookie name the
 * handler emitted, so every "the header survives / is stripped" assertion is
 * anchored to proof that the handler really tried to set that exact cookie.
 *
 * Tests are independent: each snapshots the settings it mutates, applies its
 * configuration inside try, and restores in finally with a status-checked
 * (soft) assertion, per the discipline established in scan-catalog-deep 06d.
 */
import type { Browser, Page } from '@playwright/test';
import { expect, test } from '../fixtures/wp-fixture';
import { fazApiGet, fazApiPost, findCategoryId, openSettingsPage } from '../utils/faz-api';
import { ensureFixturePlugin, listActivePluginFiles, restoreActivePluginFiles, wpEval, WP_PATH } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';

type FazSettings = Record<string, any>;

/**
 * Read the current settings and refuse to treat a WP_Error body as a
 * snapshot. Both failure modes are otherwise invisible from the assertions
 * that follow: a failed read means the finally block would write an error
 * shape back over the real settings, corrupting global state for every spec
 * that runs after this one.
 */
async function snapshotSettings(page: Page, nonce: string): Promise<FazSettings> {
  const read = await fazApiGet<FazSettings>(page, nonce, 'settings');
  expect(read.status, 'settings GET must succeed before this test may mutate anything').toBe(200);
  const settings = read.data;
  expect(settings, 'settings GET returned no body').toBeTruthy();
  expect(typeof settings).toBe('object');
  expect(settings.code, 'settings GET returned a WP_Error, not a settings snapshot').toBeUndefined();
  return settings;
}

/** Apply a partial settings payload; a silent write failure would let every standdown assertion pass on a guard that simply never ran. */
async function applySettings(page: Page, nonce: string, payload: Record<string, unknown>): Promise<void> {
  const applied = await fazApiPost(page, nonce, 'settings', payload);
  expect(applied.status, 'the test configuration was not applied — the assertion below would be meaningless').toBe(200);
}

/**
 * Restore the two top-level groups every test in this file may touch.
 * Soft on purpose: a hard expect() thrown from finally would replace the real
 * failure from the try block with a restore failure and hide it.
 */
async function restoreSettings(page: Page, nonce: string, original: FazSettings): Promise<void> {
  const restored = await fazApiPost(page, nonce, 'settings', {
    script_blocking: original.script_blocking,
    banner_control: original.banner_control,
  });
  expect.soft(restored.status, 'settings were NOT restored — later specs run against a mutated site').toBe(200);
}

/** Consent-cookie value a real accept-all visitor would carry, valid for the site's current consent revision. */
function acceptAllConsentValue(original: FazSettings): string {
  const rev = Math.max(1, Number(original.general?.consent_revision ?? 1) || 1);
  return `action:yes,necessary:yes,functional:yes,analytics:yes,performance:yes,marketing:yes,other:yes,rev:${rev}`;
}

type AjaxProbe = { setCookie: string; emittedCookieName: string };

/**
 * Hit the fixture admin-ajax endpoint as an anonymous visitor (fresh browser
 * context — the admin session used for settings writes must not leak into the
 * request under test) and report the raw Set-Cookie header alongside the name
 * the handler says it emitted. Asserting on the echoed name is what makes the
 * negative header assertions capable of failing: if the fixture plugin were
 * missing or the handler changed, the probe fails here instead of the strip
 * assertion passing vacuously on an empty response.
 */
async function probeAjaxCookie(
  browser: Browser,
  opts: { necessary?: boolean; consentCookie?: string; disableGeoRuntime?: boolean } = {},
): Promise<AjaxProbe> {
  const ctx = await browser.newContext();
  try {
    if (opts.consentCookie) {
      await ctx.addCookies([
        {
          name: 'fazcookie-consent',
          value: encodeURIComponent(opts.consentCookie),
          url: WP_BASE,
          sameSite: 'Lax',
          expires: Math.floor(Date.now() / 1000) + 3600,
        },
      ]);
    }
    const query = new URLSearchParams({ action: 'faz_e2e_scan_ajax_cookie' });
    if (opts.necessary) query.set('necessary', '1');
    if (opts.disableGeoRuntime) query.set('faz_e2e_disable_geo_runtime', '1');
    const res = await ctx.request.get(`${WP_BASE}/wp-admin/admin-ajax.php?${query.toString()}`);
    expect(res.status(), 'admin-ajax fixture endpoint must answer 200 — anything else means faz-e2e-scan-lab is not active').toBe(200);
    const body = await res.json();
    expect(body?.success, 'fixture AJAX handler must report success').toBe(true);
    return {
      setCookie: res.headers()['set-cookie'] ?? '',
      emittedCookieName: String(body?.data?.cookie ?? ''),
    };
  } finally {
    await ctx.close();
  }
}

test.describe('Release verify — Set-Cookie guard standdown (one gate, no orphaned strips)', () => {
  let initialActivePluginFiles: string[] | null = null;
  // Catalogue row id for the probe cookie, seeded by the control test below.
  let probeRowId = 0;

  test.beforeAll(() => {
    // The observable surface is the scan-lab fixture plugin. With WP_PATH we
    // deploy + activate it ourselves and restore the original active set
    // afterwards; without WP_PATH we rely on it already being active (every
    // probe hard-fails with an explanatory message if it is not).
    if (!WP_PATH) return;
    initialActivePluginFiles = listActivePluginFiles();
    ensureFixturePlugin('faz-e2e-scan-lab');
  });

  test.afterAll(() => {
    if (initialActivePluginFiles) {
      restoreActivePluginFiles(initialActivePluginFiles);
    }
    // Remove the seeded probe row so this file leaves the catalogue as it
    // found it — a leftover row is what made the ordering coupling invisible
    // in the first place.
    if (probeRowId && WP_PATH) {
      wpEval(`
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'faz_cookies', array( 'name' => 'brikpanel_vid' ), array( '%s' ) );
        do_action( 'faz_after_delete_cookie' );
      `);
    }
  });

  // ── The control. Without this, every standdown test below could pass on a
  // build whose guard never runs at all (e.g. the opt-in flag being ignored,
  // or the buffer never opening on admin-ajax). It also pins the property the
  // guard uniquely provides over the shredder: enforcement on admin-ajax
  // subrequests, where `$this->template` is structurally null and a naive
  // `! $this->template` consent-context check would have deleted the feature.
  test('control: on a normally-configured site the opted-in guard still strips an unconsented visitor\'s analytics Set-Cookie on admin-ajax', async ({ page, browser, loginAsAdmin }) => {
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await snapshotSettings(page, nonce);
    try {
      await applySettings(page, nonce, {
        script_blocking: { ...(original.script_blocking ?? {}), block_server_cookies: true },
        // Pin the consent context explicitly so this control is self-contained
        // rather than inheriting whatever a previous spec left behind.
        banner_control: { ...(original.banner_control ?? {}), status: true, cache_compatibility: false },
      });

      // brikpanel_vid is enforceable ONLY through the admin catalogue: it is
      // not in known-providers.json, and the enforcement helper deliberately
      // does not consult Cookie_Database / the Open Cookie Database. With no
      // row the cookie is unknown, unknown fails permissive, and the guard
      // correctly strips nothing — so this control would fail for a reason
      // that has nothing to do with the guard. It used to pass only because an
      // earlier scan spec happened to leave the row behind, which made this
      // whole file silently order-dependent. Seed it here instead.
      if (!probeRowId) {
        const analyticsId = await findCategoryId(page, nonce, 'analytics');
        const created = await fazApiPost<Record<string, unknown>>(page, nonce, 'cookies', {
          name: 'brikpanel_vid',
          slug: 'brikpanel_vid',
          domain: new URL(WP_BASE).hostname,
          category: analyticsId,
          duration: { en: '1 year' },
          description: { en: 'guard-standdown probe row' },
        });
        expect([200, 201], `probe catalogue row was not created (status ${created.status})`).toContain(created.status);
        probeRowId = Number((created.data as Record<string, unknown>)?.id ?? 0);
        expect(probeRowId, 'probe catalogue row returned no id').toBeGreaterThan(0);
      }

      const probe = await probeAjaxCookie(browser);
      // Positive anchor: the handler really emitted brikpanel_vid this request…
      expect(probe.emittedCookieName).toBe('brikpanel_vid');
      // …and the guard removed it from the transport before the response left PHP.
      expect(probe.setCookie, 'guard did not strip the classifiable cookie — the standdown tests below prove nothing against this build').not.toContain('brikpanel_vid=');
    } finally {
      await restoreSettings(page, nonce, original);
    }
  });

  // ── Targeting, not blanket. The same actively-enforcing configuration must
  // pass a necessary-classified cookie untouched: a guard that goes blanket
  // (header_remove without re-adding the kept lines) breaks carts and logins,
  // and this is the assertion that goes red for that failure mode.
  test('a necessary cookie survives the very configuration under which the guard is actively stripping', async ({ page, browser, loginAsAdmin }) => {
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await snapshotSettings(page, nonce);
    try {
      await applySettings(page, nonce, {
        script_blocking: { ...(original.script_blocking ?? {}), block_server_cookies: true },
        banner_control: { ...(original.banner_control ?? {}), status: true, cache_compatibility: false },
      });

      const necessary = await probeAjaxCookie(browser, { necessary: true });
      expect(necessary.emittedCookieName).toBe('wp_woocommerce_session_fazlab');
      expect(necessary.setCookie, 'guard destroyed a necessary session cookie — enforcement has gone blanket').toContain('wp_woocommerce_session_fazlab=');

      // Same configuration, same endpoint, non-necessary variant: still
      // stripped. Proves the survival above is classification, not standdown.
      const tracked = await probeAjaxCookie(browser);
      expect(tracked.emittedCookieName).toBe('brikpanel_vid');
      expect(tracked.setCookie).not.toContain('brikpanel_vid=');
    } finally {
      await restoreSettings(page, nonce, original);
    }
  });

  // ── The headline standdown. banner_control.status = false shows NO banner
  // on ANY page: the visitor has no script.js and no way to ever record
  // consent, so a stripped Set-Cookie would be permanent with no remedy. The
  // guard must not run for them — even though the operator opted into it, and
  // even on the admin-ajax path where the fix re-evaluates the banner setting
  // instead of leaning on the (structurally null) template.
  test('with the banner switched off site-wide no visitor can ever consent — the guard stands down and the third-party Set-Cookie survives', async ({ page, browser, loginAsAdmin }) => {
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await snapshotSettings(page, nonce);
    try {
      await applySettings(page, nonce, {
        script_blocking: { ...(original.script_blocking ?? {}), block_server_cookies: true },
        banner_control: { ...(original.banner_control ?? {}), status: false, cache_compatibility: false },
      });

      const probe = await probeAjaxCookie(browser);
      expect(probe.emittedCookieName).toBe('brikpanel_vid');
      expect(probe.setCookie, 'guard stripped a cookie from a visitor who is shown no banner anywhere — permanent, remediless enforcement').toContain('brikpanel_vid=private-visitor-id');
    } finally {
      await restoreSettings(page, nonce, original);
    }
  });

  // ── The cache-compat regression, sharpest form. Under Cache Compatibility
  // Mode get_blocked_categories() returns every non-necessary slug WITHOUT
  // reading the consent cookie. The broken build therefore stripped the
  // analytics cookie of a visitor who HAD accepted analytics. The fixed gate
  // stands the guard down entirely under cache-compat, so consent stays
  // enforced client-side only (reversible) and this header survives.
  test('Cache Compatibility Mode: a visitor who HAS consented to analytics keeps their analytics Set-Cookie', async ({ page, browser, loginAsAdmin }) => {
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await snapshotSettings(page, nonce);
    try {
      await applySettings(page, nonce, {
        script_blocking: { ...(original.script_blocking ?? {}), block_server_cookies: true },
        banner_control: { ...(original.banner_control ?? {}), status: true, cache_compatibility: true },
      });

      const probe = await probeAjaxCookie(browser, {
        consentCookie: acceptAllConsentValue(original),
        disableGeoRuntime: true,
      });
      expect(probe.emittedCookieName).toBe('brikpanel_vid');
      expect(probe.setCookie, 'guard destroyed a CONSENTED visitor\'s cookie under cache-compat — get_blocked_categories() ignores the consent cookie there, so the guard must not run at all').toContain('brikpanel_vid=private-visitor-id');
    } finally {
      await restoreSettings(page, nonce, original);
    }
  });

  // ── Cache-compat standdown must be unconditional, not consent-dependent. A
  // "fix" that merely started reading the consent cookie under cache-compat
  // would pass the test above and fail here: pre-consent the visitor-invariant
  // cached render can't know this visitor, so destructive server-side
  // enforcement is off entirely and only the reversible client-side layer acts.
  test('Cache Compatibility Mode: the guard stands down even pre-consent — destructive enforcement never keys off the invariant render', async ({ page, browser, loginAsAdmin }) => {
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await snapshotSettings(page, nonce);
    try {
      await applySettings(page, nonce, {
        script_blocking: { ...(original.script_blocking ?? {}), block_server_cookies: true },
        banner_control: { ...(original.banner_control ?? {}), status: true, cache_compatibility: true },
      });

      const probe = await probeAjaxCookie(browser, { disableGeoRuntime: true }); // no consent cookie at all
      expect(probe.emittedCookieName).toBe('brikpanel_vid');
      expect(probe.setCookie, 'guard ran under cache-compat — the one gate is not consulting is_cache_compatibility_enabled()').toContain('brikpanel_vid=private-visitor-id');
    } finally {
      await restoreSettings(page, nonce, original);
    }
  });

  // ── Consent is honoured where the guard DOES run. On a normal configuration
  // the active guard must read the visitor's consent cookie and pass cookies
  // of accepted categories. Red here means the guard enforces the pre-consent
  // block list against everyone — i.e. the cache-compat "never reads consent"
  // defect leaking into the normal path — which would also mean the standdown
  // tests above were passing for the wrong reason.
  test('where the guard runs, it obeys the consent cookie: a consented visitor keeps their analytics Set-Cookie on a normal configuration', async ({ page, browser, loginAsAdmin }) => {
    const nonce = await openSettingsPage(page, loginAsAdmin);
    const original = await snapshotSettings(page, nonce);
    try {
      await applySettings(page, nonce, {
        script_blocking: { ...(original.script_blocking ?? {}), block_server_cookies: true },
        banner_control: { ...(original.banner_control ?? {}), status: true, cache_compatibility: false },
      });

      const probe = await probeAjaxCookie(browser, { consentCookie: acceptAllConsentValue(original) });
      expect(probe.emittedCookieName).toBe('brikpanel_vid');
      expect(probe.setCookie, 'guard stripped an accepted-category cookie — it is not reading the consent cookie').toContain('brikpanel_vid=private-visitor-id');
    } finally {
      await restoreSettings(page, nonce, original);
    }
  });
});
