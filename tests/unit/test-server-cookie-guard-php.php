<?php
/**
 * Standalone regression tests for the opt-in PHP Set-Cookie guard.
 */

namespace FazCookie\Includes {
	class Known_Providers {
		public static function get_all() { return array(); }
		public static function get_cookie_map() { return array(); }
		public static function get_pattern_map() { return array(); }
	}

	/**
	 * Stands in for the Open Cookie Database tier, whose real implementation
	 * reads and decodes a 2.5 MB bundled dataset. Counting the materializations
	 * is what lets the short-circuit test observe that the cascade was skipped,
	 * instead of inferring it from the source text.
	 */
	class Cookie_Definitions {
		public static function get_instance() {
			++$GLOBALS['faz_definition_lookups'];
			return new self();
		}
		public function lookup( $name ) { return null; }
	}
}

namespace FazCookie\Frontend {
	// Frontend calls headers_sent()/headers_list() unqualified from inside its own
	// namespace, so PHP resolves them here before falling back to the globals.
	// That is what lets the disabled-guard test observe whether the response
	// headers were read at all, instead of guessing from the source text.
	function headers_sent() { return false; }
	function headers_list() {
		++$GLOBALS['faz_headers_list_reads'];
		// Nothing cookie-shaped: the caller returns right after this read, which
		// keeps the probe confined to the one question it asks.
		return array( 'Content-Type: text/html' );
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );

	$GLOBALS['faz_headers_list_reads'] = 0;
	$GLOBALS['faz_definition_lookups'] = 0;
	// Front-end page request unless a test says otherwise; the guard branches on
	// this because load_banner() — and therefore $this->template — never runs on
	// REST/AJAX subrequests.
	$GLOBALS['faz_is_front_end'] = true;

	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function apply_filters( $tag, $value ) { return $value; }
	function get_transient( $key ) { return false; }
	function set_transient( $key, $value, $expiration = 0 ) { return true; }
	function wp_strip_all_tags( $value ) { return trim( strip_tags( (string) $value ) ); }
	function wp_doing_cron() { return false; }
	function wp_doing_ajax() { return false; }
	function is_admin() { return false; }
	function faz_disable_banner() { return false; }
	function faz_is_front_end_request() { return (bool) $GLOBALS['faz_is_front_end']; }

	require_once dirname( __DIR__, 2 ) . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	$run = 0;
	$failed = 0;

	function guard_check( $condition, $label ) {
		global $run, $failed;
		++$run;
		if ( $condition ) {
			echo 'PASS ' . $run . ': ' . $label . "\n";
			return;
		}
		++$failed;
		echo 'FAIL ' . $run . ': ' . $label . "\n";
	}

	function guard_frontend( $blocked = array( 'analytics', 'marketing' ), $service_decisions = array() ) {
		$reflection = new \ReflectionClass( Frontend::class );
		$frontend   = $reflection->newInstanceWithoutConstructor();
		$values     = array(
			'blocked_categories_cache'          => $blocked,
			'service_cookie_decisions_cache'    => $service_decisions,
			'whitelisted_cookie_patterns_cache' => array(),
			'catalog_cookie_categories_cache'   => array(
				'brikpanel_vid'                 => 'analytics',
				'PHPSESSID'                     => 'necessary',
				'wp_woocommerce_session_*'      => 'necessary',
			),
			'cookie_allowed_cache'               => array(),
		);
		foreach ( $values as $property => $value ) {
			$ref = new \ReflectionProperty( Frontend::class, $property );
			$ref->setAccessible( true );
			$ref->setValue( $frontend, $value );
		}
		return $frontend;
	}

	function filtered_headers( $frontend, $headers ) {
		return $frontend->filter_outgoing_cookie_header_lines( $headers );
	}

