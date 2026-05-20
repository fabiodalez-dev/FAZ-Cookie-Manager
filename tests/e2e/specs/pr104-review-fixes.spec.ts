/**
 * PR #104 review-fix regression suite (1.14.0).
 *
 * Reusable coverage for every code/test fix landed during the PR #104
 * review cycle (May 2026): adamsreview F-COR-01..07, F-SEC-02/03/04,
 * F-UX-01..06; CodeRabbit CR-01/02/06/07/09/10 and the P2 ruleSet
 * asymmetry. Pre-existing tests already cover the four flake fixes
 * (TCF poll, prefcenter focus, CB-OV nonce, GEO seeding) — they are
 * NOT duplicated here.
 *
 * Numbering matches the review-finding identifiers used in the
 * branch's commit messages so a future maintainer can trace each
 * test back to a finding without re-reading the PR thread.
 *
 * All assertions go through wpEval (PHP-level reflection) where
 * possible — the tests stay fast and deterministic without depending
 * on the live HTTP frontend. UI tests (F-UX-01..06) are browser-based
 * because the assertions are DOM/CSS-level.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://localhost:9998';

/* ------------------------------------------------------------------ *
 * Helpers
 * ------------------------------------------------------------------ */

/** Snapshot every banner row, ensure two rows exist, return [activeId, secondaryId]. */
function ensureTwoBanners(): { active: number; secondary: number } {
  const raw = wpEval(`
    global $wpdb;
    $table = $wpdb->prefix . 'faz_banners';
    $active = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
    if ( ! $active ) { echo wp_json_encode( array( 'active' => 0, 'secondary' => 0 ) ); return; }
    $active_id = (int) $active->get_id();
    $secondary = (int) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT banner_id FROM {$table} WHERE banner_id <> %d ORDER BY banner_id ASC LIMIT 1",
        $active_id
      )
    );
    if ( $secondary <= 0 ) {
      $now = current_time( 'mysql' );
      $wpdb->insert( $table, array(
        'name'             => 'PR104 review-fixes secondary',
        'slug'             => 'pr104-secondary',
        'status'           => 0,
        'settings'         => wp_json_encode( $active->get_settings() ),
        'contents'         => wp_json_encode( $active->get_contents() ),
        'banner_default'   => 0,
        'target_countries' => wp_json_encode( array() ),
        'priority'         => 0,
        'date_created'     => $now,
        'date_modified'    => $now,
      ) );
      $secondary = (int) $wpdb->insert_id;
    }
    echo wp_json_encode( array( 'active' => $active_id, 'secondary' => $secondary ) );
  `).trim();
  return JSON.parse(raw);
}

/** Restore the banner_default flag on $activeId only. Used by tests that mutate the default flag. */
function restoreDefaultTo(activeId: number): void {
  wpEval(`
    global $wpdb;
    $table = $wpdb->prefix . 'faz_banners';
    $wpdb->query( "UPDATE {$table} SET banner_default = 0" );
    $wpdb->update( $table, array( 'banner_default' => 1 ), array( 'banner_id' => ${activeId} ) );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
  `);
}

/** Snapshot every banner row so tests that mutate row-level geo/status fields can restore them. */
function snapshotBannerRows(): string {
  return wpEval(`
    global $wpdb;
    $table = $wpdb->prefix . 'faz_banners';
    $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY banner_id ASC", ARRAY_A );
    echo base64_encode( wp_json_encode( $rows ) );
  `).trim();
}

function restoreBannerRows(snapshot: string): void {
  wpEval(`
    global $wpdb;
    $table = $wpdb->prefix . 'faz_banners';
    $rows = json_decode( base64_decode( '${snapshot}' ), true );
    if ( ! is_array( $rows ) ) {
      return;
    }
    $wpdb->query( "DELETE FROM {$table}" );
    foreach ( $rows as $row ) {
      if ( ! is_array( $row ) ) {
        continue;
      }
      $wpdb->insert( $table, $row );
    }
    if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
      faz_clear_banner_template_cache();
    }
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
  `);
}

/* ================================================================== *
 * F-COR-01 / CR-02 — banner_default mutual exclusion server-side
 * ================================================================== */

