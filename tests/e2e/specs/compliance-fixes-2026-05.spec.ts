/**
 * E2E tests for compliance fixes applied in the 2026-05 review round.
 *
 * 15 tests covering:
 *   P1-A  (×2) — consentExpiry default 180 days (Garante Privacy ≤ 6 months)
 *   P1-B  (×2) — cross-domain forwarding: action:yes required; no upgrade after local action
 *   P1-D  (×1) — close button hidden when reject button is present (Garante Provv. 10/06/2021)
 *   P2-A/B(×4) — no fazcookie-consent cookie / consentid before user action
 *   P2-F  (×2) — whitelist class/id token matching (no substring false positives)
 *   P3-B  (×1) — DSAR per-email rate limit (1 submission / email / hour)
 *   P3-G  (×2) — WebSocket interception for blocked providers
 *   P3-H  (×1) — IndexedDB / Cache Storage cleanup runs without JS errors
 */

import { expect } from '@playwright/test';
import { test } from '../fixtures/wp-fixture';
import { resetDefaultBannerState } from '../utils/seed-defaults';
import { upsertPage, wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
const DSAR_SLUG = 'faz-e2e-compliance-dsar';

// ── Shared helpers ────────────────────────────────────────────────────────────

type SettingsSnapshot = string;

function snapshotSettings(): SettingsSnapshot {
  return wpEval(`echo wp_json_encode( get_option( 'faz_settings', array() ) );`);
}

function restoreSettings(snap: SettingsSnapshot): void {
  const encoded = Buffer.from(snap, 'utf8').toString('base64');
  wpEval(`
    $s = json_decode( base64_decode( '${encoded}' ), true );
    update_option( 'faz_settings', is_array( $s ) ? $s : array() );
    delete_option( 'faz_banner_template' );
    if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
      \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
    }
  `);
}

function clearRateLimitTransients(): void {
  wpEval(`
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_faz_dsar_rl_%'" );
  `);
}

/**
 * Wait for the FAZ plugin JS to be fully initialised.
 * _fazConfig is the var injected by wp_localize_script (on window).
 * window.fazcookie is set synchronously at line ~11 of script.js.
 */
async function waitForPluginInit(page: import('@playwright/test').Page): Promise<void> {
  await page.waitForFunction(
    () =>
      typeof (window as Record<string, unknown>)._fazConfig !== 'undefined' &&
      typeof (window as Record<string, unknown>).fazcookie !== 'undefined',
    { timeout: 15_000 }
  );
}

/** Wait for the consent banner to become visible. */
async function waitForBanner(page: import('@playwright/test').Page): Promise<void> {
  // [data-faz-tag="notice"] is the inner bar element — _fazShowBanner() removes
  // faz-hide from its ancestor .faz-consent-container via _fazGetBanner().
  await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible({ timeout: 15_000 });
}

let dsarUrl = '';

// ─── Suite setup ─────────────────────────────────────────────────────────────

test.beforeAll(() => {
  upsertPage(DSAR_SLUG, 'FAZ Compliance DSAR', '[faz_dsar_form]');
  dsarUrl = wpEval(`
    $p = get_page_by_path( '${DSAR_SLUG}', OBJECT, 'page' );
    echo $p ? get_permalink( $p->ID ) : '';
  `).trim();
});

// ─── P1-A: consentExpiry default = 180 days ───────────────────────────────────

test.beforeAll(() => {
  // Self-provision the default box+popup GDPR banner so this spec is immune
  // to a prior full-suite spec leaving the shared banner in classic/pushdown
  // or CCPA mode (see utils/seed-defaults.ts).
  resetDefaultBannerState();
});

test.describe('P1-A — consent expiry default 180 days', () => {
  test.describe.configure({ mode: 'serial' });

  let snap: SettingsSnapshot;

  let bannerSnap: { banner_id: number; settings: string } | null = null;

  test.beforeAll(() => {
    snap = snapshotSettings();
    // Banner settings live in wp_faz_banners.settings (JSON), not faz_settings.
    // Primary key is banner_id (not id). Snapshot the active banner record and
    // force consentExpiry = 180 so the runtime JS check reflects the new default.
    const raw = wpEval(`
      global $wpdb;
      $b = $wpdb->get_row( "SELECT banner_id, settings FROM {$wpdb->prefix}faz_banners WHERE status = 1 LIMIT 1" );
      echo $b ? wp_json_encode( array( 'banner_id' => (int) $b->banner_id, 'settings' => $b->settings ) ) : '';
    `).trim();
    if (raw) {
      bannerSnap = JSON.parse(raw) as { banner_id: number; settings: string };
      const settings = JSON.parse(bannerSnap.settings) as Record<string, unknown>;
      const s = settings as {
        settings?: { consentExpiry?: { value?: number } };
      };
      if (!s.settings) s.settings = {};
      if (!s.settings.consentExpiry) s.settings.consentExpiry = {};
      s.settings.consentExpiry.value = 180;
      const updatedJson = JSON.stringify(settings);
      const encoded = Buffer.from(updatedJson, 'utf8').toString('base64');
      wpEval(`
        global $wpdb;
        $wpdb->update(
          $wpdb->prefix . 'faz_banners',
          array( 'settings' => base64_decode( '${encoded}' ) ),
          array( 'banner_id' => ${bannerSnap.banner_id} )
        );
        delete_option( 'faz_banner_template' );
        if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
          \\FazCookie\\Includes\\Cache::delete( 'banners' );
          \\FazCookie\\Includes\\Cache::delete( 'settings' );
        }
      `);
    }
  });

  test.afterAll(() => {
    if (bannerSnap) {
      const encoded = Buffer.from(bannerSnap.settings, 'utf8').toString('base64');
      wpEval(`
        global $wpdb;
        $wpdb->update(
          $wpdb->prefix . 'faz_banners',
          array( 'settings' => base64_decode( '${encoded}' ) ),
          array( 'banner_id' => ${bannerSnap.banner_id} )
        );
        delete_option( 'faz_banner_template' );
        if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
          \\FazCookie\\Includes\\Cache::delete( 'banners' );
          \\FazCookie\\Includes\\Cache::delete( 'settings' );
        }
      `);
    }
    restoreSettings(snap);
  });

  test('01 — JS _fazStore._expiry is ≤ 180 on a default install', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto(WP_BASE, { waitUntil: 'domcontentloaded' });
    await waitForPluginInit(page);

    // _fazStore is a const alias for window._fazConfig — accessible as a
    // global binding in the browser scope but NOT as window._fazStore.
    const expiry = await page.evaluate(
      () => ((window as Record<string, unknown>)._fazConfig as Record<string, unknown>)._expiry ?? null
    );

    expect(expiry).not.toBeNull();
    expect(Number(expiry)).toBeLessThanOrEqual(180);
  });

  test('02 — PHP: consentExpiry value in default config JSON is 180', async () => {
    const result = wpEval(`
      $ref  = new ReflectionClass( '\\\\FazCookie\\\\Frontend\\\\Frontend' );
      $base = dirname( $ref->getFileName(), 2 );
      $path = $base . '/admin/modules/banners/includes/configs/gdpr.json';
      $json = json_decode( file_get_contents( $path ), true );
      echo intval( $json['settings']['consentExpiry']['value'] ?? -1 );
    `);
    expect(parseInt(result.trim(), 10)).toBe(180);
  });
});

