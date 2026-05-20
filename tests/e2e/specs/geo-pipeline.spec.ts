/**
 * E2E — Geo pipeline (spec 001 tasks T026 + T027).
 *
 * Exercises Geo_Routing::get_visitor_context() across:
 *   - VPN gate (forced fallback regardless of country)
 *   - country resolution via filter override (mocks CF-IPCountry)
 *   - XX / unknown → fallback ruleset
 *   - Admin override per-country
 *
 * Uses `apply_filters('faz_geo_admin_override_country', ...)` from a
 * filter registered via wpEval to deterministically inject the country
 * without depending on Cloudflare headers or real geo lookup.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

test.describe('Geo_Routing pipeline (P3 — T026/T027)', () => {
  test('admin override → resolves to gdpr-strict for IT', () => {
    const raw = wpEval(`
      add_filter( 'faz_geo_admin_override_country', function() { return 'IT'; } );

      $orchestrator = \\FazCookie\\Admin\\Modules\\Geo_Routing\\Geo_Routing::get_instance();
      $ctx = $orchestrator->get_visitor_context();

      echo wp_json_encode( array(
        'country'    => $ctx['country'] ?? null,
        'ruleset_id' => $ctx['ruleset_id'] ?? null,
        'source'     => $ctx['source'] ?? null,
        'has_ruleset_json' => is_array( $ctx['ruleset'] ?? null ),
      ) );
    `).trim();

    const data = JSON.parse(raw);
    expect(data.country).toBe('IT');
    expect(data.ruleset_id).toBe('gdpr-strict');
    expect(data.source).toBe('admin_override');
    expect(data.has_ruleset_json).toBe(true);
  });

  test('admin override US + delta → falls through to auto-detect', () => {
    const raw = wpEval(`
      add_filter( 'faz_geo_admin_override_country', function() { return 'US'; } );

      // No region info available without CF header → US no-law fallback (gdpr-strict).
      $orchestrator = \\FazCookie\\Admin\\Modules\\Geo_Routing\\Geo_Routing::get_instance();
      $ctx = $orchestrator->get_visitor_context();

      echo wp_json_encode( array(
        'country'    => $ctx['country'] ?? null,
        'ruleset_id' => $ctx['ruleset_id'] ?? null,
      ) );
    `).trim();

    const data = JSON.parse(raw);
    expect(data.country).toBe('US');
    // US without region → most-protective per Q2 resolution.
    expect(data.ruleset_id).toBe('gdpr-strict');
  });

  test('unknown country → fallback-gdpr-most-protective', () => {
    const raw = wpEval(`
      // Force XX via the admin-override filter so the test asserts a single
      // deterministic outcome instead of accepting both XX and IT (the
      // previous tolerant matcher hid regressions in Geo_Detector's
      // unknown-country handling). XX is the documented sentinel for
      // 'no geolocation available' per spec 001 §FR-02 stage 6.
      add_filter( 'faz_geo_admin_override_country', function() { return 'XX'; } );

      $orchestrator = \\FazCookie\\Admin\\Modules\\Geo_Routing\\Geo_Routing::get_instance();
      $ctx = $orchestrator->get_visitor_context( '127.0.0.1' );

      echo wp_json_encode( array(
        'country'    => $ctx['country'] ?? null,
        'ruleset_id' => $ctx['ruleset_id'] ?? null,
      ) );
    `).trim();

    const data = JSON.parse(raw);
    expect(data.country).toBe('XX');
    expect(data.ruleset_id).toBe('fallback-gdpr-most-protective');
  });

  test('VPN gate → forced fallback regardless of country', () => {
    const raw = wpEval(`
      add_filter( 'faz_geo_admin_override_country', function() { return 'US'; } );

      // Mock ipinfo: bypass HTTP by injecting a class extension.
      // Easier: directly invoke Ruleset_Resolver with vpn=true to verify gate.
      $loader = \\FazCookie\\Admin\\Modules\\Geo_Routing\\Includes\\Ruleset_Loader::get_instance();
      $resolver = '\\\\FazCookie\\\\Admin\\\\Modules\\\\Geo_Routing\\\\Includes\\\\Ruleset_Resolver';

      $id_no_vpn = $resolver::resolve( 'US', 'US-CA', false, array(), $loader->load_index(), $loader->load_us_regions(), $loader->get_fallback_id() );
      $id_vpn    = $resolver::resolve( 'US', 'US-CA', true,  array(), $loader->load_index(), $loader->load_us_regions(), $loader->get_fallback_id() );

      echo wp_json_encode( array(
        'no_vpn' => $id_no_vpn,
        'vpn'    => $id_vpn,
      ) );
    `).trim();

    const data = JSON.parse(raw);
    expect(data.no_vpn, 'US-CA no VPN → ccpa-california').toBe('ccpa-california');
    expect(data.vpn, 'US-CA + VPN → forced fallback (most-protective)').toBe(
      'fallback-gdpr-most-protective',
    );
  });

  test('Ruleset_Loader loads all 3 sample rulesets', () => {
    const raw = wpEval(`
      $loader = \\FazCookie\\Admin\\Modules\\Geo_Routing\\Includes\\Ruleset_Loader::get_instance();
      $all = $loader->list_all();
      sort( $all );

      $r1 = $loader->load_ruleset( 'gdpr-strict' );
      $r2 = $loader->load_ruleset( 'ccpa-california' );
      $r3 = $loader->load_ruleset( 'fallback-gdpr-most-protective' );

      echo wp_json_encode( array(
        'list_all' => $all,
        'gdpr_strict_version' => $r1['version'] ?? null,
        'ccpa_version'        => $r2['version'] ?? null,
        'fallback_version'    => $r3['version'] ?? null,
        'ccpa_gpc_required'   => $r2['signals']['gpc_required'] ?? null,
        'gdpr_equal_buttons'  => $r1['ui']['equal_weight_buttons'] ?? null,
      ) );
    `).trim();

    const data = JSON.parse(raw);
    expect(data.list_all).toEqual([
      'ccpa-california',
      'fallback-gdpr-most-protective',
      'gdpr-strict',
    ]);
    expect(data.gdpr_strict_version).toBe('1.0.0');
    expect(data.ccpa_version).toBe('1.0.0');
    expect(data.fallback_version).toBe('1.0.0');
    expect(data.ccpa_gpc_required).toBe(true);
    expect(data.gdpr_equal_buttons).toBe(true);
  });
});
