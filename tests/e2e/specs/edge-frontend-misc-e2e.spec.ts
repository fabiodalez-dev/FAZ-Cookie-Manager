/**
 * Edge-case coverage for assorted frontend behaviours (subsystem: frontend-misc-e2e).
 *
 * Four focus areas, edge cases first:
 *
 *  A. Blocked-embed placeholder keeps its branded styling AFTER the consent
 *     script runs `_fazRemoveStyles()`. The placeholder CSS lives in a SEPARATE
 *     persistent <style data-faz-placeholder-style="1"> block — NOT the
 *     `#faz-style-inline` block that `_fazRemoveStyles()` deletes — so the card
 *     must still be styled (grey bg, 16/9 aspect ratio, branded red CTA) once
 *     <html> flips to .faz-ready. Edge: assert the styling survives the
 *     style-strip step that historically nuked it.
 *
 *  B. CCPA opt-out success message: role="status" live region + running
 *     countdown + auto-close, and "close while success shows" dismisses
 *     immediately. Edge: confirm WITHOUT opting out keeps the legacy immediate
 *     close (no success message).
 *
 *  C. Dynamic document.createElement('script') honours an explicit
 *     `svc.<id>:yes` per-service override: a known-provider URL whose CATEGORY
 *     is denied must still NOT be type-flipped to "javascript/blocked" when the
 *     element carries data-faz-service and svc.<id>:yes is in the store; and a
 *     `svc.<id>:no` must block a script even with no category attribute.
 *
 *  D. The category accordion must NOT collapse when a per-service toggle (or a
 *     .faz-switch / checkbox) inside it is clicked (#136 guard).
 *
 * Self-contained DB state: per_service_consent is enabled and provider cookies
 * (_ga→analytics, _fbp→marketing) are seeded as discovered cookies in
 * test.beforeAll via wpEval so the _services list and _providersToBlock contain
 * google-analytics / facebook regardless of ambient scan state. State is
 * restored in test.afterAll.
 */

import { expect, test } from '../fixtures/wp-fixture';
import { resetDefaultBannerState } from '../utils/seed-defaults';
import { wpEval } from '../utils/wp-env';

const T = 9000;

type FazSettings = Record<string, unknown>;

/* ───────────────────────── DB self-provisioning ───────────────────────── */

/** Enable per_service_consent (banner_control) and return the prior value. */
function enablePerServiceConsent(): boolean {
  const raw = wpEval(`
    $settings = get_option( 'faz_settings', array() );
    if ( ! is_array( $settings ) ) { $settings = array(); }
    if ( ! isset( $settings['banner_control'] ) || ! is_array( $settings['banner_control'] ) ) {
      $settings['banner_control'] = array();
    }
    $prev = ! empty( $settings['banner_control']['per_service_consent'] );
    $settings['banner_control']['per_service_consent'] = true;
    update_option( 'faz_settings', $settings );
    if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
      \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
    }
    if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }
    echo $prev ? '1' : '0';
  `).trim();
  return raw === '1';
}

/** Restore the prior per_service_consent value captured by the enable helper. */
function restorePerServiceConsent(previous: boolean): void {
  wpEval(`
    $settings = get_option( 'faz_settings', array() );
    if ( ! is_array( $settings ) ) { $settings = array(); }
    if ( ! isset( $settings['banner_control'] ) || ! is_array( $settings['banner_control'] ) ) {
      $settings['banner_control'] = array();
    }
    $settings['banner_control']['per_service_consent'] = ${previous ? 'true' : 'false'};
    update_option( 'faz_settings', $settings );
    if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
      \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
    }
    if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }
  `);
}

/**
 * Seed a discovered cookie row (provider → category) so the frontend's
 * _services / _providersToBlock contain the matching service. Idempotent:
 * updates the category if the row already exists.
 */