// ─── P1-D: close button hidden when reject button is enabled ──────────────────

test.describe('P1-D — close button absent when reject button is present', () => {
  test.describe.configure({ mode: 'serial' });

  let snap: SettingsSnapshot;

  test.beforeAll(() => {
    snap = snapshotSettings();
    wpEval(`delete_option( 'faz_banner_template' );`);
  });

  test.afterAll(() => {
    restoreSettings(snap);
  });

  test('03 — [data-faz-tag="close-button"] absent from DOM when reject button is enabled', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto(WP_BASE, { waitUntil: 'domcontentloaded' });
    await waitForBanner(page);

    const rejectBtn = await page.$('[data-faz-tag="reject-button"]');
    if (!rejectBtn) {
      test.skip(true, 'reject-button not found in DOM — banner may be hidden or law is not GDPR');
      return;
    }

    const closeBtn = await page.$('[data-faz-tag="close-button"]');
    expect(closeBtn).toBeNull();
  });
});

// ─── P1-B: cross-domain forwarding security ───────────────────────────────────

test.describe('P1-B — cross-domain forwarding security guards', () => {
  test.describe.configure({ mode: 'serial' });

  test('04 — forwarded message without action:yes does NOT write cookie', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto(WP_BASE, { waitUntil: 'domcontentloaded' });
    await waitForPluginInit(page);

    // _fazConfig is the window-level config object (same object as _fazStore).
    await page.evaluate(() => {
      const cfg = (window as Record<string, unknown>)._fazConfig as Record<string, unknown>;
      if (cfg) {
        cfg._consentForwarding = {
          enabled: true,
          targets: [window.location.origin],
        };
      }
    });

    // Capture only the FAZ consent cookie — `document.cookie` returns every
    // cookie on the document and the test site has third-party tracking
    // plugins (burst-statistics, IAWP, pys, etc.) that can write/refresh
    // their own cookies during the 300ms wait below, which would otherwise
    // make this assertion intermittently fail even when the FAZ listener
    // correctly refuses the forwarded message.
    const readFazCookie = () =>
      page.evaluate(() => {
        const m = document.cookie.split(';').find((c) => c.trim().startsWith('fazcookie-consent='));
        return m ?? '';
      });

    // cookieBefore is captured but NOT asserted to be non-empty: since
    // the ePrivacy Art. 5(3) compliance fix at frontend/js/script.js:388
    // (the inline comment reads "Do NOT call _fazSetConsentID() here —
    // the consentid is a stable 32-char random tracker written into
    // fazcookie-consent. Generating it before the user acts creates a
    // persistent fingerprint before consent"), the plugin deliberately
    // defers writing the consent cookie until the first user action.
    // On a fresh page load with no prior decision, `cookieBefore` is
    // therefore the empty string — that is the compliant state, not a
    // regression.
    //
    // The invariant this test must actually guard is "an unsolicited
    // forwarded message does not bestow consent." We assert that on
    // the cookieAfter snapshot directly:
    //   (1) cookieAfter equals cookieBefore (no mutation), AND
    //   (2) cookieAfter does NOT carry `action:yes` (the forwarded
    //       payload must not have silently smuggled a consent decision
    //       in).
    // (2) is the real strengthening — it catches the silent-fingerprint
    // failure mode even when both reads land on the empty string.
    const cookieBefore = await readFazCookie();

    await page.evaluate(() => {
      window.dispatchEvent(
        new MessageEvent('message', {
          data: {
            type: 'faz_consent_forward',
            consent: 'consent:yes,necessary:yes,analytics:yes',
          },
          origin: window.location.origin,
        })
      );
    });

    await page.waitForTimeout(300);
    const cookieAfter = await readFazCookie();
    expect(cookieAfter, 'forwarded message without action:yes must not mutate fazcookie-consent').toBe(cookieBefore);
    expect(cookieAfter, 'forwarded message must NOT bestow action:yes').not.toMatch(/action:yes/);
  });

  test('05 — forwarded message is ignored when local user already took action', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto(WP_BASE, { waitUntil: 'domcontentloaded' });
    await waitForBanner(page);
    await page.waitForSelector('[data-faz-tag="reject-button"]', { timeout: 10_000 });

    await page.click('[data-faz-tag="reject-button"]');
    await page.waitForTimeout(400);

    const cookiesAfterReject = await page.context().cookies();
    const consentAfterReject = cookiesAfterReject.find((c) => c.name === 'fazcookie-consent');
    const valueBefore = consentAfterReject?.value ?? '';

    await page.evaluate(() => {
      const cfg = (window as Record<string, unknown>)._fazConfig as Record<string, unknown>;
      if (cfg) {
        cfg._consentForwarding = {
          enabled: true,
          targets: [window.location.origin],
        };
      }
      window.dispatchEvent(
        new MessageEvent('message', {
          data: {
            type: 'faz_consent_forward',
            consent: 'consent:yes,action:yes,necessary:yes,analytics:yes,marketing:yes',
          },
          origin: window.location.origin,
        })
      );
    });

    await page.waitForTimeout(300);
    const cookiesAfterForward = await page.context().cookies();
    const consentAfterForward = cookiesAfterForward.find((c) => c.name === 'fazcookie-consent');
    expect(consentAfterForward?.value ?? '').toBe(valueBefore);
  });
});

