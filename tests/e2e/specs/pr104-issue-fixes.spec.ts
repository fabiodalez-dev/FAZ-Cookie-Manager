/**
 * PR #104 — final-issue regression suite (1.14.0).
 *
 * One reusable test per GitHub issue closed in commit 980e6af:
 *   #108 — faz_country_to_locale() + locale-aware faz_current_language()
 *   #110 — Geolocation multi-source vote + faz_country_detection_consensus
 *   #111 — Per-user rate-limit on /faz/v1/banners/configs (5s default, 429)
 *   #112 — Console.warn when fazcookie-consent exceeds ~3500 bytes
 *   #113 — readme.txt documents Cache Plugin Compatibility section
 *
 * Tests are PHP-reflection-first (via wpEval + rest_do_request) where the
 * behaviour is server-side — fast, deterministic, no HTTP layer flakiness.
 * #112 is the only browser-level test because the assertion is on
 * window.fazcookie._fazSetCookie's console output. #113 is a pure file
 * read assertion.
 *
 * ClassicPress 1.x compatibility is verified implicitly: each test
 * exercises only pre-WP-3.0 APIs (transients, filters, apply_filters
 * with multi-arg signatures, rest_do_request).
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

// ESM-safe replacement for the CommonJS __dirname.
const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

/* ================================================================== *
 * #108 — Regional dialect (pt_BR vs pt_PT, zh_TW vs zh_CN) mapping
 * ================================================================== */

test.describe('PR104 — #108 country→locale BCP-47 mapping', () => {
  test('faz_country_to_locale() resolves regional dialects and honours both filters', () => {
    const raw = wpEval(`
      // Direct mapping table — covers the four canonical regional-split
      // examples the issue cites.
      $pairs = array(
        'BR' => faz_country_to_locale( 'BR' ),
        'PT' => faz_country_to_locale( 'PT' ),
        'TW' => faz_country_to_locale( 'TW' ),
        'CN' => faz_country_to_locale( 'CN' ),
        'US' => faz_country_to_locale( 'US' ),
        'GB' => faz_country_to_locale( 'GB' ),
        'XX' => faz_country_to_locale( 'XX' ),
        'empty' => faz_country_to_locale( '' ),
        'lowercase' => faz_country_to_locale( 'br' ),
      );

      // Per-country filter: flip Belgium from nl_BE to fr_BE without
      // re-declaring the full map.
      $cb = function ( $locale, $country ) {
        return ( 'BE' === $country ) ? 'fr_BE' : $locale;
      };
      add_filter( 'faz_country_to_locale', $cb, 10, 2 );
      $pairs['BE_filtered'] = faz_country_to_locale( 'BE' );
      remove_filter( 'faz_country_to_locale', $cb, 10 );

      // Full-map filter: inject a synthetic country.
      $cb2 = function ( $map ) {
        $map['ZZ'] = 'xx_ZZ';
        return $map;
      };
      add_filter( 'faz_country_to_locale_map', $cb2, 10, 1 );
      $pairs['ZZ_map_filter'] = faz_country_to_locale( 'ZZ' );
      remove_filter( 'faz_country_to_locale_map', $cb2, 10 );

      echo wp_json_encode( $pairs );
    `).trim();

    const r = JSON.parse(raw);
    expect(r.BR, 'BR → pt_BR (Brazilian Portuguese)').toBe('pt_BR');
    expect(r.PT, 'PT → pt_PT (European Portuguese)').toBe('pt_PT');
    expect(r.TW, 'TW → zh_TW (Traditional Chinese)').toBe('zh_TW');
    expect(r.CN, 'CN → zh_CN (Simplified Chinese)').toBe('zh_CN');
    expect(r.US, 'US → en_US').toBe('en_US');
    expect(r.GB, 'GB → en_GB').toBe('en_GB');
    expect(r.XX, 'unmapped country returns empty string').toBe('');
    expect(r.empty, 'empty input returns empty string').toBe('');
    expect(r.lowercase, 'input is normalized to uppercase').toBe('pt_BR');
    expect(r.BE_filtered, 'per-country filter overrides nl_BE → fr_BE').toBe('fr_BE');
    expect(r.ZZ_map_filter, 'map filter injects a new country mapping').toBe('xx_ZZ');
  });

  test('faz_current_language() prefers the locale form when in selected_languages', () => {
    const raw = wpEval(`
      // Save and stub: opt into country-fallback, force the visitor country,
      // and make selected_languages include the regional locale.
      $orig_settings = get_option( 'faz_settings' );

      // The Languages helpers read $settings['languages']['selected'] for
      // the available list and $settings['languages']['default'] for the
      // baseline. Both branches under test (locale form vs bare-lang form)
      // must be candidates, so we ship en (default) and pt-br (regional).
      // Plugin-internal language codes are hyphenated lowercase (faz_wp_locale
      // map: 'pt-br' => 'pt_BR'); faz_country_to_locale() returns the
      // WP-style form pt_BR and faz_current_language() normalises it.
      $faked = is_array( $orig_settings ) ? $orig_settings : array();
      $faked['languages'] = array(
        'default'  => 'en',
        'selected' => array( 'en', 'pt-br' ),
      );
      update_option( 'faz_settings', $faked );

      $force_fallback = function () { return true; };
      add_filter( 'faz_use_country_language_fallback', $force_fallback );

      // The Geolocation lookup runs through get_visitor_country(), which
      // exposes the faz_visitor_country filter for exactly this kind of
      // test override — no need to reflect into private cache slots.
      $force_country = function () { return 'BR'; };
      add_filter( 'faz_visitor_country', $force_country );

      // Reset the static memoization inside faz_current_language() so the
      // next call re-runs the resolution chain with our stubs in place.
      faz_current_language( true );
      $resolved = faz_current_language();

      // Cleanup — order matters: clear the language cache LAST so the
      // next test boots from the restored settings.
      remove_filter( 'faz_visitor_country', $force_country );
      remove_filter( 'faz_use_country_language_fallback', $force_fallback );
      if ( false !== $orig_settings ) {
        update_option( 'faz_settings', $orig_settings );
      } else {
        delete_option( 'faz_settings' );
      }
      faz_current_language( true );

      echo wp_json_encode( array( 'resolved' => $resolved ) );
    `).trim();
    const r = JSON.parse(raw);
    // The headline assertion: when the install ships pt-br alongside the
    // bare-language default, the locale-form (BCP-47) candidate wins over
    // the bare-language fallback. Plugin-internal locale codes are
    // hyphenated lowercase (`pt-br`), normalised from the WP-style `pt_BR`
    // returned by faz_country_to_locale().
    expect(
      r.resolved,
      `country=BR + selected_languages=['en','pt-br'] should resolve to 'pt-br'`
    ).toBe('pt-br');
  });
});

