/**
 * Pin the behavioural contract of the cache-plugin auto-exclusion block
 * introduced in 1.13.1 and hardened in 1.13.2 (PR #83 + post-review fixes).
 *
 * Covers:
 *   - `tag_own_scripts_nooptimize` emits the 5 opt-out data-attrs on
 *     every FAZ <script> in the composite `script_loader_tag` blob.
 *   - Alt-asset handle family (`faz-fw`, `faz-fw-gcm`, `faz-fw-tcf-cmp`,
 *     `faz-fw-a11y`) is recognised by `is_own_script_handle()` — exercised
 *     via reflection since enabling alt-asset mode in the test DB would
 *     mutate plugin state across the suite.
 *   - `litespeed_exclude_own_scripts*` callbacks return path-anchored
 *     results that don't collaterally remove third-party entries.
 *   - `rocket_exclude_own_scripts` and `autoptimize_exclude_own_scripts`
 *     append the plugin path without munging existing entries.
 *   - `faz_auto_exclude_cache_plugins` opt-out hatch actually unregisters
 *     the filters when forced to false.
 */
import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://localhost:9998';

test.describe('Cache-plugin auto-exclude (#83 + 1.13.2 post-review)', () => {
  test('frontend <script> tags for own handles carry all 5 opt-out attributes', async ({ page }) => {
    // Hit any public URL that enqueues the FAZ frontend scripts; we
    // don't need a specific page — the root homepage enqueues them by
    // default when the banner template is loaded.
    const resp = await page.request.get(`${WP_BASE}/?diag=${Date.now()}`);
    expect(resp.ok()).toBe(true);
    const html = await resp.text();

    // Every FAZ <script> tag (main src, inline before, inline after,
    // gcm, tcf-cmp, a11y — but not the `-extra` localize payload, which
    // doesn't go through script_loader_tag) must carry all 5 hints.
    const fazScriptTags = html.match(/<script[^>]*faz-cookie-manager[^>]*>/g) ?? [];
    expect(fazScriptTags.length).toBeGreaterThan(0);

    for (const tag of fazScriptTags) {
      // `-extra` is the wp_localize_script payload — goes through a
      // different WP code path that does not fire script_loader_tag.
      if (tag.includes('faz-cookie-manager-js-extra')
        || tag.includes('faz-cookie-manager-a11y-js-extra')
        || tag.includes('faz-cookie-manager-gcm-js-extra')
        || tag.includes('faz-cookie-manager-tcf-cmp-js-extra')) {
        continue;
      }
      expect(tag, `missing data-no-defer on: ${tag.slice(0, 120)}`).toContain('data-no-defer="1"');
      expect(tag, `missing data-no-optimize on: ${tag.slice(0, 120)}`).toContain('data-no-optimize="1"');
      expect(tag, `missing data-no-minify on: ${tag.slice(0, 120)}`).toContain('data-no-minify="1"');
      expect(tag, `missing data-cfasync on: ${tag.slice(0, 120)}`).toContain('data-cfasync="false"');
      expect(tag, `missing data-ao-skip on: ${tag.slice(0, 120)}`).toContain('data-ao-skip="1"');
    }
  });

  test('is_own_script_handle() recognises alt-asset family via reflection', async () => {
    const raw = wpEval(`
      $fe = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $r  = new ReflectionClass( $fe );
      $m  = $r->getMethod( 'is_own_script_handle' );
      $m->setAccessible( true );
      $cases = array(
        'faz-cookie-manager'               => 'base',
        'faz-cookie-manager-gcm'           => 'base_gcm',
        'faz-cookie-manager-tcf-cmp'       => 'base_tcf',
        'faz-cookie-manager-a11y'          => 'base_a11y',
        'faz-cookie-manager-wca'           => 'base_wca',
        'faz-cookie-manager-microsoft-consent' => 'base_ms',
        'faz-fw'                           => 'alt',
        'faz-fw-gcm'                       => 'alt_gcm',
        'faz-fw-tcf-cmp'                   => 'alt_tcf',
        'faz-fw-a11y'                      => 'alt_a11y',
        // New-in-future handle — should match without a code change.
        'faz-cookie-manager-stripe-sdk'    => 'future',
        // Negatives.
        'other-plugin-js'                  => 'negative_other',
        'faz-cookie-manager-like-but-not'  => 'dash_but_not_a_child',
        ''                                 => 'empty',
      );
      $out = array();
      foreach ( $cases as $handle => $label ) {
        $out[ $label ] = (bool) $m->invoke( $fe, $handle );
      }
      echo wp_json_encode( $out );
    `).trim();
    const result = JSON.parse(raw) as Record<string, boolean>;

    // Positives — must all match (the alt-asset family is the hardening
    // that landed in 1.13.2).
    for (const key of [
      'base', 'base_gcm', 'base_tcf', 'base_a11y', 'base_wca', 'base_ms',
      'alt', 'alt_gcm', 'alt_tcf', 'alt_a11y',
      'future',
      'dash_but_not_a_child', // `faz-cookie-manager-like-but-not` DOES start with `faz-cookie-manager-` so it matches; that's intentional
    ]) {
      expect(result[key], `${key} should be recognised as an own handle`).toBe(true);
    }
    // Negatives.
    expect(result.negative_other).toBe(false);
    expect(result.empty).toBe(false);
  });

  test('litespeed_exclude_own_scripts_from_include is path-anchored (no false-positive scrub)', async () => {
    const raw = wpEval(`
      $fe = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      // Admin's original include list contains a legitimate third-party
      // entry whose file name happens to contain the substring
      // "faz-cookie-manager" (e.g. an integration plugin). Under the
      // 1.13.1 behaviour that entry would be wrongly stripped.
      $input = array(
        'some-admin-include.js',
        'my-integration-faz-cookie-manager-compat.js',          // third-party, must stay
        'wp-content/plugins/faz-cookie-manager/frontend/js/a.js', // our path, must go
        'wp-content/plugins/faz-cookie-manager/frontend/js/b.js', // our path, must go
      );
      $out = $fe->litespeed_exclude_own_scripts_from_include( $input );
      echo wp_json_encode( $out );
    `).trim();
    const result = JSON.parse(raw) as string[];

    expect(result).toContain('some-admin-include.js');
    expect(result).toContain('my-integration-faz-cookie-manager-compat.js');
    expect(result.find((v) => v.includes('plugins/faz-cookie-manager/'))).toBeUndefined();
  });

  test('rocket_exclude_own_scripts and autoptimize callback append without munging', async () => {
    const raw = wpEval(`
      $fe = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $rocket_in   = array( 'some/other/pattern' );
      $rocket_out  = $fe->rocket_exclude_own_scripts( $rocket_in );
      $ao_in       = 'jquery.js, admin-bar.js';
      $ao_out      = $fe->autoptimize_exclude_own_scripts( $ao_in );
      $ls_string_in  = "foo.js\\nbar.js";
      $ls_string_out = $fe->litespeed_exclude_own_scripts( $ls_string_in );
      echo wp_json_encode( array(
        'rocket_out'    => $rocket_out,
        'ao_out'        => $ao_out,
        'ls_string_out' => $ls_string_out,
      ) );
    `).trim();
    const result = JSON.parse(raw) as { rocket_out: string[]; ao_out: string; ls_string_out: string };

    expect(result.rocket_out).toContain('some/other/pattern');
    expect(result.rocket_out.some((p: string) => p.includes('faz-cookie-manager'))).toBe(true);

    expect(result.ao_out).toContain('jquery.js');
    expect(result.ao_out).toContain('admin-bar.js');
    expect(result.ao_out).toContain('faz-cookie-manager');

    expect(result.ls_string_out).toContain('foo.js');
    expect(result.ls_string_out).toContain('bar.js');
    expect(result.ls_string_out).toContain('plugins/faz-cookie-manager/');
  });

  test('faz_auto_exclude_cache_plugins opt-out hatch suppresses all filter registrations', async () => {
    // Drive the filter callback to false, construct a fresh Frontend,
    // and assert that the cache-plugin hooks are NOT registered on its
    // filter handles. Uses reflection on the global $wp_filter registry.
    const raw = wpEval(`
      add_filter( 'faz_auto_exclude_cache_plugins', '__return_false' );
      $fe = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $filters_to_check = array(
        'litespeed_optm_js_defer_exc',
        'litespeed_optm_js_delay_inc',
        'litespeed_optimize_js_excludes',
        'rocket_exclude_defer_js',
        'rocket_delay_js_exclusions',
        'rocket_minify_excluded_external_js',
        'autoptimize_filter_js_exclude',
      );
      global $wp_filter;
      $registered = array();
      foreach ( $filters_to_check as $f ) {
        $has_ours = false;
        if ( isset( $wp_filter[ $f ] ) ) {
          foreach ( $wp_filter[ $f ]->callbacks as $prio => $cbs ) {
            foreach ( $cbs as $cb ) {
              if ( is_array( $cb['function'] ) && is_object( $cb['function'][0] ) && $cb['function'][0] === $fe ) {
                $has_ours = true;
              }
            }
          }
        }
        $registered[ $f ] = $has_ours;
      }
      remove_all_filters( 'faz_auto_exclude_cache_plugins' );
      echo wp_json_encode( $registered );
    `).trim();
    const registered = JSON.parse(raw) as Record<string, boolean>;

    for (const filterName of Object.keys(registered)) {
      expect(registered[filterName], `${filterName} must NOT be registered when hatch is false`).toBe(false);
    }
  });
});