// ─── P2-A/B: no cookie / consentid before user action ────────────────────────

test.describe('P2-A/B — no cookie written before user action', () => {
  test.describe.configure({ mode: 'serial' });

  test('06 — fazcookie-consent cookie absent immediately after first-visit page load', async ({
    page,
  }) => {
    await page.context().clearCookies();
    await page.goto(WP_BASE, { waitUntil: 'domcontentloaded' });
    await waitForBanner(page);

    const cookies = await page.context().cookies();
    const consent = cookies.find((c) => c.name === 'fazcookie-consent');
    expect(consent).toBeUndefined();
  });

  test('07 — consentid absent from cookie on first visit (no write before action)', async ({
    page,
  }) => {
    await page.context().clearCookies();
    await page.goto(WP_BASE, { waitUntil: 'domcontentloaded' });
    await waitForBanner(page);

    const cookies = await page.context().cookies();
    const consent = cookies.find((c) => c.name === 'fazcookie-consent');
    if (consent) {
      expect(decodeURIComponent(consent.value)).not.toContain('consentid:');
    }
  });

  test('08 — fazcookie-consent written with consent:yes and action:yes after Accept click', async ({
    page,
  }) => {
    await page.context().clearCookies();
    await page.goto(WP_BASE, { waitUntil: 'domcontentloaded' });
    await waitForBanner(page);
    await page.waitForSelector('[data-faz-tag="accept-button"]', { timeout: 10_000 });
    await page.click('[data-faz-tag="accept-button"]');
    await page.waitForTimeout(500);

    const cookies = await page.context().cookies();
    const consent = cookies.find((c) => c.name === 'fazcookie-consent');
    expect(consent).toBeDefined();
    const val = decodeURIComponent(consent!.value);
    expect(val).toContain('consent:yes');
    expect(val).toContain('action:yes');
  });

  test('09 — consentid present in cookie only after user clicks Reject', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto(WP_BASE, { waitUntil: 'domcontentloaded' });

    let cookies = await page.context().cookies();
    expect(cookies.find((c) => c.name === 'fazcookie-consent')).toBeUndefined();

    await waitForBanner(page);
    await page.waitForSelector('[data-faz-tag="reject-button"]', { timeout: 10_000 });
    await page.click('[data-faz-tag="reject-button"]');
    await page.waitForTimeout(500);

    cookies = await page.context().cookies();
    const consent = cookies.find((c) => c.name === 'fazcookie-consent');
    expect(consent).toBeDefined();
    expect(decodeURIComponent(consent!.value)).toContain('consentid:');
  });
});