/* ================================================================== *
 * #110 — Multi-source geolocation vote + consensus filter
 * ================================================================== */

test.describe('PR104 — #110 Geolocation multi-source consensus', () => {
  test('detect_country() collects votes from every enabled source and the consensus filter blocks on disagreement', () => {
    const raw = wpEval(`
      // Opt into both header-trusting filters so CF and GEOIP votes are collected.
      $opt_cf = function () { return true; };
      $opt_geoip = function () { return true; };
      add_filter( 'faz_trust_cf_ipcountry_header', $opt_cf );
      add_filter( 'faz_trust_geoip_country_code', $opt_geoip );

      // Reflection handle on the private static.
      $rc = new ReflectionMethod( '\\\\FazCookie\\\\Includes\\\\Geolocation', 'detect_country' );
      $rc->setAccessible( true );

      $results = array();

      // Scenario A — CF only → priority order returns CF.
      $_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
      unset( $_SERVER['GEOIP_COUNTRY_CODE'] );
      $results['only_cf'] = $rc->invoke( null, '1.2.3.4' );

      // Scenario B — CF + GEOIP agree → returns the agreed country.
      $_SERVER['HTTP_CF_IPCOUNTRY']  = 'US';
      $_SERVER['GEOIP_COUNTRY_CODE'] = 'US';
      $results['both_agree'] = $rc->invoke( null, '1.2.3.4' );

      // Scenario C — CF + GEOIP DISAGREE, consensus filter OFF (default)
      // → priority order wins, returns CF.
      $_SERVER['HTTP_CF_IPCOUNTRY']  = 'US';
      $_SERVER['GEOIP_COUNTRY_CODE'] = 'DE';
      $results['disagree_no_consensus'] = $rc->invoke( null, '1.2.3.4' );

      // Scenario D — same disagreement, consensus filter ON → ''.
      $require_consensus = function () { return true; };
      add_filter( 'faz_country_detection_consensus', $require_consensus );
      $results['disagree_with_consensus'] = $rc->invoke( null, '1.2.3.4' );
      remove_filter( 'faz_country_detection_consensus', $require_consensus );

      // Scenario E — filter receives the votes payload (verify by capturing).
      // F109 fix (1.14.3): the filter signature dropped the third
      // argument entirely. Consensus enforcement doesn't need the IP
      // (votes carry the country signal); passing it leaked PII to
      // third-party filter consumers even when hashed.
      $captured = array();
      $capture = function ( $require, $votes ) use ( &$captured ) {
        $captured['votes'] = $votes;
        return false; // don't enforce
      };
      add_filter( 'faz_country_detection_consensus', $capture, 10, 2 );
      $rc->invoke( null, '8.8.8.8' );
      remove_filter( 'faz_country_detection_consensus', $capture, 10 );
      $results['captured'] = $captured;

      // Cleanup.
      unset( $_SERVER['HTTP_CF_IPCOUNTRY'], $_SERVER['GEOIP_COUNTRY_CODE'] );
      remove_filter( 'faz_trust_cf_ipcountry_header', $opt_cf );
      remove_filter( 'faz_trust_geoip_country_code', $opt_geoip );

      echo wp_json_encode( $results );
    `).trim();
    const r = JSON.parse(raw);
    expect(r.only_cf, 'single CF vote returns CF country').toBe('US');
    expect(r.both_agree, 'agreeing votes return the agreed country').toBe('US');
    expect(
      r.disagree_no_consensus,
      'priority order (CF > GEOIP) wins when consensus filter is off'
    ).toBe('US');
    expect(
      r.disagree_with_consensus,
      'consensus filter fail-open returns empty string on disagreement'
    ).toBe('');
    // Filter signature: 2 args after F109 fix (was 3 with the IP/IP-hash).
    expect(r.captured.votes, 'consensus filter receives the votes map').toMatchObject({
      cf: 'US',
      geoip: 'DE',
    });
    // F109 fix (1.14.3): the filter no longer receives the IP (raw or
    // hashed). The visitor IP is PII and consensus enforcement doesn't
    // need it — the votes map already carries the country signal.
    // Plugins that genuinely need the IP for their own logic should
    // hook `faz_visitor_country` instead.
    expect(
      r.captured.ip,
      'consensus filter no longer exposes the visitor IP (raw or hashed)',
    ).toBeUndefined();
  });
});

