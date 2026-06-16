import { expect, test } from '../fixtures/wp-fixture';
import type { BrowserContext, Page } from '@playwright/test';
import { wpEval } from '../utils/wp-env';

/**
 * Edge-case coverage for per-service frontend consent (subsystem: per-service-e2e).
 *
 * These tests drive the REAL shipped script (the minified frontend bundle) with
 * deliberately adversarial inputs:
 *   - per_service_consent ON + scanner-detected provider cookies (_ga→analytics,
 *     _fbp→marketing) seeded in beforeAll so the suite is self-contained.
 *   - a denied service (svc:no) under an ACCEPTED category (the hard edge — the
 *     service override must win over the category).
 *   - an explicit svc:yes under a DENIED category (the inverse edge).
 *   - GPC clearing svc.* before blocking (legal opt-out overrides explicit allow).
 *   - the 4 KB consent-cookie cap dropping ck.* before svc.* before core.
 *   - _fazAcceptService persisting svc.<id>:yes WITHOUT flipping the whole
 *     category, even with no rendered toggle in the DOM.
 *   - per_service OFF → category-only (no service toggles rendered/exposed).
 *
 * Seeding strategy: the per-service list is built server-side from
 * wp_faz_cookies rows with discovered=1 (see class-frontend.php
 * get_detected_cookie_names + provider_has_detected_cookie). We INSERT _ga and
 * _fbp rows, then bust the `faz_detected_cookie_names` transient so the next
 * page load rebuilds the list. State is restored in afterAll.
 */

type FazSettings = Record<string, unknown>;

async function getAdminNonce(page: Page): Promise<string> {
  return page.evaluate(() => window.fazConfig?.api?.nonce ?? '');
}