// ─── P2-F: whitelist class/id token matching ──────────────────────────────────

test.describe('P2-F — whitelist token matching precision', () => {
  test.describe.configure({ mode: 'serial' });

  test('10 — PHP matches_whitelist_pattern: exact token=true, substring=false, spaced=true', async () => {
    const result = wpEval(`
      $frontend = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $ref = new ReflectionMethod( $frontend, 'matches_whitelist_pattern' );
      $ref->setAccessible( true );
      $exact   = $ref->invoke( $frontend, 'analytics', 'analytics' );
      $false_p = $ref->invoke( $frontend, 'my-analytics-helper', 'analytics' );
      $spaced  = $ref->invoke( $frontend, 'foo analytics bar', 'analytics' );
      $url_ok  = $ref->invoke( $frontend, 'https://www.google-analytics.com/ga.js', 'google-analytics.com' );
      echo json_encode( compact( 'exact', 'false_p', 'spaced', 'url_ok' ) );
    `);

    const r = JSON.parse(result) as {
      exact: boolean;
      false_p: boolean;
      spaced: boolean;
      url_ok: boolean;
    };
    expect(r.exact, 'exact token must match').toBe(true);
    expect(r.false_p, 'substring-in-hyphenated-token must NOT match').toBe(false);
    expect(r.spaced, 'space-separated token must match').toBe(true);
    expect(r.url_ok, 'URL pattern must still match via url branch').toBe(true);
  });

  test('11 — PHP is_whitelisted: script with class="my-analytics-helper" is NOT whitelisted by "analytics"', async () => {
    const result = wpEval(`
      $frontend = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $getWl = new ReflectionMethod( $frontend, 'get_whitelist' );
      $getWl->setAccessible( true );
      $setProp = new ReflectionProperty( $frontend, 'whitelist_cache' );
      $setProp->setAccessible( true );
      $setProp->setValue( $frontend, array( 'analytics' ) );
      $isWl = new ReflectionMethod( $frontend, 'is_whitelisted' );
      $isWl->setAccessible( true );
      $false_pos = $isWl->invoke( $frontend, 'class="my-analytics-helper" src="https://example.com/t.js"', '' );
      $true_pos  = $isWl->invoke( $frontend, 'class="analytics" src="https://example.com/t.js"', '' );
      echo json_encode( compact( 'false_pos', 'true_pos' ) );
    `);

    const r = JSON.parse(result) as { false_pos: boolean; true_pos: boolean };
    expect(r.false_pos, '"my-analytics-helper" must not match whitelist "analytics"').toBe(false);
    expect(r.true_pos, '"analytics" class must match whitelist "analytics"').toBe(true);
  });
});

// ─── P3-B: DSAR per-email rate limit ─────────────────────────────────────────