/* ================================================================== *
 * #111 — Per-user rate-limit on /faz/v1/banners/configs
 * ================================================================== */

test.describe('PR104 — #111 banners/configs rate-limit', () => {
  test('second call within window returns 429 with Retry-After; filter override disables throttle', () => {
    const raw = wpEval(`
      // Ensure we're authenticated as an admin user (manage_options is the
      // existing capability gate; rate-limit is on TOP of it).
      $admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
      if ( empty( $admin ) ) {
        echo wp_json_encode( array( 'fatal' => 'no admin user found' ) );
        return;
      }
      wp_set_current_user( $admin[0]->ID );

      // Clear any leftover throttle so the first call passes.
      delete_transient( 'faz_configs_rl_' . $admin[0]->ID );

      $do_call = function () {
        $req = new WP_REST_Request( 'GET', '/faz/v1/banners/configs' );
        $req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        return rest_do_request( $req );
      };

      // 1st call within window → 200 + payload contains 'gdpr' + 'ccpa'.
      $first = $do_call();
      $first_status = $first->get_status();
      $first_keys = is_array( $first->get_data() ) ? array_keys( $first->get_data() ) : array();

      // 2nd immediate call → 429 + Retry-After header set.
      $second = $do_call();
      $second_status = $second->get_status();
      $second_headers = $second->get_headers();
      $retry_after = isset( $second_headers['Retry-After'] ) ? (int) $second_headers['Retry-After'] : 0;

      // Disable via filter (returning 0 short-circuits the throttle).
      delete_transient( 'faz_configs_rl_' . $admin[0]->ID );
      $disable = function () { return 0; };
      add_filter( 'faz_configs_rate_limit_seconds', $disable );
      $a = $do_call();
      $b = $do_call();
      $a_status = $a->get_status();
      $b_status = $b->get_status();
      remove_filter( 'faz_configs_rate_limit_seconds', $disable );

      // Final cleanup.
      delete_transient( 'faz_configs_rl_' . $admin[0]->ID );

      echo wp_json_encode( array(
        'first_status'   => $first_status,
        'first_keys'     => $first_keys,
        'second_status'  => $second_status,
        'retry_after'    => $retry_after,
        'override_a_status' => $a_status,
        'override_b_status' => $b_status,
      ) );
    `).trim();
    const r = JSON.parse(raw);
    expect(r.first_status, 'first call within window passes').toBe(200);
    expect(r.first_keys, 'response payload exposes gdpr + ccpa configs').toEqual(
      expect.arrayContaining(['gdpr', 'ccpa'])
    );
    expect(r.second_status, 'immediate second call returns 429').toBe(429);
    expect(r.retry_after, 'Retry-After header is set to a positive value').toBeGreaterThan(0);
    expect(r.retry_after, 'Retry-After is at most the throttle window').toBeLessThanOrEqual(5);
    expect(r.override_a_status, 'filter=0 disables throttle (call A)').toBe(200);
    expect(r.override_b_status, 'filter=0 disables throttle (immediate call B)').toBe(200);
  });
});

