/**
 * Regression suite for the 1.13.18 release.
 *
 * Single fix: `*-js-extra` and `*-js-translations` inline payloads (the
 * output of `wp_localize_script()` and `wp_set_script_translations()`) are
 * exempt by default from the provider-substring matcher. These ID shapes
 * carry only data (`var NAME = {...}`) or i18n strings (`wp.i18n.setLocaleData(...)`),
 * never tracker calls, so matching `stripos( $content, $pattern )` against
 * their body produces false positives whenever a config key or a translated
 * string incidentally contains a provider name.
 *
 * Canonical bug: trx_addons (ThemeREX) emits a localize payload with the
 * config key `animate_to_mc4wp_form_submitted`. The substring `mc4wp`
 * matches MailChimp → category `marketing` → the tag is rewritten to
 * `type="text/plain"` → `var TRX_ADDONS_STORAGE = {...}` never runs →
 * `ReferenceError: TRX_ADDONS_STORAGE is not defined` crashes Elementor's
 * `frontend/init` handler.
 *
 * The fix exempts both ID suffixes (`-js-extra`, `-js-translations`) at
 * the top of `filter_inline_script_tag()` (WP 5.7+) and `process_script_tag()`
 * (output-buffer fallback for WP < 5.7), keeping `-js-before` / `-js-after`
 * on the regular path because `wp_add_inline_script()` with those positions
 * accepts arbitrary executable code.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';

test.describe('1.13.18 — wp_localize_script payloads exempt from content-substring blocking', () => {
  test('filter_inline_script_tag leaves a `-js-extra` payload intact even when its body contains a provider substring', () => {
    // Build a tag shape identical to what wp_localize_script emits, with
    // a body that contains the literal substring `mc4wp` inside a
    // config-key value (the trx_addons reproduction).
    const result = wpEval(`
      $tag = '<script id="trx-mock-js-extra">var TRX_MOCK_STORAGE = {"site_url":"http://example.test","animate_to_mc4wp_form_submitted":"1"};</script>';
      $filtered = apply_filters( 'wp_inline_script_tag', $tag, 'trx-mock-js-extra', 'trx_mock' );
      $rewritten = ( false !== strpos( $filtered, 'type="text/plain"' ) || false !== strpos( $filtered, 'data-faz-category=' ) );
      echo $rewritten ? 'BLOCKED' : 'PASSTHROUGH';
    `).trim();

    expect(result, '`-js-extra` payload with `mc4wp` substring must pass through unchanged').toBe('PASSTHROUGH');
  });

  test('filter_inline_script_tag leaves a `-js-translations` payload intact even when its body contains a provider substring', () => {
    const result = wpEval(`
      $tag = '<script id="trx-mock-js-translations">( function( domain, translations ) { var localeData = translations.locale_data[ domain ] || translations.locale_data.messages; wp.i18n.setLocaleData( { "Sign up to mc4wp": [ "Iscriviti a mc4wp" ] }, "trx_mock" ); } )( "trx_mock", {"locale_data":{"trx_mock":{"":{}}}} );</script>';
      $filtered = apply_filters( 'wp_inline_script_tag', $tag, 'trx-mock-js-translations', 'trx_mock' );
      $rewritten = ( false !== strpos( $filtered, 'type="text/plain"' ) || false !== strpos( $filtered, 'data-faz-category=' ) );
      echo $rewritten ? 'BLOCKED' : 'PASSTHROUGH';
    `).trim();

    expect(result, '`-js-translations` payload with `mc4wp` substring must pass through unchanged').toBe('PASSTHROUGH');
  });

  test('filter_inline_script_tag STILL blocks a `-js-before` payload when its body looks like a tracker call', () => {
    // `wp_add_inline_script( $handle, $code, 'before' )` accepts arbitrary
    // executable code, so the matcher must keep examining its body.
    // The substring `mc4wp` here is part of a function call shape, not a
    // config key — but the exemption is about the ID SUFFIX, not body
    // content, and `-js-before` is NOT exempted.
    const result = wpEval(`
      $tag = '<script id="trx-mock-js-before">window.mc4wp = window.mc4wp || []; window.mc4wp.push({event:"x"});</script>';
      $filtered = apply_filters( 'wp_inline_script_tag', $tag, 'trx-mock-js-before', 'trx_mock' );
      $rewritten = ( false !== strpos( $filtered, 'type="text/plain"' ) && false !== strpos( $filtered, 'data-faz-category="marketing"' ) );
      echo $rewritten ? 'BLOCKED' : 'PASSTHROUGH';
    `).trim();

    expect(result, '`-js-before` payload (executable code path) must still be blocked when the body matches a provider').toBe('BLOCKED');
  });

  test('process_script_tag (output-buffer fallback for WP < 5.7) leaves `-js-extra` payloads intact too', () => {
    // The output-buffer path is hit on WP < 5.7 (no wp_inline_script_tag
    // filter) and as a defense-in-depth catch-all on every version for
    // scripts injected outside the WP enqueue system. Reflection: build
    // a (Frontend) instance and call its private process_script_tag()
    // directly with a regex match shaped like the real OB pipeline.
    const result = wpEval(`
      $instance = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $reflection = new ReflectionClass( $instance );
      $method = $reflection->getMethod( 'process_script_tag' );
      $method->setAccessible( true );

      $providers_method = $reflection->getMethod( 'get_provider_category_map' );
      $providers_method->setAccessible( true );
      $providers = $providers_method->invoke( $instance );

      $blocked = array( 'analytics', 'marketing', 'functional', 'performance' );

      $tag = '<script id="trx-mock-js-extra">var TRX_MOCK_STORAGE = {"animate_to_mc4wp_form_submitted":"1"};</script>';

      // Defensive: if preg_match somehow fails on the hardcoded tag above (it
      // shouldn't — that would mean someone mutated the literal), fail loud
      // with REGEX_FAIL rather than passing an empty $m to invokeArgs() and
      // letting process_script_tag() see undefined indices. The test below
      // asserts PASSTHROUGH, so a silent failure would mimic the success path.
      if ( 1 !== preg_match( '/<script([^>]*)>(.*?)<\\/script>/s', $tag, $m ) ) {
        echo 'REGEX_FAIL';
        return;
      }

      $result = $method->invokeArgs( $instance, array( $m, $providers, $blocked ) );
      $rewritten = ( false !== strpos( $result, 'type="text/plain"' ) || false !== strpos( $result, 'data-faz-category=' ) );
      echo $rewritten ? 'BLOCKED' : 'PASSTHROUGH';
    `).trim();

    expect(result, 'OB-path `-js-extra` payload must pass through unchanged').toBe('PASSTHROUGH');
  });

  test('the new is_wp_localize_or_translations_inline_id helper returns true only for the documented ID shapes', () => {
    const result = wpEval(`
      $instance = new \\FazCookie\\Frontend\\Frontend( 'faz-cookie-manager', '1.0' );
      $reflection = new ReflectionClass( $instance );
      $method = $reflection->getMethod( 'is_wp_localize_or_translations_inline_id' );
      $method->setAccessible( true );

      $cases = array(
        'trx_addons-js-extra'                  => true,
        'wp-i18n-js-translations'              => true,
        'someplugin-js-before'                 => false,
        'someplugin-js-after'                  => false,
        'random-script-id'                     => false,
        ''                                     => false,
        // Edge: legitimate IDs whose handle base happens to end in
        // "-js" should still match because preg_match anchors on $.
        'analytics-handler-js-extra'           => true,
      );

      $out = array();
      foreach ( $cases as $id => $expected ) {
        $actual = $method->invoke( $instance, $id );
        $out[] = ( $expected === $actual ) ? 'OK' : sprintf( 'FAIL[%s expected=%s actual=%s]', $id, var_export( $expected, true ), var_export( $actual, true ) );
      }
      echo implode( ';', $out );
    `).trim();

    expect(result.split(';').every((token) => token === 'OK'), result).toBe(true);
  });
});