test.describe('P3-B — DSAR per-email rate limit', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(() => {
    clearRateLimitTransients();
  });

  test.afterAll(() => {
    clearRateLimitTransients();
    wpEval(`
      $posts = get_posts( array( 'post_type' => 'faz_dsar', 'numberposts' => -1, 'post_status' => 'private' ) );
      foreach ( $posts as $post ) { wp_delete_post( $post->ID, true ); }
    `);
  });

  test('12 — second DSAR submission with same email within 1 hour is rejected with error', async ({
    page,
  }) => {
    if (!dsarUrl) return test.skip();

    await page.context().clearCookies();
    await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });

    // The nonce hidden field has name="nonce" (no id attribute on the element).
    const nonce = await page
      .locator('.faz-dsar-form [name="nonce"]')
      .getAttribute('value')
      .catch(() => '');
    expect(nonce).toBeTruthy();

    const ajaxUrl = `${WP_BASE}/wp-admin/admin-ajax.php`;
    const uniqueEmail = `rl-${Date.now()}@compliance-test.example`;

    const submitDsar = async (): Promise<{ success: boolean }> =>
      page.evaluate(
        async ({ url, em, nc }: { url: string; em: string; nc: string }) => {
          const body = new URLSearchParams({
            action: 'faz_dsar_submit',
            nonce: nc,
            dsar_name: 'Rate Limit User',
            dsar_email: em,
            dsar_type: 'access',
            dsar_message: 'Per-email rate limit test',
            faz_hp_name: '',
          }).toString();
          const r = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
          });
          return r.json() as Promise<{ success: boolean }>;
        },
        { url: ajaxUrl, em: uniqueEmail, nc: nonce! }
      );

    const r1 = await submitDsar();
    expect(r1.success).toBe(true);

    // Clear the per-IP transient so only the per-email limit applies.
    wpEval(`
      global $wpdb;
      $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_faz_dsar_rl_%' AND option_name NOT LIKE '_transient_faz_dsar_rl_em_%'" );
    `);

    const r2 = await submitDsar();
    expect(r2.success).toBe(false);
  });
});

// ─── P3-G: WebSocket interception ────────────────────────────────────────────

test.describe('P3-G — WebSocket interception', () => {
  test.describe.configure({ mode: 'serial' });

  test('13 — window.WebSocket is patched by the plugin (not the native constructor)', async ({
    page,
  }) => {
    await page.context().clearCookies();
    await page.goto(WP_BASE, { waitUntil: 'domcontentloaded' });
    // Wait for the plugin JS (including _fazNetworkInterceptors IIFE) to run.
    await waitForPluginInit(page);

    const isPatched = await page.evaluate(() => {
      return !window.WebSocket.toString().includes('[native code]');
    });

    expect(isPatched).toBe(true);
  });

  test('14 — WebSocket to a blocked tracking endpoint returns readyState CLOSED immediately', async ({
    page,
  }) => {
    await page.context().clearCookies();
    await page.goto(WP_BASE, { waitUntil: 'domcontentloaded' });
    await waitForPluginInit(page);

    const readyState = await page.evaluate(() => {
      const config = (window as unknown as {
        _fazConfig?: { _providersToBlock?: Array<{ re: string; categories: string[]; fullPath?: boolean }> };
      })._fazConfig;
      if (config) {
        config._providersToBlock = config._providersToBlock || [];
        if (!config._providersToBlock.some((provider) => provider.re === 'ws.hotjar.com/api/v1/client/ws')) {
          config._providersToBlock.push({
            re: 'ws.hotjar.com/api/v1/client/ws',
            categories: ['analytics'],
            fullPath: true,
          });
        }
      }
      const ws = new WebSocket('wss://ws.hotjar.com/api/v1/client/ws');
      return ws.readyState;
    });

    expect(readyState).toBe(3 /* WebSocket.CLOSED */);
  });
});

// ─── P3-H: IndexedDB / Cache Storage cleanup ─────────────────────────────────

test.describe('P3-H — Storage API cleanup runs without JS errors', () => {
  test.describe.configure({ mode: 'serial' });

  test('15 — no uncaught JS errors from IndexedDB/Cache cleanup after consent change', async ({
    page,
  }) => {
    const errors: string[] = [];
    page.on('pageerror', (err) => errors.push(err.message));

    await page.context().clearCookies();
    await page.goto(WP_BASE, { waitUntil: 'domcontentloaded' });
    await waitForBanner(page);
    await page.waitForSelector('[data-faz-tag="reject-button"]', { timeout: 10_000 });
    await page.click('[data-faz-tag="reject-button"]');

    await page.waitForTimeout(800);

    const storageErrors = errors.filter((e) =>
      /indexedDB|IDBDatabase|CacheStorage|caches\./i.test(e)
    );
    expect(storageErrors).toHaveLength(0);
  });
});