function seedProviderCookie(name: string, category: string): void {
  wpEval(`
    global $wpdb;
    $table = $wpdb->prefix . 'faz_cookies';
    $existing = $wpdb->get_var( $wpdb->prepare( "SELECT cookie_id FROM {$table} WHERE name = %s", '${name}' ) );
    if ( $existing ) {
      $wpdb->update( $table, array( 'category' => '${category}' ), array( 'cookie_id' => (int) $existing ), array( '%s' ), array( '%d' ) );
    } else {
      $wpdb->insert(
        $table,
        array(
          'name'        => '${name}',
          'category'    => '${category}',
          'domain'      => 'example.com',
          'description' => 'FAZ edge-suite seeded provider cookie',
          'duration'    => '1 year',
          'type'        => 'HTTP',
        ),
        array( '%s', '%s', '%s', '%s', '%s', '%s' )
      );
    }
  `);
}

/** Read the cookie ids of the seeded provider rows so we can delete only ours. */
function flushFazCaches(): void {
  wpEval(`
    if ( class_exists( '\\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Cookie_Controller' ) ) {
      \\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Cookie_Controller::get_instance()->delete_cache();
    }
    if ( class_exists( '\\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Category_Controller' ) ) {
      \\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Category_Controller::get_instance()->delete_cache();
    }
    delete_transient( 'faz_cookie_scripts_map' );
    if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }
    do_action( 'faz_after_create_cookie' );
  `);
}

function deleteSeededProviderCookies(names: string[]): void {
  const inList = names.map((n) => `'${n.replace(/'/g, "")}'`).join(',');
  wpEval(`
    global $wpdb;
    $table = $wpdb->prefix . 'faz_cookies';
    $wpdb->query( "DELETE FROM {$table} WHERE name IN ( ${inList} ) AND description = 'FAZ edge-suite seeded provider cookie'" );
  `);
}

/** Switch the default banner to CCPA + overlay ccpa.json defaults; returns restore meta. */
function setDefaultBannerToCcpa(): { banner_id?: number; original_settings?: string; error?: string } {
  const raw = wpEval(`
    global $wpdb;
    $row = $wpdb->get_row( "SELECT banner_id, settings FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 LIMIT 1" );
    if ( ! $row ) { echo wp_json_encode( array( 'error' => 'no_default_banner' ) ); exit; }
    $original_settings_json = $row->settings;
    $settings = json_decode( $row->settings, true );
    if ( ! isset( $settings['settings'] ) || ! is_array( $settings['settings'] ) ) { $settings['settings'] = array(); }
    $settings['settings']['applicableLaw'] = 'ccpa';
    $ccpa_path = trailingslashit( WP_PLUGIN_DIR ) . 'faz-cookie-manager/admin/modules/banners/includes/configs/ccpa.json';
    if ( file_exists( $ccpa_path ) ) {
      $ccpa_defaults = json_decode( file_get_contents( $ccpa_path ), true );
      if ( is_array( $ccpa_defaults ) && isset( $ccpa_defaults['config'] ) ) {
        $existing_config = isset( $settings['config'] ) && is_array( $settings['config'] ) ? $settings['config'] : array();
        $settings['config'] = array_replace_recursive( $existing_config, $ccpa_defaults['config'] );
      }
    }
    $wpdb->update(
      $wpdb->prefix . 'faz_banners',
      array( 'settings' => wp_json_encode( $settings ) ),
      array( 'banner_id' => $row->banner_id ),
      array( '%s' ),
      array( '%d' )
    );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    faz_clear_banner_template_cache();
    echo wp_json_encode( array( 'banner_id' => $row->banner_id, 'original_settings' => $original_settings_json ) );
  `).trim();
  return JSON.parse(raw);
}