	$frontend = guard_frontend();
	$result = filtered_headers(
		$frontend,
		array(
			'Content-Type: application/json',
			'Set-Cookie: brikpanel_vid=secret-visitor-id; Path=/; Max-Age=31536000; HttpOnly; SameSite=Lax',
			'Set-Cookie: wordpress_logged_in_hash=secret; Path=/; HttpOnly',
			'Set-Cookie: wp_woocommerce_session_lab=cart; Path=/; HttpOnly',
			'Set-Cookie: PHPSESSID=session; Path=/; HttpOnly',
			'Set-Cookie: unknown_plugin_session=opaque; Path=/; HttpOnly',
		)
	);

	guard_check( 1 === count( $result['blocked'] ), 'pre-consent brikpanel_vid is blocked when analytics is denied' );
	guard_check( 'brikpanel_vid' === $result['blocked'][0]['name'], 'blocked diagnostics retain the cookie name' );
	guard_check( false === strpos( serialize( $result['blocked'] ), 'secret-visitor-id' ), 'blocked diagnostics never retain cookie values' );
	guard_check( 4 === count( $result['set_cookie_headers'] ), 'WordPress, WooCommerce, PHP and unknown session cookies remain available' );
	guard_check( false !== strpos( implode( "\n", $result['set_cookie_headers'] ), 'wordpress_logged_in_hash' ), 'WordPress authentication cookie is fail-safe allowed' );
	guard_check( false !== strpos( implode( "\n", $result['set_cookie_headers'] ), 'wp_woocommerce_session_lab' ), 'WooCommerce session cookie is fail-safe allowed' );
	guard_check( false !== strpos( implode( "\n", $result['set_cookie_headers'] ), 'unknown_plugin_session' ), 'unclassified cookies fail permissive to protect login and checkout' );

	$consented = filtered_headers(
		guard_frontend( array() ),
		array( 'Set-Cookie: brikpanel_vid=consented; Path=/; Max-Age=31536000; HttpOnly' )
	);
	guard_check( empty( $consented['blocked'] ) && 1 === count( $consented['set_cookie_headers'] ), 'analytics consent allows the one-year HttpOnly cookie' );

	$deletion = filtered_headers(
		guard_frontend(),
		array( 'Set-Cookie: brikpanel_vid=; Path=/; Max-Age=0; HttpOnly' )
	);
	guard_check( empty( $deletion['blocked'] ) && 1 === count( $deletion['set_cookie_headers'] ), 'cookie deletion headers always pass through' );

	$explicit_deny = filtered_headers(
		guard_frontend( array(), array( 'brikpanel_vid' => array( 'no' ) ) ),
		array( 'Set-Cookie: brikpanel_vid=denied; Path=/; HttpOnly' )
	);
	guard_check( 1 === count( $explicit_deny['blocked'] ), 'an explicit cookie/service denial wins over category consent' );

	// With nothing blocked and no per-service decision, every remaining path in
	// is_cookie_allowed() returns true, so the classification cascade — whose
	// last tier materializes the bundled Open Cookie Database — must not run at
	// all. The counter assertion below it proves the probe can see a
	// materialization, so the first one cannot pass on a dead counter.
	$GLOBALS['faz_definition_lookups'] = 0;
	$nothing_blocked = guard_frontend( array(), array() );
	guard_check( true === $nothing_blocked->is_cookie_allowed( 'entirely_unknown_cookie' ), 'an unclassified cookie stays allowed when nothing is blocked' );
	guard_check( 0 === $GLOBALS['faz_definition_lookups'], 'no blocked categories and no service decisions short-circuit before the Open Cookie Database is materialized' );

	$GLOBALS['faz_definition_lookups'] = 0;
	guard_frontend()->is_cookie_allowed( 'entirely_unknown_cookie' );
	guard_check( 1 === $GLOBALS['faz_definition_lookups'], 'the classification cascade still runs when a category IS blocked' );

	// The short-circuit must test BOTH conditions: a per-service or per-cookie
	// denial stays enforceable on a site where no category is blocked, and
	// guarding on the category list alone would silently stop honouring it.
	$denied_without_blocked_category = guard_frontend( array(), array( 'brikpanel_vid' => array( 'no' ) ) );
	guard_check( false === $denied_without_blocked_category->is_cookie_allowed( 'brikpanel_vid' ), 'an explicit denial is still honoured when no category is blocked' );

