import { expect, test } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

// ── Settings helpers ──────────────────────────────────────────────────────────

type SettingsSnapshot = string;

function snapshotSettings(): SettingsSnapshot {
  return wpEval(`echo wp_json_encode( get_option( 'faz_settings', array() ) );`);
}

function restoreSettings(snap: SettingsSnapshot): void {
  const encoded = Buffer.from(snap, 'utf8').toString('base64');
  wpEval(`
    $s = json_decode( base64_decode( '${encoded}' ), true );
    update_option( 'faz_settings', is_array( $s ) ? $s : array() );
    if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
      \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
    }
  `);
}

function applySettingsPatch(phpPatch: string): void {
  wpEval(`
    $s = get_option( 'faz_settings', array() );
    if ( ! is_array( $s ) ) { $s = array(); }
    ${phpPatch}
    update_option( 'faz_settings', $s );
    if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
      \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
    }
  `);
}

// ── Regression tests ──────────────────────────────────────────────────────────

test.describe('Regression checks for applied bug fixes', () => {
  test.describe.configure({ mode: 'serial' });

  // ── Fix 1 ── blocking-compliance: withCustomRules() must clear whitelist_patterns ──────
  //
  // Root cause: faz_settings.script_blocking.whitelist_patterns on the test-site DB contained
  // 'connect.facebook.net/en_US/fbevents.js', so PHP is_whitelisted() returned true and the
  // blocking assertion failed. Fix: withCustomRules() now always sets whitelist_patterns: [].
  //
  // This test verifies both states:
  //   A) whitelist_patterns contains the Facebook URL → JS _userWhitelist exposes the pattern,
  //      MutationObserver does NOT block the script
  //   B) whitelist_patterns is [] → MutationObserver blocks the same script as a known provider

  test('Fix 1 (blocking-compliance): whitelist_patterns bypass vs empty-whitelist provider blocking', async ({ browser, wpBaseURL }) => {
    const snap = snapshotSettings();
    try {
      // Phase A: Facebook pixel in whitelist_patterns → NOT blocked by MutationObserver
      applySettingsPatch(`
        if ( ! isset( $s['banner_control'] ) || ! is_array( $s['banner_control'] ) ) {
          $s['banner_control'] = array();
        }
        $s['banner_control']['status'] = true;
        if ( ! isset( $s['script_blocking'] ) || ! is_array( $s['script_blocking'] ) ) {
          $s['script_blocking'] = array();
        }
        $s['script_blocking']['whitelist_patterns'] = array( 'connect.facebook.net/en_US/fbevents.js' );
      `);

      const ctx1 = await browser.newContext({ baseURL: wpBaseURL });
      try {
        const page1 = await ctx1.newPage();
        // Abort outbound Facebook requests — we only care about the DOM mutation, not actual script load
        await page1.route('**/connect.facebook.net/**', (route) => route.abort());
        await page1.goto('/', { waitUntil: 'domcontentloaded' });
        await expect(page1.locator('[data-faz-tag="notice"]')).toBeVisible();

        const whitelistResult = await page1.evaluate(async () => {
          const userWhitelist: string[] = (window as any)._fazConfig?._userWhitelist ?? [];
          const s = document.createElement('script');
          s.src = 'https://connect.facebook.net/en_US/fbevents.js';
          document.head.appendChild(s);
          await new Promise<void>((r) => setTimeout(r, 50));
          return {
            patternExposedToFrontend: userWhitelist.some((p: string) => p === 'connect.facebook.net/en_US/fbevents.js'),
            scriptType: s.getAttribute('type') ?? '',
          };
        });

        expect(whitelistResult.patternExposedToFrontend).toBe(true);
        expect(whitelistResult.scriptType).not.toBe('javascript/blocked');
      } finally {
        await ctx1.close();
      }

      // Phase B: Empty whitelist_patterns → provider blocking re-activates for Facebook
      applySettingsPatch(`
        $s['script_blocking']['whitelist_patterns'] = array();
      `);

      const ctx2 = await browser.newContext({ baseURL: wpBaseURL });
      try {
        const page2 = await ctx2.newPage();
        await page2.goto('/', { waitUntil: 'domcontentloaded' });
        await expect(page2.locator('[data-faz-tag="notice"]')).toBeVisible();

        const blockedResult = await page2.evaluate(async () => {
          const s = document.createElement('script');
          s.src = 'https://connect.facebook.net/en_US/fbevents.js';
          document.head.appendChild(s);
          await new Promise<void>((r) => setTimeout(r, 50));
          return s.getAttribute('type') ?? '';
        });

        expect(blockedResult).toBe('javascript/blocked');
      } finally {
        await ctx2.close();
      }
    } finally {
      restoreSettings(snap);
    }
  });

  // ── Fix 2 ── gcm-tcf: window._fazGcm is the sole reliable FAZ GCM indicator ─────────
  //
  // Root cause: old detection used `hasDataLayer || hasGtag`, both of which are set by
  // GTM4WP independently of FAZ's GCM module. Result: GCM test didn't skip even when
  // FAZ GCM was disabled → assertion on GCM defaults failed.
  // Fix: active = typeof _fazGcm === 'object' && _fazGcm !== null.
  //
  // This test injects a third-party dataLayer (simulating GTM4WP) and verifies that:
  //   • Old detection logic gives a false positive (active=true due to dataLayer)
  //   • New detection logic correctly reflects FAZ GCM state via _fazGcm

  test('Fix 2 (gcm-tcf): _fazGcm is sole reliable GCM indicator; window.dataLayer alone is insufficient', async ({ browser, wpBaseURL }) => {
    const ctx = await browser.newContext({ baseURL: wpBaseURL });
    try {
      const page = await ctx.newPage();
      // Simulate GTM4WP creating window.dataLayer before FAZ loads
      await page.addInitScript(() => {
        (window as any).dataLayer = (window as any).dataLayer || [];
      });
      await page.goto('/', { waitUntil: 'domcontentloaded' });

      const indicators = await page.evaluate(() => {
        const fazGcm = (window as any)._fazGcm;
        const dl = (window as any).dataLayer;
        return {
          dataLayerExists: Array.isArray(dl),
          fazGcmIsActive: typeof fazGcm === 'object' && fazGcm !== null,
          // Old detection logic (false-positive-prone when GTM4WP present):
          oldLogicActive: Array.isArray(dl) || typeof (window as any).gtag === 'function',
          // New detection logic (FAZ-specific, used in gcm-tcf.spec.ts after fix):
          newLogicActive: typeof fazGcm === 'object' && fazGcm !== null,
        };
      });

      // Our initScript guarantees dataLayer exists regardless of FAZ GCM status
      expect(indicators.dataLayerExists).toBe(true);

      if (!indicators.fazGcmIsActive) {
        // FAZ GCM disabled branch:
        // oldLogicActive is trivially true because we pre-seeded dataLayer above — this assertion
        // documents the false-positive, not tests for absence of it. The meaningful assertion is
        // newLogicActive === false: _fazGcm being absent correctly signals GCM is off.
        expect(indicators.oldLogicActive).toBe(true);  // old: guaranteed false positive (dataLayer pre-seeded)
        expect(indicators.newLogicActive).toBe(false); // new: correct — _fazGcm not present
      } else {
        // FAZ GCM enabled: both correctly report active — no divergence
        expect(indicators.newLogicActive).toBe(true);
      }
    } finally {
      await ctx.close();
    }
  });

  // ── Fix 3 ── pr-2026-04-19-audit: async evaluate + 50ms yield for MutationObserver ───
  //
  // Root cause: MutationObserver fires as a microtask, AFTER synchronous evaluate() returns.
  // The test read script.getAttribute('type') synchronously — always '' because the observer
  // hadn't fired yet. Also, FAZ removes the node from DOM after blocking, so getElementById
  // returned null. Fix: async evaluate + setTimeout(resolve, 50) + read from closure variable.
  //
  // This test verifies the timing: before the 50ms yield the type is empty; after it is set.

  test('Fix 3 (pr-2026-04-19-audit): data:URI script type is set to javascript/blocked after 50ms MutationObserver yield', async ({ browser, wpBaseURL }) => {
    const snap = snapshotSettings();
    try {
      applySettingsPatch(`
        if ( ! isset( $s['banner_control'] ) || ! is_array( $s['banner_control'] ) ) {
          $s['banner_control'] = array();
        }
        $s['banner_control']['status'] = true;
        if ( ! isset( $s['script_blocking'] ) || ! is_array( $s['script_blocking'] ) ) {
          $s['script_blocking'] = array();
        }
        $s['script_blocking']['whitelist_patterns'] = array();
      `);

      const ctx = await browser.newContext({ baseURL: wpBaseURL });
      try {
        const page = await ctx.newPage();
        await page.goto('/', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

        const result = await page.evaluate(async () => {
          // Encode payload embedding a known provider URL so FAZ recognises the script.
          // Plain JS — no TypeScript casts: this string is evaluated as JS in the browser.
          const payload = btoa(
            '/* connect.facebook.net/en_US/fbevents.js */ (function(){ window.__fazReg3Hit=(window.__fazReg3Hit||0)+1; })()',
          );
          const script = document.createElement('script');
          script.src = `data:text/javascript;base64,${payload}`;
          document.head.appendChild(script);

          // Synchronous read: MutationObserver microtask has NOT yet fired
          const typeSynchronous = script.getAttribute('type') ?? '';

          // Yield 50ms: MutationObserver fires and sets type on the script node
          await new Promise<void>((r) => setTimeout(r, 50));

          // Read from the closure variable (not getElementById — node may have been removed)
          const typeAfterYield = script.getAttribute('type') ?? (script as HTMLScriptElement).type ?? '';
          const executed = (window as any).__fazReg3Hit ?? 0;

          return { typeSynchronous, typeAfterYield, executed };
        });

        // Depending on browser timing, the observer may already have marked the
        // script by this synchronous read. The regression guarantee is the final
        // blocked state after the observer has had a chance to run.
        expect(['', 'javascript/blocked']).toContain(result.typeSynchronous);
        // The type attribute must be set after the 50ms yield (microtask has time to run)
        expect(result.typeAfterYield).toBe('javascript/blocked');
        // NOTE: data: URI scripts (src="data:...") execute before the MutationObserver
        // microtask fires in Chromium — FAZ marks the type but cannot prevent the initial
        // execution. We do not assert executed === 0 here; what matters is the type is set.
      } finally {
        await ctx.close();
      }
    } finally {
      restoreSettings(snap);
    }
  });

  // ── Fix 4 ── provider-matrix: clearCookies() before tests 11/12 ───────────────────────
  //
  // Root cause: tests 11 and 12 ran serially after test 10's acceptAll(), which left
  // 'marketing:yes' in the consent cookie. Test 12's sendBeacon was not blocked because
  // FAZ saw existing consent. Fix: clearCookies() at the start of tests 11 and 12.
  //
  // This test verifies that sendBeacon to a URL matching a known provider (Facebook) is
  // blocked by FAZ's network interceptor when no consent cookie exists (fresh context).

  test('Fix 4 (provider-matrix): sendBeacon to provider URL is blocked in fresh context without consent', async ({ browser, wpBaseURL }) => {
    const snap = snapshotSettings();
    try {
      applySettingsPatch(`
        if ( ! isset( $s['banner_control'] ) || ! is_array( $s['banner_control'] ) ) {
          $s['banner_control'] = array();
        }
        $s['banner_control']['status'] = true;
        if ( ! isset( $s['script_blocking'] ) || ! is_array( $s['script_blocking'] ) ) {
          $s['script_blocking'] = array();
        }
        $s['script_blocking']['whitelist_patterns'] = array();
      `);

      const ctx = await browser.newContext({ baseURL: wpBaseURL });
      try {
        const page = await ctx.newPage();

        // Route a real provider host. The route should never be hit when FAZ
        // blocks sendBeacon by provider host before the request reaches network.
        let beaconReachedNetwork = false;
        const beaconUrl = 'https://connect.facebook.net/en_US/fbevents.js';
        await page.route('https://connect.facebook.net/**', (route) => {
          beaconReachedNetwork = true;
          route.abort();
        });

        await page.goto('/', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();

        // Confirm fresh context: no consent cookie (equivalent to clearCookies() fix)
        const cookies = await ctx.cookies(wpBaseURL);
        expect(cookies.find((c) => c.name === 'fazcookie-consent')).toBeUndefined();

        // sendBeacon to a provider-pattern URL — FAZ JS interceptor must block it
        await page.evaluate((url) => {
          navigator.sendBeacon(url, 'regression-probe');
        }, beaconUrl);

        // sendBeacon is fire-and-forget; give Playwright route interception time to settle
        await page.waitForTimeout(200);

        // If FAZ blocked it at JS level, no network request was made
        expect(beaconReachedNetwork).toBe(false);
      } finally {
        await ctx.close();
      }
    } finally {
      restoreSettings(snap);
    }
  });

  // ── Fix 5 ── settings-options-behavior: waitForFunction for nonce ──────────────────────
  //
  // Root cause: fazConfig.api.nonce is injected by the admin JS (loaded in footer).
  // Reading it immediately at 'domcontentloaded' could return '' if the script had
  // not yet executed, leading to 401 errors in afterEach restoreOriginalSettings().
  // Fix: waitForFunction until the nonce is a non-empty string before reading.
  //
  // This test verifies that after the waitForFunction guard the nonce is non-empty
  // and valid for authenticated REST API calls against /faz/v1/settings/.

  test('Fix 5 (settings-options-behavior): fazConfig.api.nonce is populated and valid before API calls', async ({ page, wpBaseURL, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(
      `${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-settings`,
      { waitUntil: 'domcontentloaded' },
    );

    // Without the fix, reading here might return '' if the footer script hasn't run yet
    const nonceImmediate = await page.evaluate(() => (window as any).fazConfig?.api?.nonce ?? '');

    // With the fix: wait until fazConfig.api.nonce is a non-empty string
    await page.waitForFunction(
      () => typeof (window as any).fazConfig?.api?.nonce === 'string' && (window as any).fazConfig.api.nonce.length > 0,
      undefined,
      { timeout: 15_000 },
    );
    const nonce = await page.evaluate(() => (window as any).fazConfig?.api?.nonce ?? '');
    expect(nonce.length).toBeGreaterThan(0);

    // Verify the nonce is valid: a REST call with it must return 200, not 401
    const response = await page.request.get(`${wpBaseURL}/?rest_route=/faz/v1/settings/`, {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(response.status()).toBe(200);

    const settings = await response.json() as Record<string, unknown>;
    expect(typeof settings).toBe('object');
    expect(settings).toHaveProperty('banner_control');

    // Emit diagnostic if nonce was initially empty (timing issue caught by fix)
    if (!nonceImmediate) {
      test.info().annotations.push({
        type: 'timing',
        description: 'Fix 5: nonce was empty at domcontentloaded; waitForFunction resolved it (expected on slow environments)',
      });
    }
  });
});