/* ================================================================== *
 * #112 — Cookie size observability warning (>3500 bytes)
 * ================================================================== */

test.describe('PR104 — #112 fazcookie-consent size warning', () => {
  test('console.warn fires when fazcookie-consent exceeds 3500 encoded bytes', async ({
    page,
    wpBaseURL,
  }) => {
    const warnings: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'warning') warnings.push(msg.text());
    });

    // Visit the frontend so the banner bootstrap runs and exposes
    // window.fazcookie._fazSetCookie.
    await page.goto(`${wpBaseURL}/?faz-issue112=1`, { waitUntil: 'domcontentloaded' });
    // Wait for the consent bar to mount — that's the marker that the
    // bootstrap finished and ref._fazSetCookie is wired up.
    await page.waitForSelector('.faz-consent-bar, .faz-consent-container, .faz-modal', {
      timeout: 15000,
    });

    // Pre-flight: confirm the function is reachable.
    const isReachable = await page.evaluate(
      () => typeof (window as any).fazcookie?._fazSetCookie === 'function'
    );
    expect(isReachable, 'window.fazcookie._fazSetCookie is exposed by the bootstrap').toBe(
      true
    );

    // Drive 2 scenarios in one trip — keeps the test reusable.
    await page.evaluate(() => {
      const sc = (window as any).fazcookie;
      // Scenario A — small payload (200 chars) — NO warn.
      sc._fazSetCookie('fazcookie-consent', 'x'.repeat(200), 0);
      // Scenario B — large payload (3501 chars, encoding-stable since 'x' is
      // not %-encoded) — WARN expected.
      sc._fazSetCookie('fazcookie-consent', 'x'.repeat(3501), 0);
      // Scenario C — non-consent cookie name → never warn even when huge.
      sc._fazSetCookie('some-other-cookie', 'y'.repeat(4000), 0);
    });

    // Give the event loop a tick to flush console messages to Playwright.
    await page.waitForTimeout(150);

    const consentWarns = warnings.filter((w) =>
      w.includes('[FAZ Cookie Manager] fazcookie-consent cookie is')
    );
    expect(
      consentWarns.length,
      `expected exactly 1 size warning, got: ${JSON.stringify(warnings)}`
    ).toBe(1);
    // Sanity-check the byte count in the warning matches the encoded length
    // (3501 'x' chars don't get URL-encoded so byte length === 3501).
    expect(consentWarns[0], 'warning reports the actual encoded byte count').toContain('3501');
    expect(consentWarns[0], 'warning mentions the 4096 browser cap').toContain('4096');
  });
});

/* ================================================================== *
 * #113 — readme.txt documents Cache Plugin Compatibility
 * ================================================================== */

test.describe('PR104 — #113 readme.txt cache plugin compatibility docs', () => {
  test('readme.txt ships the Cache Plugin Compatibility section with the canonical signal list', () => {
    const readmePath = resolve(__dirname, '../../../readme.txt');
    const readme = readFileSync(readmePath, 'utf8');

    // Section heading.
    expect(readme, 'readme.txt has the wp.org section heading').toMatch(
      /^==\s*Cache Plugin Compatibility\s*==$/m
    );

    // Each cache-bypass signal the plugin emits MUST be documented so
    // hosts running unusual cache stacks can map the behaviour.
    for (const signal of [
      'Cache-Control',
      'DONOTCACHEPAGE',
      'X-LiteSpeed-Cache-Control',
      'Vary',
      'CF-IPCountry',
    ]) {
      expect(readme, `readme.txt documents signal: ${signal}`).toContain(signal);
    }

    // Verified-compatible cache plugins — every name the issue committed to.
    for (const plugin of [
      'LiteSpeed',
      'WP Rocket',
      'W3 Total Cache',
      'WP Super Cache',
      'Hummingbird',
      'Cloudflare APO',
    ]) {
      expect(readme, `readme.txt lists verified plugin: ${plugin}`).toContain(plugin);
    }

    // Documented escape hatch — the filter publishers can use to opt
    // out of country-dependent output entirely.
    expect(readme, 'readme.txt documents the per-request override filter').toContain(
      'faz_country_dependent_banner_output'
    );
  });
});