	$settings_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/settings/includes/class-settings.php' );
	guard_check( (bool) preg_match( "/'block_server_cookies'\\s*=>\\s*false/", $settings_source ), 'PHP Set-Cookie blocking is opt-in by default' );

	/**
	 * Build a Frontend whose only configured input is the opt-in switch, then run
	 * the header-rewriting entry point on it.
	 *
	 * @param bool  $block_server_cookies Whether the operator opted in.
	 * @param array $overrides            Property values replacing the defaults.
	 * @return int Number of times the response header list was read.
	 */
	function guard_header_reads( $block_server_cookies, $overrides = array() ) {
		$GLOBALS['faz_headers_list_reads'] = 0;
		$frontend = guard_frontend();
		$values   = array_merge(
			array(
				'settings_option_cache' => array(
					'script_blocking' => array( 'block_server_cookies' => $block_server_cookies ),
				),
				// A resolved template is load_banner()'s record that a banner was
				// built for this visitor. The guard reads it rather than
				// re-deriving the six conditions that can suppress a banner.
				'template'              => '<div data-faz-tag="notice"></div>',
				// Only consulted once the switch is on; an empty exclusion list keeps
				// the page-level opt-out out of the way.
				'settings'              => new class() {
					public function get( $group, $key ) { return array(); }
				},
			),
			$overrides
		);
		foreach ( $values as $property => $value ) {
			$ref = new \ReflectionProperty( Frontend::class, $property );
			$ref->setAccessible( true );
			$ref->setValue( $frontend, $value );
		}
		$method = new \ReflectionMethod( Frontend::class, 'filter_current_set_cookie_headers' );
		$method->setAccessible( true );
		$method->invoke( $frontend );
		return $GLOBALS['faz_headers_list_reads'];
	}

	// Reading headers_list() on a site that never opted in is the failure this
	// pins: it means the guard ran, and a rewrite of the Set-Cookie set is one
	// step away. The opted-in case proves the probe can see a read at all —
	// without it the first assertion would pass on a guard that is simply dead.
	$GLOBALS['faz_is_front_end'] = true;
	guard_check( 0 === guard_header_reads( false ), 'disabled guard exits before reading response headers' );
	guard_check( 1 === guard_header_reads( true ), 'enabled guard does read the response headers' );

	// Stand-down pins. A visitor shown no banner has no script.js and therefore
	// no way to ever record consent, so a stripped Set-Cookie header would be
	// permanent with no remedy — the guard must not run for them at all.
	guard_check(
		0 === guard_header_reads( true, array( 'template' => null ) ),
		'a front-end request with no banner (geo no-banner routing, no active banner) stands the guard down'
	);
	guard_check(
		0 === guard_header_reads(
			true,
			array(
				'settings' => new class() {
					public function get( $group, $key ) { return 'status' === $key ? false : array(); }
				},
			)
		),
		'banner_control status false stands the guard down site-wide'
	);

	// …but the subrequest coverage must survive that gate: faz_is_front_end_request()
	// is false on REST/admin-ajax, where load_banner() never runs and the
	// template is structurally null. Gating on the template alone would silently
	// kill the one thing this guard does that the shredder cannot.
	$GLOBALS['faz_is_front_end'] = false;
	guard_check(
		1 === guard_header_reads( true, array( 'template' => null ) ),
		'REST/AJAX subrequests stay guarded even though load_banner() never set a template'
	);
	guard_check(
		0 === guard_header_reads(
			true,
			array(
				'template' => null,
				'settings' => new class() {
					public function get( $group, $key ) { return 'status' === $key ? false : array(); }
				},
			)
		),
		'banner_control status false stands the guard down on subrequests too'
	);
	$GLOBALS['faz_is_front_end'] = true;

	echo $run . ' checks, ' . $failed . " failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