function restoreBanner(meta: { banner_id?: number; original_settings?: string }): void {
  if (!meta || typeof meta.original_settings !== 'string' || !meta.banner_id) return;
  const b64 = Buffer.from(meta.original_settings, 'utf8').toString('base64');
  wpEval(`
    global $wpdb;
    $settings = base64_decode( '${b64}' );
    $wpdb->update(
      $wpdb->prefix . 'faz_banners',
      array( 'settings' => $settings ),
      array( 'banner_id' => ${meta.banner_id} ),
      array( '%s' ),
      array( '%d' )
    );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    faz_clear_banner_template_cache();
  `);
}

/* ─────────────────────────── shared lifecycle ─────────────────────────── */

const SEEDED_COOKIES: Array<[string, string]> = [
  ['_ga', 'analytics'],
  ['_fbp', 'marketing'],
];

let prevPerService = false;

test.beforeAll(() => {
  resetDefaultBannerState();
  prevPerService = enablePerServiceConsent();
  for (const [name, cat] of SEEDED_COOKIES) seedProviderCookie(name, cat);
  flushFazCaches();
});

test.afterAll(() => {
  deleteSeededProviderCookies(SEEDED_COOKIES.map(([n]) => n));
  restorePerServiceConsent(prevPerService);
  flushFazCaches();
  resetDefaultBannerState();
});

/* ─────────────────────────── A. placeholder ───────────────────────────── */

test.describe('A. Blocked-embed placeholder keeps branded styling after the JS runs', () => {
  let url = '';
  let postId = '';

  test.beforeAll(() => {
    postId = wpEval(`
      $id = wp_insert_post( array(
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_title'   => 'FAZ edge YouTube placeholder',
        'post_name'    => 'faz-edge-yt-placeholder',
        'post_content' => '<iframe width="560" height="315" src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="YouTube"></iframe>',
      ) );
      echo (int) $id;
    `).trim();
    url = wpEval(`echo get_permalink( ${Number(postId)} );`).trim();
  });

  test.afterAll(() => {
    if (postId) wpEval(`wp_delete_post( ${Number(postId)}, true );`);
  });

  test('placeholder CSS lives in a persistent <style> that survives the style-strip', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
      await page.goto(url, { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(
        () => document.documentElement.classList.contains('faz-ready'),
        undefined,
        { timeout: T },
      );
      await page.waitForTimeout(300);

      const probe = await page.evaluate(() => {
        const inlineStripped = document.getElementById('faz-style-inline') === null;
        const persistent = document.querySelector('style[data-faz-placeholder-style="1"]');
        const card = document.querySelector('.faz-placeholder--video') as HTMLElement | null;
        if (!card) return { card: false, inlineStripped, persistent: !!persistent };
        const cs = getComputedStyle(card);
        const btn = card.querySelector('.faz-placeholder-btn') as HTMLElement | null;
        const svc = card.querySelector('.faz-placeholder-svcname') as HTMLElement | null;
        return {
          card: true,
          inlineStripped,
          persistent: !!persistent,
          bg: cs.backgroundColor,
          height: Math.round(card.getBoundingClientRect().height),
          btnBg: btn ? getComputedStyle(btn).backgroundColor : null,
          svcname: svc ? (svc.textContent || '').trim() : null,
        };
      });

      // Edge: the #faz-style-inline block was removed (the destructive step)
      // but the dedicated persistent placeholder <style> remains.
      expect(probe.inlineStripped, '#faz-style-inline is stripped after reveal').toBe(true);
      expect(probe.persistent, 'persistent placeholder <style> block survives').toBe(true);

      expect(probe.card, 'a video placeholder rendered server-side').toBe(true);
      // Styled grey background (NOT the transparent unstyled symptom).
      expect(probe.bg).not.toBe('rgba(0, 0, 0, 0)');
      expect(probe.bg).toBe('rgb(233, 234, 236)');
      // 16/9 aspect ratio on a ~560px card → ~315px, well above the
      // unstyled content height (~160px).
      expect(probe.height).toBeGreaterThan(250);
      // YouTube brand accent on the CTA + the service name label.
      expect(probe.btnBg).toBe('rgb(255, 0, 0)');
      expect(probe.svcname).toBe('YouTube');
    } finally {
      await ctx.close();
    }
  });
});