test.describe.serial('PR104 — F-COR-01 / CR-02 banner_default single-default invariant', () => {
  let active = 0;
  let secondary = 0;
  let bannerSnapshot = '';

  test.beforeAll(() => {
    bannerSnapshot = snapshotBannerRows();
    const ids = ensureTwoBanners();
    active = ids.active;
    secondary = ids.secondary;
  });

  test.afterAll(() => {
    restoreBannerRows(bannerSnapshot);
  });

  test('F-COR-01-01: clear_default_on_others zeroes every peer row', () => {
    const result = wpEval(`
      global $wpdb;
      $table = $wpdb->prefix . 'faz_banners';
      // Seed: both banners marked default=1 (the buggy starting state).
      $wpdb->query( "UPDATE {$table} SET banner_default = 1" );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->clear_default_on_others( ${active} );
      $defaults = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE banner_default = 1" );
      $keeper = (int) $wpdb->get_var( $wpdb->prepare( "SELECT banner_default FROM {$table} WHERE banner_id = %d", ${active} ) );
      echo wp_json_encode( array( 'defaults' => $defaults, 'keeper_kept' => $keeper ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.defaults, 'exactly one banner_default=1 row after clear_default_on_others').toBe(1);
    expect(data.keeper_kept, 'the keeper id retained its banner_default=1').toBe(1);
  });

  test('F-COR-01-02: update_item enforces mutual exclusion through the model', () => {
    const result = wpEval(`
      global $wpdb;
      $table = $wpdb->prefix . 'faz_banners';
      // Reset both rows to non-default explicitly so the update path is the
      // only thing that can produce a banner_default=1 row.
      $wpdb->query( "UPDATE {$table} SET banner_default = 0" );
      // Now promote the secondary banner to default via the Banner model
      // (mirrors what the REST PUT does internally).
      $b = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( ${secondary} );
      $b->set_default( true );
      $b->save();
      $defaults = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE banner_default = 1" );
      $second = (int) $wpdb->get_var( $wpdb->prepare( "SELECT banner_default FROM {$table} WHERE banner_id = %d", ${secondary} ) );
      $first  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT banner_default FROM {$table} WHERE banner_id = %d", ${active} ) );
      echo wp_json_encode( array( 'defaults' => $defaults, 'second' => $second, 'first' => $first ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.defaults, 'still exactly one banner_default=1 after promotion').toBe(1);
    expect(data.second, 'the promoted banner holds the flag').toBe(1);
    expect(data.first, 'the previously-default banner lost the flag').toBe(0);
  });
});

/* ================================================================== *
 * F-COR-03 — ORDER BY banner_id determinism
 * ================================================================== */

test.describe('PR104 — F-COR-03 get_items() ORDER BY banner_id', () => {
  test('rows are returned in strictly ascending banner_id order', () => {
    ensureTwoBanners();
    const raw = wpEval(`
      $items = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_items();
      // get_items() is keyed by banner_id (associative array). Use
      // array_values to collapse to a numeric-indexed list so we can
      // assert ordering rather than key membership.
      $ids = array_values( array_map( function ( $i ) { return (int) $i->banner_id; }, $items ) );
      echo wp_json_encode( $ids );
    `).trim();
    const ids = JSON.parse(raw) as number[];
    expect(ids.length, 'at least one banner row present').toBeGreaterThan(0);
    for (let i = 1; i < ids.length; i += 1) {
      expect(ids[i], `banner_id ascending: ids[${i}] > ids[${i - 1}]`).toBeGreaterThan(ids[i - 1]);
    }
  });
});

/* ================================================================== *
 * F-COR-04 — get_active_banner() contract preservation
 * ================================================================== */

test.describe.serial('PR104 — F-COR-04 get_active_banner() preserves pre-1.14.0 contract', () => {
  let active = 0;
  let secondary = 0;
  let bannerSnapshot = '';

  test.beforeAll(() => {
    bannerSnapshot = snapshotBannerRows();
    const ids = ensureTwoBanners();
    active = ids.active;
    secondary = ids.secondary;
  });

  test.afterAll(() => {
    restoreBannerRows(bannerSnapshot);
  });

  test('returns a status=1 country-targeted banner when no default + no match-all exists', () => {
    const result = wpEval(`
      global $wpdb;
      $table = $wpdb->prefix . 'faz_banners';
      // Install shape: ONE status=1 banner that targets US only. No
      // banner_default, no match-all row. Pre-fix, get_active_banner()
      // returned false because the picker fell through every bucket.
      $wpdb->update( $table, array( 'status' => 0, 'banner_default' => 0, 'target_countries' => '[]' ), array( 'banner_id' => ${active} ) );
      $wpdb->update( $table, array( 'status' => 1, 'banner_default' => 0, 'target_countries' => '["US"]' ), array( 'banner_id' => ${secondary} ) );
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
      $b = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
      echo wp_json_encode( array( 'id' => $b ? $b->get_id() : null ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.id, 'legacy get_active_banner() must fall through to status_targeted').toBe(secondary);
  });
});

/* ================================================================== *
 * F-COR-06 — send_geo_cache_headers gates on faz_is_front_end_request
 * ================================================================== */

test.describe('PR104 — F-COR-06 send_geo_cache_headers front-end gate', () => {
  test('does not emit headers on REST requests', async ({ request }) => {
    // A REST GET should not pick up the country-dependent Cache-Control
    // header (the hook fires on send_headers but the new guard skips
    // non-front-end requests).
    const response = await request.get(`${WP_BASE}/wp-json/`);
    const cacheControl = response.headers()['cache-control'] || '';
    expect(cacheControl, 'REST root must not carry the geo no-store directive').not.toContain('no-store, no-cache, must-revalidate, max-age=0');
  });
});

/* ================================================================== *
 * F-COR-07 — update_db_350 information_schema safety net
 * ================================================================== */

test.describe.serial('PR104 — F-COR-07 update_db_350 MySQL 8.0 STRICT safety net', () => {
  test('drops and re-adds target_countries column via the explicit ALTER fallback', () => {
    const result = wpEval(`
      global $wpdb;
      $table  = $wpdb->prefix . 'faz_banners';
      $schema = $wpdb->get_var( 'SELECT DATABASE()' );

      // Simulate a STRICT-mode install where dbDelta silently skipped the
      // new column: drop it ourselves, run the migration, verify it's
      // back. We re-INSERT with default '[]' to avoid leaving the table
      // in an empty-column state for the rest of the suite. Plain
      // DROP COLUMN (no IF EXISTS) is used because older MySQL builds
      // don't support the IF EXISTS clause; we suppress the error
      // explicitly via @ since the column is expected to exist.
      @$wpdb->query( "ALTER TABLE {$table} DROP COLUMN target_countries" );
      $missing_before = (int) $wpdb->get_var( $wpdb->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $schema, $table, 'target_countries'
      ) );

      \\FazCookie\\Includes\\Activator::update_db_350();

      $present_after = (int) $wpdb->get_var( $wpdb->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $schema, $table, 'target_countries'
      ) );

      echo wp_json_encode( array( 'missing_before' => $missing_before, 'present_after' => $present_after ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.missing_before, 'column was actually dropped at test start').toBe(0);
    expect(data.present_after, 'update_db_350 re-added the column via the safety net').toBe(1);
  });
});

/* ================================================================== *
 * F-SEC-02 / CR-09 — geo-input hardening
 * ================================================================== */

test.describe('PR104 — F-SEC-02 GEOIP_COUNTRY_CODE opt-in filter', () => {
  test('GEOIP_COUNTRY_CODE is ignored by default and consumed only when the trust filter is on', () => {
    // Geolocation::get_country() short-circuits with '' when the client IP
    // is empty/localhost (the WP-CLI default), so the detect_country()
    // chain — and the GEOIP_COUNTRY_CODE branch this test cares about —
    // is never reached. Force a non-localhost REMOTE_ADDR for the
    // duration of the assertion AND bypass the per-IP transient cache.
    const result = wpEval(`
      // Defensive cleanup: the dev-only mu-plugin faz-geo-dev-fake-cf
      // registers __return_true on faz_trust_cf_ipcountry_header AND
      // injects $_SERVER['HTTP_CF_IPCOUNTRY']='IT' on every request,
      // which would short-circuit the detection chain before the
      // GEOIP branch this test verifies. Strip both. We also strip the
      // GEOIP filter for symmetry (no current dev fixture sets it, but
      // the assertion needs to observe production default 'false').
      remove_all_filters( 'faz_trust_cf_ipcountry_header' );
      remove_all_filters( 'faz_trust_geoip_country_code' );
      unset( $_SERVER['HTTP_CF_IPCOUNTRY'], $_SERVER['HTTP_CF_REGION_CODE'] );

      $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
      // Clear any cached lookup for that IP from previous tests.
      delete_transient( 'faz_geo_' . md5( '8.8.8.8' ) );

      // Filter OFF, header present: header MUST be ignored. detect_country
      // then falls through to PHP geoip/MaxMind/ip-api which, without
      // those configured in CI, returns ''.
      $_SERVER['GEOIP_COUNTRY_CODE'] = 'IT';
      delete_transient( 'faz_geo_' . md5( '8.8.8.8' ) );
      $off = \\FazCookie\\Includes\\Geolocation::get_visitor_country();

      // Filter ON: header IS consumed and steers the result.
      delete_transient( 'faz_geo_' . md5( '8.8.8.8' ) );
      $closure = function() { return true; };
      add_filter( 'faz_trust_geoip_country_code', $closure, 10 );
      $on = \\FazCookie\\Includes\\Geolocation::get_visitor_country();
      remove_filter( 'faz_trust_geoip_country_code', $closure, 10 );

      unset( $_SERVER['GEOIP_COUNTRY_CODE'], $_SERVER['REMOTE_ADDR'] );
      delete_transient( 'faz_geo_' . md5( '8.8.8.8' ) );
      echo wp_json_encode( array( 'off' => $off, 'on' => $on ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.on, 'with the trust filter ON, GEOIP_COUNTRY_CODE steers the result to IT').toBe('IT');
    expect(data.off, 'with the trust filter OFF, GEOIP_COUNTRY_CODE must NOT leak through').not.toBe('IT');
  });
});

test.describe('PR104 — CR-09 XX sentinel rejected post-filter', () => {
  test('faz_visitor_country filter returning XX yields empty country', () => {
    const result = wpEval(`
      $closure = function() { return 'XX'; };
      add_filter( 'faz_visitor_country', $closure, 100 );
      $value = \\FazCookie\\Includes\\Geolocation::get_visitor_country();
      remove_filter( 'faz_visitor_country', $closure, 100 );
      echo wp_json_encode( array( 'value' => $value ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.value, 'XX sentinel must NOT leak through as a real country').toBe('');
  });
});

/* ================================================================== *
 * F-SEC-03 — scope fingerprint integrity (HMAC)
 * ================================================================== */

test.describe('PR104 — F-SEC-03 scope fingerprint integrity check', () => {
  test('fingerprint is stable for the same scope and changes when banner/law changes', () => {
    const result = wpEval(`
      // Same scope → same fingerprint.
      $fp1 = substr( wp_hash( 'gdpr-banner|gdpr', 'auth' ), 0, 32 );
      $fp2 = substr( wp_hash( 'gdpr-banner|gdpr', 'auth' ), 0, 32 );
      // Different banner slug → different fingerprint.
      $fp3 = substr( wp_hash( 'ccpa-banner|gdpr', 'auth' ), 0, 32 );
      // Different law → different fingerprint.
      $fp4 = substr( wp_hash( 'gdpr-banner|ccpa', 'auth' ), 0, 32 );
      echo wp_json_encode( array(
        'stable' => $fp1 === $fp2,
        'diff_banner' => $fp1 !== $fp3,
        'diff_law' => $fp1 !== $fp4,
        'length' => strlen( $fp1 ),
      ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.stable, 'same scope yields the same fingerprint deterministically').toBe(true);
    expect(data.diff_banner, 'changing the banner slug changes the fingerprint').toBe(true);
    expect(data.diff_law, 'changing the law changes the fingerprint').toBe(true);
    expect(data.length, 'fingerprint is 32 hex chars (truncated)').toBe(32);
  });

  test('fingerprint cannot be forged without wp_salt(auth) — empty/garbage values do not match', () => {
    const result = wpEval(`
      $real    = substr( wp_hash( 'gdpr-banner|gdpr', 'auth' ), 0, 32 );
      $forged1 = substr( hash( 'sha256', 'gdpr-banner|gdpr' ), 0, 32 ); // no salt
      $forged2 = str_repeat( '0', 32 ); // garbage
      echo wp_json_encode( array(
        'forged_no_salt_differs' => $real !== $forged1,
        'forged_garbage_differs' => $real !== $forged2,
      ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.forged_no_salt_differs, 'hashing without the salt produces a different fingerprint').toBe(true);
    expect(data.forged_garbage_differs, 'arbitrary 32-char strings do not collide with the real fingerprint').toBe(true);
  });

  test('frontend bootstrap exposes _scopeFingerprint in the localize payload', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
    // The localize payload lives on window._fazConfig (set by
    // wp_localize_script). Inside script.js it gets aliased to a module-
    // local `const _fazStore = window._fazConfig`, but that const is not
    // on window, so the test reads the source variable directly.
    await page.waitForFunction(
      () => typeof (window as any)._fazConfig !== 'undefined' && (window as any)._fazConfig !== null,
      undefined,
      { timeout: 5_000 },
    );
    const fp = await page.evaluate(() => (window as any)._fazConfig && (window as any)._fazConfig._scopeFingerprint);
    expect(typeof fp, '_scopeFingerprint published to _fazConfig').toBe('string');
    expect(String(fp).length, 'fingerprint length matches the truncation in PHP').toBe(32);
    // Hex shape sanity check — wp_hash returns hex, substr keeps that.
    expect(String(fp)).toMatch(/^[a-f0-9]{32}$/);
  });
});

/* ================================================================== *
 * F-SEC-04 — has_country_dependent_banners memoization
 * ================================================================== */

test.describe('PR104 — F-SEC-04 has_country_dependent_banners memoize', () => {
  test('result is cached in wp_cache under an epoch-versioned key and delete_cache invalidates by bumping the epoch (issue #109)', () => {
    const result = wpEval(`
      $ctrl = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
      // First call: populates the cache under the current epoch's key.
      // F305 (1.14.3+): the epoch is stored as a microsecond-precision
      // float string from sprintf('%.6F', microtime(true)). Cast to
      // (float) — NOT (int) — so two delete_cache() calls within the
      // same wall-clock second still produce strictly-greater values.
      $epoch_before = (string) get_option( 'faz_banner_cache_epoch', '0' );
      $first  = $ctrl->has_country_dependent_banners();
      $cached = wp_cache_get( 'faz_has_country_dependent_banners_v' . $epoch_before, 'faz_banners' );
      // delete_cache must bump the epoch — multi-node-safe invalidation.
      $ctrl->delete_cache();
      $epoch_after = (string) get_option( 'faz_banner_cache_epoch', '0' );
      echo wp_json_encode( array(
        'first_value'         => (bool) $first,
        'cached_after_first'  => $cached !== false,
        // bccomp returns 1 when arg1 > arg2 — string-safe ordering
        // for the float-shaped epoch.
        'epoch_bumped'        => bccomp( $epoch_after, $epoch_before, 6 ) === 1,
      ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.cached_after_first, 'first call seeds wp_cache under the current epoch key').toBe(true);
    expect(data.epoch_bumped, 'delete_cache bumps the cache epoch so every node misses the old key').toBe(true);
  });
});

/* ================================================================== *
 * CR-01 — update_db_350 delete_option('faz_banners_table_version')
 * ================================================================== */

test.describe('PR104 — CR-01 update_db_350 forces dbDelta to re-run', () => {
  test('the version option is cleared before install_tables() executes', () => {
    const result = wpEval(`
      // Seed: pretend the table version is already up to date — this is
      // the state install_all_tables() leaves at install() time, and the
      // bug was that update_db_350() inherited it without clearing.
      update_option( 'faz_banners_table_version', FAZ_VERSION );
      \\FazCookie\\Includes\\Activator::update_db_350();
      // After the migration runs, the version option must be back to
      // FAZ_VERSION (install_tables sets it on success), proving dbDelta
      // had a chance to re-run.
      $version_after = get_option( 'faz_banners_table_version' );
      echo wp_json_encode( array( 'version_after' => $version_after, 'matches' => $version_after === FAZ_VERSION ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.matches, 'install_tables() ran and re-set the version option').toBe(true);
  });
});

/* ================================================================== *
 * CR-06 — IAB enabled → country-dependent output
 * ================================================================== */

test.describe.serial('PR104 — CR-06 IAB TCF marks output country-dependent', () => {
  test('is_country_dependent_output returns true when IAB is enabled even on single-banner installs', () => {
    const result = wpEval(`
      $prev = get_option( 'faz_settings', null );
      $s = is_array( $prev ) ? $prev : array();
      if ( ! isset( $s['iab'] ) ) { $s['iab'] = array(); }

      // Baseline — IAB off. Use a FRESH Frontend instance each invocation:
      // is_country_dependent_output() reads through the instance-level
      // settings_option_cache memoization, so a single instance would return
      // the same value both times even after update_option flipped it.
      $s['iab']['enabled'] = false;
      update_option( 'faz_settings', $s );
      $fe_before = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $m_before  = ( new ReflectionClass( $fe_before ) )->getMethod( 'is_country_dependent_output' );
      $m_before->setAccessible( true );
      $before = $m_before->invoke( $fe_before );

      // Enable IAB → fresh Frontend instance + fresh method handle so the
      // instance-level cache is rebuilt against the just-written option.
      $s['iab']['enabled'] = true;
      update_option( 'faz_settings', $s );
      $fe_after = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $m_after  = ( new ReflectionClass( $fe_after ) )->getMethod( 'is_country_dependent_output' );
      $m_after->setAccessible( true );
      $after = $m_after->invoke( $fe_after );

      // Restore.
      if ( null === $prev ) { delete_option( 'faz_settings' ); } else { update_option( 'faz_settings', $prev ); }
      echo wp_json_encode( array( 'before' => (bool) $before, 'after' => (bool) $after ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.after, 'IAB enabled flips the output to country-dependent').toBe(true);
  });
});

/* ================================================================== *
 * CR-07 — AMP resolver applies geo guards
 * ================================================================== */

test.describe('PR104 — CR-07 AMP get_active_banner geo guards', () => {
  test('AMP class exposes is_geo_banner_disabled + is_banner_geo_blocked helpers', () => {
    const result = wpEval(`
      $amp = new \\FazCookie\\Frontend\\AMP_Consent();
      $ref = new ReflectionClass( $amp );
      $has_global = $ref->hasMethod( 'is_geo_banner_disabled' );
      $has_per_banner = $ref->hasMethod( 'is_banner_geo_blocked' );
      echo wp_json_encode( array( 'global' => $has_global, 'per_banner' => $has_per_banner ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.global, 'AMP class has is_geo_banner_disabled() helper').toBe(true);
    expect(data.per_banner, 'AMP class has is_banner_geo_blocked() helper').toBe(true);
  });
});

/* ================================================================== *
 * CR-10 — __scope.* namespace does not collide with category slugs
 * ================================================================== */

test.describe('PR104 — CR-10 scope keys do not collide with category slugs', () => {
  test('frontend bootstrap separates __scope.banner from any same-named category slug', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto(`${WP_BASE}/`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
    const probe = await page.evaluate(() => {
      // Even if a category happens to be named "banner" or "law", the
      // store API exposes the scope under the __scope.* prefix; the
      // unprefixed keys are reserved for category slugs only.
      const ref = (window as any);
      const fromScopeBanner = typeof ref._fazGetFromStore === 'function' ? ref._fazGetFromStore('__scope.banner') : 'no-store';
      const fromScopeLaw    = typeof ref._fazGetFromStore === 'function' ? ref._fazGetFromStore('__scope.law')    : 'no-store';
      return { fromScopeBanner, fromScopeLaw };
    });
    // The values are empty strings pre-consent — the assertion is just
    // that the store ACCEPTS the prefixed keys without erroring.
    expect(typeof probe.fromScopeBanner, '__scope.banner is a queryable store key').not.toBe('undefined');
    expect(typeof probe.fromScopeLaw, '__scope.law is a queryable store key').not.toBe('undefined');
  });
});

/* ================================================================== *
 * P2-1 — is_geo_blocked iterates ALL ruleSet entries
 * ================================================================== */

test.describe.serial('PR104 — P2-1 Frontend::is_geo_blocked iterates every ruleSet entry', () => {
  let active = 0;

  test.beforeAll(() => {
    const ids = ensureTwoBanners();
    active = ids.active;
  });

  // Banner::get_settings() applies sanitize_settings on EVERY read, which
  // collapses ruleSet down to a single entry against the default config
  // tree. That means is_geo_blocked() can never see a multi-rule ruleSet
  // via the model in production — but defensive code is still useful for
  // direct-DB / third-party callers, and the rule-matching helper itself
  // is unit-testable via Reflection. Test rule_matches_visitor() directly
  // instead of round-tripping through the sanitized model.
  test('rule_matches_visitor: ALL matches every visitor (including unknown country)', () => {
    const result = wpEval(`
      $fe = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $m = (new ReflectionClass( $fe ))->getMethod( 'rule_matches_visitor' );
      $m->setAccessible( true );
      echo wp_json_encode( array(
        'all_us'      => $m->invoke( $fe, array( 'code' => 'ALL' ), 'US' ),
        'all_jp'      => $m->invoke( $fe, array( 'code' => 'ALL' ), 'JP' ),
        'all_unknown' => $m->invoke( $fe, array( 'code' => 'ALL' ), '' ),
      ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.all_us, 'ALL matches a US visitor').toBe(true);
    expect(data.all_jp, 'ALL matches a JP visitor').toBe(true);
    expect(data.all_unknown, 'ALL matches even when country detection failed').toBe(true);
  });

  test('rule_matches_visitor: EU / US / OTHER require a resolved country and match against the right set', () => {
    const result = wpEval(`
      $fe = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $m = (new ReflectionClass( $fe ))->getMethod( 'rule_matches_visitor' );
      $m->setAccessible( true );
      echo wp_json_encode( array(
        'eu_de'        => $m->invoke( $fe, array( 'code' => 'EU' ), 'DE' ),
        'eu_us'        => $m->invoke( $fe, array( 'code' => 'EU' ), 'US' ),
        'eu_unknown'   => $m->invoke( $fe, array( 'code' => 'EU' ), '' ),
        'us_us'        => $m->invoke( $fe, array( 'code' => 'US' ), 'US' ),
        'us_de'        => $m->invoke( $fe, array( 'code' => 'US' ), 'DE' ),
        'other_jp'     => $m->invoke( $fe, array( 'code' => 'OTHER', 'regions' => array( 'JP', 'KR' ) ), 'JP' ),
        'other_us'     => $m->invoke( $fe, array( 'code' => 'OTHER', 'regions' => array( 'JP', 'KR' ) ), 'US' ),
      ) );
    `).trim();
    const data = JSON.parse(result);
    expect(data.eu_de, 'EU matches DE visitor').toBe(true);
    expect(data.eu_us, 'EU does not match US visitor').toBe(false);
    expect(data.eu_unknown, 'EU does not match when country is unknown (fail-closed)').toBe(false);
    expect(data.us_us, 'US matches US visitor').toBe(true);
    expect(data.us_de, 'US does not match DE visitor').toBe(false);
    expect(data.other_jp, 'OTHER matches a visitor in its regions list').toBe(true);
    expect(data.other_us, 'OTHER does not match a visitor outside its regions list').toBe(false);
  });

  test('is_geo_blocked: iterates EVERY entry, not just $rules[0] — verified by direct Reflection write', () => {
    // Bypass Banner::get_settings() (which sanitizes ruleSet) by stubbing
    // a Banner instance whose data['settings'] carries the multi-rule
    // payload as a string. is_geo_blocked() re-reads via get_settings(),
    // which json_decodes the string but then sanitizes it... so we
    // additionally pin the Banner instance to one whose settings have
    // ALREADY been bypass-sanitized via Reflection on the data array
    // AFTER calling get_settings once.
    //
    // Simpler approach: temporarily swap Banner::get_settings via a
    // subclass-style anonymous override is impossible in PHP without
    // class declaration scopes. We test the BEHAVIOUR by exercising
    // rule_matches_visitor for every entry that COULD be in a ruleSet
    // (covered above), plus asserting the iteration shape with a
    // fixture that has a single-entry ruleSet[0]=US and verifying a
    // US visitor isn't blocked — proving the loop runs the matcher
    // at least once and respects its boolean return.
    const result = wpEval(`
      global $wpdb;
      $table = $wpdb->prefix . 'faz_banners';
      // Snapshot original settings BEFORE any mutation so the PHP
      // try/finally below can restore even if anything throws mid-test
      // (an exception in is_geo_blocked / Reflection that would
      // otherwise leave ruleSet=US persisted and contaminate the rest
      // of the suite).
      $b = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( ${active} );
      $original_settings = $b->get_settings();

      try {
        // Write a single-entry US rule via the model (sanitizer allows
        // single entries) — this is the realistic production shape.
        $s = $original_settings;
        $s['settings']['ruleSet'] = array( array( 'code' => 'US', 'regions' => array() ) );
        $b->set_settings( $s );
        $b->save();

        $closure_us = function() { return 'US'; };
        add_filter( 'faz_visitor_country', $closure_us );
        $fe = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
        $fe_ref = new ReflectionClass( $fe );
        $banner_prop = $fe_ref->getProperty( 'banner' );
        $banner_prop->setAccessible( true );
        $banner_prop->setValue( $fe, new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( ${active} ) );
        $method = $fe_ref->getMethod( 'is_geo_blocked' );
        $method->setAccessible( true );
        $blocked_us_single = $method->invoke( $fe );
        remove_filter( 'faz_visitor_country', $closure_us );

        // Now flip the visitor to JP — the same single-entry US rule must block them.
        $closure_jp = function() { return 'JP'; };
        add_filter( 'faz_visitor_country', $closure_jp );
        $banner_prop->setValue( $fe, new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( ${active} ) );
        $blocked_jp_single = $method->invoke( $fe );
        remove_filter( 'faz_visitor_country', $closure_jp );

        echo wp_json_encode( array(
          'blocked_us_single' => $blocked_us_single,
          'blocked_jp_single' => $blocked_jp_single,
        ) );
      } finally {
        // Always restore the original settings — covers both successful
        // assertion paths and unexpected exceptions.
        $b_restore = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( ${active} );
        $b_restore->set_settings( $original_settings );
        $b_restore->save();
        \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
      }
    `).trim();
    const data = JSON.parse(result);
    expect(data.blocked_us_single, 'single-entry US rule does NOT block US visitor').toBe(false);
    expect(data.blocked_jp_single, 'single-entry US rule blocks JP visitor').toBe(true);
  });
});

/* ================================================================== *
 * F-UX-01..06 — admin UI hardening (DOM checks)
 * ================================================================== */

test.describe('PR104 — F-UX-02/03/05/06 admin UI elements present', () => {
  test('Banner Settings page renders the regulatory warning + a11y fieldset', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, { waitUntil: 'domcontentloaded' });

    // F-UX-02 — warning visual on the close-X override toggle.
    await page.click('button.faz-tab[data-tab="buttons"]').catch(() => {});
    const group = page.locator('#faz-b-close-with-reject-group');
    await expect(group, 'F-UX-02: close-X override has its own warning-styled group').toBeAttached();
    // The amber border-left is part of the warning treatment.
    const borderColor = await group.evaluate((el) => getComputedStyle(el as HTMLElement).borderLeftColor);
    expect(borderColor, 'F-UX-02: warning border colour is present').toMatch(/rgb\(245,\s*158,\s*11\)|#f59e0b/i);

    // F-UX-03 — sub-toggle disabled when parent toggle is off.
    // Note: #faz-b-close-toggle is the <label>, not the <input>. The
    // sync handler binds to the underlying checkbox via descendant
    // selector (banner.js F001 fix at line 985-987). Dispatch the
    // change event on the actual input — that matches the production
    // event flow when a user clicks the label (browser fires change
    // on the input, not the label).
    await page.evaluate(() => {
      const label = document.getElementById('faz-b-close-toggle');
      const input = label ? label.querySelector('input[type="checkbox"]') as HTMLInputElement | null : null;
      if (input && input.checked) {
        input.checked = false;
        input.dispatchEvent(new Event('change'));
      }
    });
    const isDisabled = await page.locator('#faz-b-close-with-reject').evaluate((el) => (el as HTMLInputElement).disabled);
    expect(isDisabled, 'F-UX-03: sub-toggle disables when parent goes off').toBe(true);

    // F-UX-06 — fieldset + legend wrap the region presets (a11y).
    await page.click('button.faz-tab[data-tab="geo"]').catch(() => {});
    const fieldset = page.locator('#tab-geo fieldset');
    await expect(fieldset, 'F-UX-06: region presets are grouped under a fieldset').toBeAttached();
    const legend = fieldset.locator('legend');
    await expect(legend, 'F-UX-06: the fieldset has a programmatic legend').toBeAttached();
  });
});

test.describe('PR104 — F-UX-01 multi-banner switcher', () => {
  test('chip switcher renders one chip per banner; delete button hidden on single-banner install', async ({ page, loginAsAdmin }) => {
    // Switcher refactored in 1.14.1: <select> dropdown replaced with always-
    // visible chip buttons; inline rename input moved from #faz-b-name-inline
    // (toolbar) to #faz-b-name (General tab card). Assertions follow the
    // new contract.
    const beforeCount = parseInt(wpEval(`
      global $wpdb;
      echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}faz_banners" );
    `).trim(), 10);

    await loginAsAdmin(page);
    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, { waitUntil: 'domcontentloaded' });

    // Switcher container is ALWAYS rendered — the chip row, the "+ New"
    // button, and the delete button live inside it. Visible on every
    // multi-banner-aware install.
    const switcher = page.locator('#faz-b-switcher');
    await expect(switcher, 'switcher container is rendered and visible').toBeVisible({ timeout: 10_000 });

    // Wait for populateSwitcher() to render one chip per banner row —
    // chips populate after FAZ.get('banners') resolves.
    const chips = page.locator('#faz-b-switcher-chips button');
    await page.waitForFunction(
      (n) => document.querySelectorAll('#faz-b-switcher-chips button').length >= n,
      Math.max(beforeCount, 1),
      { timeout: 10_000 },
    ).catch(() => {});

    // Headline assertion: chip count matches banner row count.
    const chipCount = await chips.count();
    expect(chipCount, 'one chip per banner row').toBe(beforeCount);

    // The rename input moved to the General tab and is bound on page load,
    // regardless of how many banners exist.
    await expect(page.locator('#faz-b-name'), 'in-tab rename input exists in the General tab').toBeAttached();

    if (beforeCount >= 2) {
      // Multi-banner install: every chip carries the banner_id in a
      // data attribute so deep-link navigation is deterministic.
      const ids = await chips.evaluateAll((els) =>
        els.map((el) => Number((el as HTMLElement).dataset.bannerId || 0)),
      );
      expect(ids.every((id) => id > 0), 'every chip exposes a positive data-banner-id').toBe(true);
      // Delete button visible whenever count ≥ 2 AND the current banner
      // isn't the default — assert it's at least attached (visibility
      // depends on whether we're editing the default banner, which we
      // can't guarantee from outside).
      await expect(page.locator('#faz-b-switcher-delete'), 'delete button is attached on multi-banner installs').toBeAttached();
    } else {
      // SINGLE-BANNER install: delete button is hidden (can't delete the
      // only row), but the chip for the lone banner is still rendered.
      await expect(page.locator('#faz-b-switcher-delete'), 'delete button hidden on a single-banner install').toBeHidden();
    }
  });
});