async function getSettings(page: Page, nonce: string): Promise<FazSettings> {
  const res = await page.request.get('/?rest_route=/faz/v1/settings/', {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(res.status()).toBe(200);
  return (await res.json()) as FazSettings;
}

async function postSettings(page: Page, nonce: string, payload: FazSettings): Promise<void> {
  const res = await page.request.post('/?rest_route=/faz/v1/settings/', {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data: payload,
  });
  expect(res.status(), `settings update status ${res.status()}`).toBe(200);
}

/** Insert a detected provider cookie row (discovered=1) if absent; idempotent. */
function seedDetectedCookie(name: string): void {
  wpEval(
    `global $wpdb;$t=$wpdb->prefix.'faz_cookies';` +
      `$n=${JSON.stringify(name)};` +
      `$exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE name=%s AND discovered=1",$n));` +
      `if(!$exists){$wpdb->insert($t,array('name'=>$n,'slug'=>sanitize_title($n),'description'=>'e2e seed','duration'=>'session','domain'=>'example.com','category'=>0,'type'=>'http','discovered'=>1,'date_created'=>current_time('mysql'),'date_modified'=>current_time('mysql')));}`,
  );
}

/** Remove the e2e-seeded provider cookie rows. */
function removeSeededCookie(name: string): void {
  wpEval(
    `global $wpdb;$t=$wpdb->prefix.'faz_cookies';` +
      `$wpdb->query($wpdb->prepare("DELETE FROM $t WHERE name=%s AND description=%s",${JSON.stringify(name)},'e2e seed'));`,
  );
}

/** Bust the detected-cookie-name transient + per-service caches so the next load rebuilds. */
function bustDetectionCache(): void {
  wpEval(`delete_transient('faz_detected_cookie_names');`);
}

/** Read the parsed _fazConfig._services array from a frontend page. */
async function readServices(page: Page): Promise<Array<Record<string, unknown>>> {
  return page.evaluate(
    () =>
      (window as unknown as { _fazConfig?: { _services?: Array<Record<string, unknown>> } })._fazConfig
        ?._services ?? [],
  );
}

test.describe('Per-service consent — edge cases (per-service-e2e)', () => {
  test.describe.configure({ mode: 'serial' });

  let original: FazSettings | null = null;
  let nonce = '';

  test.beforeAll(async ({ browser, loginAsAdmin }) => {
    // 1) Self-contained DB state: seed the two provider cookies the per-service
    //    list keys off, BEFORE flipping the feature, so the first frontend load
    //    in the suite already sees google-analytics + facebook services.
    seedDetectedCookie('_ga');
    seedDetectedCookie('_fbp');
    bustDetectionCache();

    // 2) Enable per_service_consent through the real settings REST endpoint.
    const page = await browser.newPage();
    await loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', {
      waitUntil: 'domcontentloaded',
    });
    nonce = await getAdminNonce(page);
    expect(nonce.length).toBeGreaterThan(0);
    original = await getSettings(page, nonce);
    const bannerControl = { ...(original.banner_control as Record<string, unknown> | undefined) };
    await postSettings(page, nonce, {
      banner_control: { ...bannerControl, per_service_consent: true },
    });
    await page.close();
    // The settings write can recompute caches; re-bust the detection transient
    // so the per-service list is fresh on the first visitor load.
    bustDetectionCache();
  });

  test.afterAll(async ({ browser, loginAsAdmin }) => {
    if (original?.banner_control) {
      const page = await browser.newPage();
      await loginAsAdmin(page);
      await page.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', {
        waitUntil: 'domcontentloaded',
      });
      const n = await getAdminNonce(page);
      await postSettings(page, n, { banner_control: original.banner_control as FazSettings });
      await page.close();
    }
    removeSeededCookie('_ga');
    removeSeededCookie('_fbp');
    bustDetectionCache();
  });

  // ───────────────────────────────────────────────────────────────────────
  // 1. The seeded providers surface as services (precondition for the rest).
  // ───────────────────────────────────────────────────────────────────────
  test('seeded _ga and _fbp surface as google-analytics + facebook services', async ({
    browser,
  }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const services = await readServices(page);
    const ids = services.map((s) => String(s.id));
    expect(ids, 'analytics service present from seeded _ga').toContain('google-analytics');
    expect(ids, 'marketing service present from seeded _fbp').toContain('facebook');

    const ga = services.find((s) => s.id === 'google-analytics')!;
    const fb = services.find((s) => s.id === 'facebook')!;
    expect(ga.category).toBe('analytics');
    expect(fb.category).toBe('marketing');
    // patterns must be present so the blocker can map a URL → service.
    expect(Array.isArray(ga.patterns)).toBe(true);
    expect((ga.patterns as unknown[]).length).toBeGreaterThan(0);

    await ctx.close();
  });

  // ───────────────────────────────────────────────────────────────────────
  // 2. Preference center renders .faz-service-toggle under the category
  //    accordion (one per seeded service, with data-service/data-category).
  // ───────────────────────────────────────────────────────────────────────
  test('preference center renders a .faz-service-toggle under its category accordion', async ({
    browser,
  }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    // The toggles are rendered into the accordion bodies on load; assert the
    // analytics toggle lives inside #fazDetailCategoryanalytics specifically
    // (edge: a service must be nested under its OWN category, not loose).
    const placement = await page.evaluate(() => {
      const t = document.querySelector(
        '.faz-service-toggle[data-service="google-analytics"][data-category="analytics"]',
      ) as HTMLElement | null;
      if (!t) return { found: false, underOwnCategory: false, label: '' };
      const acc = document.getElementById('fazDetailCategoryanalytics');
      const underOwnCategory = !!(acc && acc.contains(t));
      const ariaLabel = t.getAttribute('aria-label') || '';
      return { found: true, underOwnCategory, label: ariaLabel };
    });

    expect(placement.found, '.faz-service-toggle for google-analytics is rendered').toBe(true);
    expect(placement.underOwnCategory, 'service toggle nested under its own category accordion').toBe(
      true,
    );
    expect(placement.label.length, 'service toggle carries an accessible label').toBeGreaterThan(0);

    // The marketing service toggle must exist too, under marketing.
    const fbCount = await page
      .locator('.faz-service-toggle[data-service="facebook"][data-category="marketing"]')
      .count();
    expect(fbCount).toBe(1);

    await ctx.close();
  });

  // ───────────────────────────────────────────────────────────────────────
  // 3. EDGE: a denied service (svc:no) keeps its script blocked even while
  //    the parent category is accepted. The service override must WIN.
  // ───────────────────────────────────────────────────────────────────────
  test('denied service (svc:no) stays blocked while its category is accepted', async ({
    browser,
  }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const decision = await page.evaluate(() => {
      const w = window as unknown as {
        fazcookie?: { _fazSetInStore?: (k: string, v: string) => void };
        _fazShouldBlockResource?: (cat: string, target: string, svc: string) => boolean;
        _fazShouldBlockProvider?: (target: string) => boolean;
      };
      const fz = w.fazcookie;
      if (!fz || typeof fz._fazSetInStore !== 'function') return { ok: false } as Record<string, unknown>;
      // Accept analytics category, but explicitly DENY the google-analytics service.
      fz._fazSetInStore('analytics', 'yes');
      fz._fazSetInStore('svc.google-analytics', 'no');
      // _fazShouldBlockProvider is the public blocking decision used for URLs.
      const blockedByProvider =
        typeof w._fazShouldBlockProvider === 'function'
          ? w._fazShouldBlockProvider('https://www.google-analytics.com/analytics.js')
          : null;
      return { ok: true, blockedByProvider };
    });

    expect(decision.ok, 'frontend blocking helpers exposed').toBe(true);
    // svc.google-analytics:no must force a block even though analytics:yes.
    expect(
      decision.blockedByProvider,
      'svc:no overrides an accepted category → provider stays blocked',
    ).toBe(true);

    await ctx.close();
  });

  // ───────────────────────────────────────────────────────────────────────
  // 4. EDGE (inverse): explicit svc:yes overrides a DENIED category — the one
  //    consented service is unblocked while the rest of the category stays
  //    blocked.
  // ───────────────────────────────────────────────────────────────────────
  test('explicit svc:yes overrides a denied category for that one service only', async ({
    browser,
  }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const result = await page.evaluate(() => {
      const w = window as unknown as {
        fazcookie?: { _fazSetInStore?: (k: string, v: string) => void };
        _fazShouldBlockProvider?: (target: string) => boolean;
      };
      const fz = w.fazcookie;
      if (!fz || typeof fz._fazSetInStore !== 'function' || typeof w._fazShouldBlockProvider !== 'function') {
        return { ok: false } as Record<string, unknown>;
      }
      // Deny the WHOLE marketing category…
      fz._fazSetInStore('marketing', 'no');
      // …but explicitly allow ONLY the facebook service.
      fz._fazSetInStore('svc.facebook', 'yes');
      const facebookBlocked = w._fazShouldBlockProvider('https://connect.facebook.net/en_US/fbevents.js');
      return { ok: true, facebookBlocked };
    });

    expect(result.ok, 'frontend helpers exposed').toBe(true);
    // svc.facebook:yes unblocks facebook even with marketing:no.
    expect(result.facebookBlocked, 'svc:yes unblocks its provider under a denied category').toBe(false);

    await ctx.close();
  });

  // ───────────────────────────────────────────────────────────────────────
  // 5. EDGE: GPC clears svc.* overrides THEN blocks. A stale svc:yes must not
  //    survive a legally-binding GPC opt-out.
  // ───────────────────────────────────────────────────────────────────────
  test('GPC opt-out clears svc.* overrides then blocks the provider', async ({
    browser,
    wpBaseURL,
  }) => {
    // Enable respectGPC on the default banner for this test only.
    wpEval(
      `global $wpdb; $t=$wpdb->prefix.'faz_banners';` +
        `$id=(int)$wpdb->get_var("SELECT banner_id FROM $t WHERE banner_default=1 LIMIT 1");` +
        `if(!$id){$id=(int)$wpdb->get_var("SELECT banner_id FROM $t WHERE status=1 LIMIT 1");}` +
        `if($id){$s=json_decode($wpdb->get_var($wpdb->prepare("SELECT settings FROM $t WHERE banner_id=%d",$id)),true);` +
        `if(!is_array($s))$s=array();if(!isset($s['behaviours'])||!is_array($s['behaviours']))$s['behaviours']=array();` +
        `$s['behaviours']['respectGPC']=array('status'=>true);` +
        `$wpdb->update($t,array('settings'=>wp_json_encode($s)),array('banner_id'=>$id));}` +
        `\\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();` +
        `delete_option('faz_banner_template');`,
    );

    try {
      const ctx = await browser.newContext();
      // Assert GPC before any page script runs.
      await ctx.addInitScript(() => {
        Object.defineProperty(navigator, 'globalPrivacyControl', {
          get: () => true,
          configurable: true,
        });
      });
      const page = await ctx.newPage();
      await page.goto(`${wpBaseURL}/?n=${Date.now()}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1500);

      const state = await page.evaluate(() => {
        const w = window as unknown as {
          fazcookie?: { _fazGetFromStore?: (k: string) => string };
          _fazShouldBlockProvider?: (target: string) => boolean;
        };
        const raw = document.cookie
          .split(';')
          .map((s) => s.trim())
          .find((s) => s.startsWith('fazcookie-consent='));
        const decoded = raw ? decodeURIComponent(raw.substring('fazcookie-consent='.length)) : '';
        return {
          decoded,
          gpcMarker: /(?:^|,)gpc:1(?:,|$)/.test(decoded),
          hasSvcEntry: /(?:^|,)svc\./.test(decoded),
          gaBlocked:
            typeof w._fazShouldBlockProvider === 'function'
              ? w._fazShouldBlockProvider('https://www.google-analytics.com/analytics.js')
              : null,
        };
      });

      // The GPC opt-out was applied (gpc:1 marker present)…
      expect(state.gpcMarker, 'GPC opt-out recorded with gpc:1 marker').toBe(true);
      // …and NO svc.* override leaked into the cookie (they were cleared)…
      expect(state.hasSvcEntry, 'svc.* overrides cleared by GPC opt-out').toBe(false);
      // …and the analytics provider is blocked.
      expect(state.gaBlocked, 'provider blocked after GPC opt-out').toBe(true);

      await ctx.close();
    } finally {
      wpEval(
        `global $wpdb; $t=$wpdb->prefix.'faz_banners';` +
          `$id=(int)$wpdb->get_var("SELECT banner_id FROM $t WHERE banner_default=1 LIMIT 1");` +
          `if(!$id){$id=(int)$wpdb->get_var("SELECT banner_id FROM $t WHERE status=1 LIMIT 1");}` +
          `if($id){$s=json_decode($wpdb->get_var($wpdb->prepare("SELECT settings FROM $t WHERE banner_id=%d",$id)),true);` +
          `if(!is_array($s))$s=array();if(!isset($s['behaviours'])||!is_array($s['behaviours']))$s['behaviours']=array();` +
          `$s['behaviours']['respectGPC']=array('status'=>false);` +
          `$wpdb->update($t,array('settings'=>wp_json_encode($s)),array('banner_id'=>$id));}` +
          `\\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();` +
          `delete_option('faz_banner_template');`,
      );
    }
  });

  // ───────────────────────────────────────────────────────────────────────
  // 6. EDGE: the consent cookie stays under 4 KB by dropping ck.* before svc.*
  //    before core. A flood of diverging svc.* + ck.* overrides must not bust
  //    the browser limit, and the priority order must hold.
  // ───────────────────────────────────────────────────────────────────────
  test('4 KB cap drops ck.* before svc.* before core entries', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const result = await page.evaluate(() => {
      const w = window as unknown as {
        fazcookie?: { _fazSetInStore?: (k: string, v: string) => void };
      };
      const fz = w.fazcookie;
      if (!fz || typeof fz._fazSetInStore !== 'function') return { ok: false } as Record<string, unknown>;
      // Core category entry that must always survive.
      fz._fazSetInStore('analytics', 'yes');
      // A per-cookie override that diverges (low priority — dropped first).
      fz._fazSetInStore('ck.low-priority.example', 'no');
      // Flood with diverging svc.* allows to push past the budget.
      for (let i = 0; i < 300; i++) {
        fz._fazSetInStore('svc.flood-provider-with-a-long-identifier-' + i, 'yes');
      }
      // A diverging svc denial — explicit denials have the highest granular priority.
      fz._fazSetInStore('svc.critical-deny', 'no');

      const m = document.cookie.match(/fazcookie-consent=([^;]+)/);
      const raw = m ? m[1] : '';
      const decoded = decodeURIComponent(raw);
      return {
        ok: true,
        encodedLen: raw.length,
        hasCore: decoded.indexOf('analytics:yes') !== -1,
        hasCriticalDeny: decoded.indexOf('svc.critical-deny:no') !== -1,
        hasCkOverride: decoded.indexOf('ck.low-priority.example:no') !== -1,
      };
    });

    expect(result.ok, '_fazSetInStore exposed').toBe(true);
    // Under the hard browser limit (the code targets a 3500-byte budget).
    expect(result.encodedLen).toBeGreaterThan(0);
    expect(result.encodedLen).toBeLessThan(4096);
    // Core category entry is never sacrificed.
    expect(result.hasCore, 'core category entry survives the cap').toBe(true);
    // Explicit service denial outranks the flood of allows.
    expect(result.hasCriticalDeny, 'explicit svc:no kept over flooded svc:yes').toBe(true);
    // ck.* is dropped before any svc.* survives the cap.
    expect(result.hasCkOverride, 'ck.* override dropped before svc.* under the cap').toBe(false);

    await ctx.close();
  });

  // ───────────────────────────────────────────────────────────────────────
  // 7. EDGE: _fazAcceptService persists svc.<id>:yes (NOT the whole category)
  //    even when NO toggle is rendered for that service in the DOM.
  // ───────────────────────────────────────────────────────────────────────
  test('_fazAcceptService persists svc:yes without flipping the category, with no rendered toggle', async ({
    browser,
  }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const result = await page.evaluate(() => {
      const w = window as unknown as {
        _fazAcceptService?: (id: string, cat?: string) => void;
        fazcookie?: { _fazGetFromStore?: (k: string) => string };
      };
      if (typeof w._fazAcceptService !== 'function') return { ok: false } as Record<string, unknown>;
      // "youtube" is NOT a seeded/detected service, so there is no rendered
      // .faz-service-toggle for it — the edge the placeholder accept handles.
      const renderedTogglesBefore = document.querySelectorAll(
        '.faz-service-toggle[data-service="youtube"]',
      ).length;
      w._fazAcceptService('youtube');
      const raw = document.cookie
        .split(';')
        .map((s) => s.trim())
        .find((s) => s.startsWith('fazcookie-consent='));
      const decoded = raw ? decodeURIComponent(raw.substring('fazcookie-consent='.length)) : '';
      const get = w.fazcookie?._fazGetFromStore;
      return {
        ok: true,
        renderedTogglesBefore,
        svcYes: typeof get === 'function' ? get('svc.youtube') : '',
        // The whole marketing category must NOT be flipped to yes.
        marketingValue: typeof get === 'function' ? get('marketing') : '',
        cookieHasSvc: /(?:^|,)svc\.youtube:yes(?:,|$)/.test(decoded),
        cookieHasMarketingYes: /(?:^|,)marketing:yes(?:,|$)/.test(decoded),
      };
    });

    expect(result.ok, '_fazAcceptService exposed on window').toBe(true);
    expect(result.renderedTogglesBefore, 'no rendered toggle for the unseen service').toBe(0);
    // The per-service grant is persisted…
    expect(result.svcYes, 'svc.youtube stored as yes').toBe('yes');
    expect(result.cookieHasSvc, 'svc.youtube:yes serialised into the cookie').toBe(true);
    // …but the category is NOT flipped to yes by the single-service accept.
    expect(result.marketingValue, 'marketing category not flipped to yes').not.toBe('yes');
    expect(result.cookieHasMarketingYes, 'marketing:yes not written by single-service accept').toBe(
      false,
    );

    await ctx.close();
  });

  // ───────────────────────────────────────────────────────────────────────
  // 8. EDGE: an ABSENT svc.<id> entry inherits the category (fallback contract).
  //    With analytics:no and no svc override, the analytics provider is blocked;
  //    with analytics:yes and no svc override, it is allowed.
  // ───────────────────────────────────────────────────────────────────────
  test('absent svc.<id> inherits the category consent (fallback contract)', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const result = await page.evaluate(() => {
      const w = window as unknown as {
        fazcookie?: { _fazSetInStore?: (k: string, v: string) => void };
        _fazShouldBlockProvider?: (target: string) => boolean;
      };
      const fz = w.fazcookie;
      if (!fz || typeof fz._fazSetInStore !== 'function' || typeof w._fazShouldBlockProvider !== 'function') {
        return { ok: false } as Record<string, unknown>;
      }
      const url = 'https://www.google-analytics.com/analytics.js';
      // No svc.google-analytics override anywhere.
      fz._fazSetInStore('analytics', 'no');
      const blockedWhenCatNo = w._fazShouldBlockProvider(url);
      fz._fazSetInStore('analytics', 'yes');
      const blockedWhenCatYes = w._fazShouldBlockProvider(url);
      return { ok: true, blockedWhenCatNo, blockedWhenCatYes };
    });

    expect(result.ok, 'frontend helpers exposed').toBe(true);
    // With no svc override, the provider follows its category.
    expect(result.blockedWhenCatNo, 'absent svc inherits category:no → blocked').toBe(true);
    expect(result.blockedWhenCatYes, 'absent svc inherits category:yes → allowed').toBe(false);

    await ctx.close();
  });

  // ───────────────────────────────────────────────────────────────────────
  // 9. EDGE: the necessary category never gets a service toggle (services in
  //    the necessary category are excluded from per-service consent).
  // ───────────────────────────────────────────────────────────────────────
  test('necessary category exposes no service toggles', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const result = await page.evaluate(() => {
      const services =
        (window as unknown as { _fazConfig?: { _services?: Array<{ category?: string }> } })._fazConfig
          ?._services ?? [];
      const necessaryServices = services.filter((s) => s.category === 'necessary').length;
      const necessaryToggles = document.querySelectorAll(
        '.faz-service-toggle[data-category="necessary"]',
      ).length;
      return { necessaryServices, necessaryToggles };
    });

    expect(result.necessaryServices, 'no necessary-category services in the list').toBe(0);
    expect(result.necessaryToggles, 'no necessary-category service toggles rendered').toBe(0);

    await ctx.close();
  });

  // ───────────────────────────────────────────────────────────────────────
  // 10. EDGE: a service toggle reflects the explicit svc override on render
  //     (svc:no wins over an accepted category for the checkbox state).
  // ───────────────────────────────────────────────────────────────────────
  test('rendered service toggle reflects svc override over category on a fresh load', async ({
    browser,
    wpBaseURL,
  }) => {
    const ctx = await browser.newContext();
    // Seed a consent cookie: analytics accepted, but google-analytics service denied.
    const rev = parseInt(wpEval('echo faz_get_consent_revision();').trim(), 10) || 1;
    const domain = new URL(wpBaseURL).hostname;
    await ctx.addCookies([
      {
        name: 'fazcookie-consent',
        value:
          `consentid%3Ae2e-edge%2Cconsent%3Ayes%2Caction%3Ayes%2Cnecessary%3Ayes%2C` +
          `analytics%3Ayes%2Cmarketing%3Ano%2C` +
          `svc.google-analytics%3Ano%2Crev%3A${rev}`,
        domain,
        path: '/',
        sameSite: 'Lax',
      },
    ]);
    const page = await ctx.newPage();
    await page.goto(`${wpBaseURL}/?n=${Date.now()}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);

    const checked = await page.evaluate(() => {
      const t = document.querySelector(
        '.faz-service-toggle[data-service="google-analytics"]',
      ) as HTMLInputElement | null;
      return t ? t.checked : null;
    });

    // analytics:yes but svc.google-analytics:no → the toggle must render UNCHECKED.
    expect(checked, 'service toggle rendered unchecked because svc:no overrides category:yes').toBe(
      false,
    );

    await ctx.close();
  });

  // ───────────────────────────────────────────────────────────────────────
  // 11. EDGE: malformed/unknown svc key does not crash blocking and is ignored
  //     for an unrelated provider (an unknown service id never blocks GA).
  // ───────────────────────────────────────────────────────────────────────
  test('unknown svc.<id> override does not affect an unrelated provider', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const result = await page.evaluate(() => {
      const w = window as unknown as {
        fazcookie?: { _fazSetInStore?: (k: string, v: string) => void };
        _fazShouldBlockProvider?: (target: string) => boolean;
      };
      const fz = w.fazcookie;
      if (!fz || typeof fz._fazSetInStore !== 'function' || typeof w._fazShouldBlockProvider !== 'function') {
        return { ok: false } as Record<string, unknown>;
      }
      fz._fazSetInStore('analytics', 'yes');
      // An override for a service that maps to no detected provider.
      fz._fazSetInStore('svc.this-service-does-not-exist', 'no');
      let threw = false;
      let gaBlocked: boolean | null = null;
      try {
        gaBlocked = w._fazShouldBlockProvider('https://www.google-analytics.com/analytics.js');
      } catch {
        threw = true;
      }
      return { ok: true, threw, gaBlocked };
    });

    expect(result.ok, 'helpers exposed').toBe(true);
    expect(result.threw, 'unknown svc key does not throw in the blocker').toBe(false);
    // GA follows analytics:yes — the unknown svc:no must not block it.
    expect(result.gaBlocked, 'unknown svc.<id>:no leaves GA allowed under analytics:yes').toBe(false);

    await ctx.close();
  });

  // ───────────────────────────────────────────────────────────────────────
  // 12. per_service OFF → category-only: no _services exposed, no toggles
  //     rendered, but category toggles still present. (Flag flips back to ON
  //     in the finally so the serial suite's later state assumptions hold.)
  // ───────────────────────────────────────────────────────────────────────
  test('per_service OFF falls back to category-only (no service toggles)', async ({
    browser,
    loginAsAdmin,
  }) => {
    const adminPage = await browser.newPage();
    let visitorContext: BrowserContext | null = null;
    await loginAsAdmin(adminPage);
    await adminPage.goto('/wp-admin/admin.php?page=faz-cookie-manager-settings', {
      waitUntil: 'domcontentloaded',
    });
    const adminNonce = await getAdminNonce(adminPage);
    expect(adminNonce.length).toBeGreaterThan(0);

    try {
      const current = await getSettings(adminPage, adminNonce);
      await postSettings(adminPage, adminNonce, {
        banner_control: {
          ...(current.banner_control as Record<string, unknown> | undefined),
          per_service_consent: false,
        },
      });

      visitorContext = await browser.newContext();
      const visitorPage = await visitorContext.newPage();
      await visitorPage.goto('/', { waitUntil: 'domcontentloaded' });

      const mode = await visitorPage.evaluate(() => {
        const cfg = (window as unknown as {
          _fazConfig?: { _perServiceConsent?: boolean; _services?: unknown };
        })._fazConfig;
        return {
          enabled: cfg?._perServiceConsent === true,
          hasServices: Array.isArray(cfg?._services),
          toggles: document.querySelectorAll('.faz-service-toggle').length,
          categoryToggles: document.querySelectorAll(
            'input[id^="fazSwitch"], input[id^="fazCategoryDirect"]',
          ).length,
        };
      });

      expect(mode.enabled, 'per-service flag is off').toBe(false);
      expect(mode.hasServices, '_services not exposed in category-only mode').toBe(false);
      expect(mode.toggles, 'no .faz-service-toggle rendered in category-only mode').toBe(0);
      expect(mode.categoryToggles, 'category toggles still present').toBeGreaterThan(0);
    } finally {
      const current = await getSettings(adminPage, adminNonce);
      await postSettings(adminPage, adminNonce, {
        banner_control: {
          ...(current.banner_control as Record<string, unknown> | undefined),
          per_service_consent: true,
        },
      });
      bustDetectionCache();
      if (visitorContext) await visitorContext.close();
      await adminPage.close();
    }
  });
});