/* ─────────────────────────── B. CCPA opt-out ──────────────────────────── */

test.describe('B. CCPA opt-out success message (role=status + countdown + auto-close)', () => {
  let meta: { banner_id?: number; original_settings?: string; error?: string } = {};

  test.beforeAll(() => {
    meta = setDefaultBannerToCcpa();
  });

  test.afterAll(() => {
    restoreBanner(meta);
  });

  test.beforeEach(async ({ page }) => {
    await page.context().clearCookies();
  });

  test('opt-out + confirm → role=status success, decrementing countdown, then close dismisses', async ({ page, wpBaseURL }) => {
    expect(meta.error, 'install has a default banner').toBeUndefined();

    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    const banner = page.locator('.faz-consent-container').first();
    await expect(banner, 'CCPA banner shows on first visit').toBeVisible({ timeout: T });

    await page.locator('[data-faz-tag="donotsell-button"]').first().click();
    const popup = page.locator('[data-faz-tag="optout-popup"]').first();
    await expect(popup, 'opt-out popup opens').toBeVisible({ timeout: T });
    await page.locator('#fazCCPAOptOut').check();
    await page.locator('[data-faz-tag="optout-confirm-button"]').first().click();

    const success = page.locator('[data-faz-tag="optout-success"]').first();
    await expect(success, 'success message becomes visible').toBeVisible({ timeout: T });

    // Edge / a11y: the live region is announced via role="status".
    await expect(success, 'success element is a role=status live region').toHaveAttribute('role', 'status');
    await expect(success).toContainText('opt-out preference has been honored', { ignoreCase: true });
    await expect(
      page.locator('[data-faz-tag="optout-buttons"]').first(),
      'action buttons hidden while success shows',
    ).toBeHidden();

    // Countdown is running: the visible seconds value decreases over ~2.2s.
    const subtext = page.locator('[data-faz-tag="optout-success-subtext"]').first();
    const first = (await subtext.innerText()).match(/\d+/)?.[0];
    expect(first, 'countdown shows a starting number').toBeTruthy();
    await page.waitForTimeout(2200);
    const second = (await subtext.innerText()).match(/\d+/)?.[0];
    expect(Number(second), 'countdown decremented').toBeLessThan(Number(first));

    // Opt-out persisted (action recorded) even mid-countdown.
    const recorded = await page.evaluate(() =>
      (document.cookie.match(/fazcookie-consent=([^;]+)/)?.[1] ?? '').includes('action%3Ayes') ||
      decodeURIComponent(document.cookie).includes('action:yes'));
    expect(recorded, 'consent cookie records the opt-out (action:yes)').toBeTruthy();

    // Closing while the success shows dismisses immediately (dismiss-on-close).
    await page.locator('[data-faz-tag="optout-close"]').first().click();
    await expect(banner, 'banner dismissed immediately on close-during-success').toBeHidden({ timeout: T });
  });

  test('confirm WITHOUT opting out (checkbox unchecked) → no success message, immediate close', async ({ page, wpBaseURL }) => {
    expect(meta.error).toBeUndefined();

    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    const banner = page.locator('.faz-consent-container').first();
    await expect(banner).toBeVisible({ timeout: T });

    await page.locator('[data-faz-tag="donotsell-button"]').first().click();
    await expect(page.locator('[data-faz-tag="optout-popup"]').first()).toBeVisible({ timeout: T });

    // Leave the opt-out checkbox UNCHECKED (edge: the non-opt-out branch).
    await page.locator('[data-faz-tag="optout-confirm-button"]').first().click();

    await expect(
      page.locator('[data-faz-tag="optout-success"]').first(),
      'no success message when not opted out',
    ).toBeHidden();
    await expect(banner, 'banner closes immediately on a non-opt-out confirm').toBeHidden({ timeout: T });
  });

  test('countdown auto-closes the banner without any user click', async ({ page, wpBaseURL }) => {
    expect(meta.error).toBeUndefined();

    await page.goto(wpBaseURL, { waitUntil: 'domcontentloaded' });
    const banner = page.locator('.faz-consent-container').first();
    await expect(banner).toBeVisible({ timeout: T });

    await page.locator('[data-faz-tag="donotsell-button"]').first().click();
    await expect(page.locator('[data-faz-tag="optout-popup"]').first()).toBeVisible({ timeout: T });
    await page.locator('#fazCCPAOptOut').check();

    // Capture the auto-close timeout callback the success flow schedules. The
    // real wiring uses a ~15s setTimeout (_FAZ_OPTOUT_SUCCESS_DISMISS_MS); we
    // intercept the LONG (>=5s) timer registered immediately after the confirm
    // click so we can invoke its callback deterministically rather than waiting
    // 15 wall-clock seconds — the assertion still proves the auto-close path
    // (no user click on the close button) tears the banner down.
    await page.evaluate(() => {
      const w = window as unknown as {
        __fazAutoClose?: TimerHandler | null;
        setTimeout: typeof setTimeout;
      };
      w.__fazAutoClose = null;
      const orig = w.setTimeout.bind(window);
      w.setTimeout = ((fn: TimerHandler, ms?: number, ...rest: unknown[]) => {
        if (typeof fn === 'function' && typeof ms === 'number' && ms >= 5000) {
          w.__fazAutoClose = fn;
          // Return a real (long) handle so the production clear path still works.
        }
        return orig(fn as TimerHandler, ms as number, ...(rest as []));
      }) as typeof setTimeout;
    });

    await page.locator('[data-faz-tag="optout-confirm-button"]').first().click();

    const success = page.locator('[data-faz-tag="optout-success"]').first();
    await expect(success).toBeVisible({ timeout: T });

    // The success flow must have scheduled a long-running auto-close timer.
    const scheduled = await page.evaluate(
      () => typeof (window as unknown as { __fazAutoClose?: unknown }).__fazAutoClose === 'function',
    );
    expect(scheduled, 'an auto-close timeout was scheduled by the success flow').toBe(true);

    // Fire the captured auto-close callback (the thing the 15s timer would run).
    await page.evaluate(() => {
      const fn = (window as unknown as { __fazAutoClose?: () => void }).__fazAutoClose;
      if (typeof fn === 'function') fn();
    });

    await expect(banner, 'banner auto-dismisses when the countdown elapses').toBeHidden({ timeout: T });
  });
});

/* ───────────── C. dynamic createElement honours svc.<id> ──────────────── */

test.describe('C. Dynamic createElement(script) honours per-service svc.<id> overrides', () => {
  test.beforeEach(async ({ page, wpBaseURL }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL + '/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible({ timeout: T });
    // Per-service consent must be active for the svc.<id> branch to engage.
    // The runtime exposes the store on window._fazConfig (script.js aliases the
    // module-local `_fazStore` to it). The flag is emitted truthy (the runtime
    // uses `if (_fazStore._perServiceConsent)`, so a "1"/true both engage it);
    // assert truthiness AND that the service list is present.
    const state = await page.evaluate(() => {
      const cfg = (window as unknown as {
        _fazConfig?: { _perServiceConsent?: unknown; _services?: unknown };
      })._fazConfig;
      return {
        enabled: !!cfg?._perServiceConsent,
        services: Array.isArray(cfg?._services) ? (cfg!._services as unknown[]).length : -1,
      };
    });
    expect(state.enabled, 'per_service_consent is active on the frontend store').toBe(true);
    expect(state.services, 'the detected-service list is present').toBeGreaterThan(0);
  });

  test('google-analytics URL is blocked by default (analytics denied pre-consent)', async ({ page }) => {
    const state = await page.evaluate(() => {
      const s = document.createElement('script');
      s.setAttribute('data-faz-service', 'google-analytics');
      // Known google-analytics provider pattern → matched by _fazShouldBlockProvider.
      s.src = 'https://www.googletagmanager.com/gtag/js?id=G-EDGE-TEST';
      document.head.appendChild(s);
      return { type: s.getAttribute('type') };
    });
    // No svc override + analytics category denied → script is type-flipped.
    expect(state.type).toBe('javascript/blocked');
  });

  test('svc.google-analytics:yes unblocks a denied-category provider script', async ({ page }) => {
    const state = await page.evaluate(() => {
      const fz = (window as unknown as { fazcookie?: Record<string, (k: string, v: string) => void> }).fazcookie;
      // Explicit per-service grant — diverges from the (still-denied) category.
      if (fz && typeof fz._fazSetInStore === 'function') {
        fz._fazSetInStore('svc.google-analytics', 'yes');
      }
      const s = document.createElement('script');
      s.setAttribute('data-faz-service', 'google-analytics');
      s.src = 'https://www.googletagmanager.com/gtag/js?id=G-EDGE-TEST';
      document.head.appendChild(s);
      return {
        type: s.getAttribute('type'),
        hadSetter: !!(fz && typeof fz._fazSetInStore === 'function'),
      };
    });
    expect(state.hadSetter, 'window.fazcookie._fazSetInStore is exposed').toBe(true);
    // The explicit svc:yes override must win over the denied analytics category.
    expect(state.type).not.toBe('javascript/blocked');
  });

  test('svc.<id>:no blocks a script via data-faz-service even with no data-fazcookie category', async ({ page }) => {
    const state = await page.evaluate(() => {
      const fz = (window as unknown as { fazcookie?: Record<string, (k: string, v: string) => void> }).fazcookie;
      if (fz && typeof fz._fazSetInStore === 'function') {
        fz._fazSetInStore('svc.google-analytics', 'no');
      }
      const s = document.createElement('script');
      s.setAttribute('data-faz-service', 'google-analytics');
      // src is NOT a known provider pattern and there is no data-fazcookie
      // category — the ONLY signal is the explicit svc:no override.
      s.src = 'https://cdn.example.org/edge-no-pattern.js';
      document.head.appendChild(s);
      return { type: s.getAttribute('type') };
    });
    // Explicit per-service denial blocks the script outright.
    expect(state.type).toBe('javascript/blocked');
  });

  test('a non-provider script with no service id and no category is left untouched (safe default)', async ({ page }) => {
    const state = await page.evaluate(() => {
      window.__fazEdgeC = false;
      const s = document.createElement('script');
      s.textContent = 'window.__fazEdgeC = true;';
      s.src = 'https://cdn.example.org/edge-harmless.js';
      document.head.appendChild(s);
      return { type: s.getAttribute('type') };
    });
    expect(state.type).not.toBe('javascript/blocked');
  });
});

/* ────────────────────── D. accordion guard (#136) ─────────────────────── */

test.describe('D. Accordion does not collapse on a service-toggle click', () => {
  test.beforeEach(async ({ page, wpBaseURL }) => {
    await page.context().clearCookies();
    await page.goto(wpBaseURL + '/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible({ timeout: T });
  });

  async function openPreferenceCenter(page: import('@playwright/test').Page): Promise<void> {
    const customizeOpened = await page.evaluate(() => {
      const sel = [
        '[data-faz-tag="settings-button"] button',
        '[data-faz-tag="settings-button"]',
        '.faz-btn-customize',
      ];
      for (const s of sel) {
        const el = document.querySelector(s) as HTMLElement | null;
        if (el) { el.click(); return true; }
      }
      return false;
    });
    expect(customizeOpened, 'a customize / settings button exists').toBe(true);
    await expect(page.locator('[data-faz-tag="detail"]')).toBeVisible({ timeout: T });
  }

  /**
   * Resolve a NON-necessary category accordion (its `.faz-switch` carries an
   * ENABLED checkbox, unlike the always-on necessary one). Returns its id suffix.
   */
  async function pickOptionalAccordion(page: import('@playwright/test').Page): Promise<{ id: string }> {
    const info = await page.evaluate(() => {
      const accs = Array.from(document.querySelectorAll<HTMLElement>('.faz-accordion'));
      for (const acc of accs) {
        if (/necessary$/i.test(acc.id)) continue;
        const cb = acc.querySelector<HTMLInputElement>('.faz-switch input[type="checkbox"]');
        if (cb && !cb.disabled) return { id: acc.id };
      }
      // Fall back to ANY accordion that has a .faz-switch at all.
      for (const acc of accs) {
        if (acc.querySelector('.faz-switch')) return { id: acc.id };
      }
      return { id: '' };
    });
    expect(info.id, 'an optional category accordion with a switch exists').not.toBe('');
    return info;
  }

  test('clicking a .faz-switch toggle inside an expanded accordion keeps it open (#136)', async ({ page }) => {
    await openPreferenceCenter(page);
    const { id } = await pickOptionalAccordion(page);
    const accordion = page.locator(`#${id}`);

    // Expand it via its header button.
    await accordion.locator('.faz-accordion-btn').first().click();
    await expect(accordion, 'accordion expands on header click').toHaveClass(/faz-accordion-active/, { timeout: T });

    // Click the .faz-switch wrapper (the category toggle). The guard
    // (target.closest('.faz-switch')) must short-circuit and leave the
    // accordion EXACTLY as it is — the historic bug collapsed it here.
    const clicked = await page.evaluate((accId) => {
      const acc = document.getElementById(accId);
      if (!acc) return { ok: false, where: 'no-accordion' };
      const sw = acc.querySelector('.faz-switch, .faz-service-toggle') as HTMLElement | null;
      if (!sw) return { ok: false, where: 'no-switch' };
      sw.click();
      return { ok: true, where: 'clicked' };
    }, id);
    expect(clicked.ok, `a switch/service-toggle was found and clicked (${clicked.where})`).toBe(true);

    // Give the listener a tick to (incorrectly) collapse it, if the guard were broken.
    await page.waitForTimeout(150);
    await expect(accordion, 'accordion stays open after the toggle click').toHaveClass(/faz-accordion-active/);
  });

  test('clicking the checkbox INPUT inside the switch keeps the accordion open', async ({ page }) => {
    await openPreferenceCenter(page);
    const { id } = await pickOptionalAccordion(page);
    const accordion = page.locator(`#${id}`);

    await accordion.locator('.faz-accordion-btn').first().click();
    await expect(accordion).toHaveClass(/faz-accordion-active/, { timeout: T });

    // The guard also short-circuits on `target.type === 'checkbox'`.
    const clicked = await page.evaluate((accId) => {
      const acc = document.getElementById(accId);
      const cb = acc?.querySelector('.faz-switch input[type="checkbox"]') as HTMLInputElement | null;
      if (!cb) return false;
      cb.click();
      return true;
    }, id);
    expect(clicked, 'a checkbox input was found and clicked').toBe(true);

    await page.waitForTimeout(150);
    await expect(accordion, 'accordion stays open after the checkbox click').toHaveClass(/faz-accordion-active/);
  });

  test('a click on the accordion header (not a toggle) DOES collapse it (guard is scoped)', async ({ page }) => {
    await openPreferenceCenter(page);
    const { id } = await pickOptionalAccordion(page);
    const accordion = page.locator(`#${id}`);

    await accordion.locator('.faz-accordion-btn').first().click();
    await expect(accordion).toHaveClass(/faz-accordion-active/, { timeout: T });

    // Sanity counter-check: the guard only protects toggles — the header still toggles.
    await accordion.locator('.faz-accordion-btn').first().click();
    await expect(accordion, 'header click collapses the accordion').not.toHaveClass(/faz-accordion-active/, { timeout: T });
  });
});
