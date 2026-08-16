<?php
/**
 * Standalone regression tests for the opt-in PHP Set-Cookie guard and for the
 * split between cookie CLASSIFICATION (wide, informational) and cookie
 * ENFORCEMENT (narrow, destructive).
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
	 *
	 * The two rows reproduce the two shapes that matter in the real snapshot:
	 * an exact name that collides with an ordinary first-party cookie
	 * (`sessionid` → Instagram → Marketing) and a two-character WILDCARD prefix
	 * (`_s` → Marfeel → Analytics) that swallows any name starting with `_s`.
	 */
	class Cookie_Definitions {
		public static function get_instance() {
			++$GLOBALS['faz_definition_lookups'];
			return new self();
		}
		public function lookup( $name ) {
			$key = strtolower( (string) $name );
			if ( 'sessionid' === $key ) {
				return array( 'name' => 'sessionid', 'category' => 'marketing', 'wildcard' => false );
			}
			if ( 0 === strpos( $key, '_s' ) ) {
				return array( 'name' => '_s', 'category' => 'analytics', 'wildcard' => true );
			}
			return false;
		}
	}
}

namespace FazCookie\Admin\Modules\Cookies\Includes {
	// Minimal stand-ins for the admin catalogue, so get_catalog_cookie_categories()
	// can be exercised for real instead of having its output injected.
	class Cookie_Categories {
		private $row;
		public function __construct( $row ) { $this->row = (array) $row; }
		public function get_id() { return $this->row['id']; }
		public function get_slug() { return $this->row['slug']; }
	}
	class Cookie {
		private $row;
		public function __construct( $row ) { $this->row = (array) $row; }
		public function get_name() { return $this->row['name']; }
		public function get_category() { return $this->row['category']; }
	}
	class Category_Controller {
		public static function get_instance() { return new self(); }
		public function get_items() { return $GLOBALS['faz_catalog_categories']; }
	}
	class Cookie_Controller {
		public static function get_instance() { return new self(); }
		public function get_items() { return $GLOBALS['faz_catalog_cookies']; }
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
		// Configurable so a test can put a real third-party Set-Cookie in the
		// response and then assert it was left alone. The default is nothing
		// cookie-shaped: the caller returns right after this read, which keeps
		// the read-count probe confined to the one question it asks.
		return $GLOBALS['faz_headers_list_payload'];
	}
	// Rewriting the response is the destructive act the guard exists to
	// perform. Counting it — rather than only counting the header READ — is what
	// makes "the guard stood down" an observation rather than an inference.
	// Stubbing them also keeps PHP's "headers already sent" CLI warnings out of
	// the test output.
	function header_remove( $name = null ) { ++$GLOBALS['faz_header_removals']; }
	function header( $header, $replace = true, $response_code = 0 ) { $GLOBALS['faz_headers_emitted'][] = $header; }
	function setcookie( $name, $value = '', $expires = 0, $path = '', $domain = '', $secure = false, $httponly = false ) {
		++$GLOBALS['faz_setcookie_calls'];
		$GLOBALS['faz_setcookie_names'][] = $name;
		return true;
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );

	$GLOBALS['faz_headers_list_reads']   = 0;
	$GLOBALS['faz_headers_list_payload'] = array( 'Content-Type: text/html' );
	$GLOBALS['faz_header_removals']      = 0;
	$GLOBALS['faz_headers_emitted']      = array();
	$GLOBALS['faz_setcookie_calls']      = 0;
	$GLOBALS['faz_setcookie_names']      = array();
	$GLOBALS['faz_definition_lookups']   = 0;
	$GLOBALS['faz_filter_overrides']     = array();
	$GLOBALS['faz_transient_reads']      = array();
	$GLOBALS['faz_transient_writes']     = array();
	$GLOBALS['faz_catalog_categories']   = array();
	$GLOBALS['faz_catalog_cookies']      = array();
	// Front-end page request unless a test says otherwise; the guard branches on
	// this because load_banner() — and therefore $this->template — never runs on
	// REST/AJAX subrequests.
	$GLOBALS['faz_is_front_end'] = true;

	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function apply_filters( $tag, $value ) {
		return array_key_exists( $tag, $GLOBALS['faz_filter_overrides'] ) ? $GLOBALS['faz_filter_overrides'][ $tag ] : $value;
	}
	function do_action( $tag ) { return null; }
	function wp_unslash( $value ) { return $value; }
	function get_transient( $key ) {
		return array_key_exists( $key, $GLOBALS['faz_transient_reads'] ) ? $GLOBALS['faz_transient_reads'][ $key ] : false;
	}
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['faz_transient_writes'][ $key ] = $value;
		return true;
	}
	function wp_strip_all_tags( $value ) { return trim( strip_tags( (string) $value ) ); }
	function wp_doing_cron() { return false; }
	function wp_doing_ajax() { return false; }
	function is_admin() { return false; }
	function faz_disable_banner() { return false; }
	function faz_get_cookie_domain() { return ''; }
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

	function guard_frontend( $blocked = array( 'analytics', 'marketing' ), $service_decisions = array(), $catalog = null ) {
		$reflection = new \ReflectionClass( Frontend::class );
		$frontend   = $reflection->newInstanceWithoutConstructor();
		$values     = array(
			'blocked_categories_cache'          => $blocked,
			'service_cookie_decisions_cache'    => $service_decisions,
			'whitelisted_cookie_patterns_cache' => array(),
			'catalog_cookie_categories_cache'   => null === $catalog ? array(
				'brikpanel_vid'                 => 'analytics',
				'PHPSESSID'                     => 'necessary',
				'wp_woocommerce_session_*'      => 'necessary',
			) : $catalog,
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
	// all. The counter assertion further down proves the probe can see a
	// materialization, so this one cannot pass on a dead counter.
	$GLOBALS['faz_definition_lookups'] = 0;
	$nothing_blocked = guard_frontend( array(), array() );
	guard_check( true === $nothing_blocked->is_cookie_allowed( 'entirely_unknown_cookie' ), 'an unclassified cookie stays allowed when nothing is blocked' );
	guard_check( 0 === $GLOBALS['faz_definition_lookups'], 'no blocked categories and no service decisions short-circuit before the Open Cookie Database is materialized' );

	// The short-circuit must test BOTH conditions: a per-service or per-cookie
	// denial stays enforceable on a site where no category is blocked, and
	// guarding on the category list alone would silently stop honouring it.
	$denied_without_blocked_category = guard_frontend( array(), array( 'brikpanel_vid' => array( 'no' ) ) );
	guard_check( false === $denied_without_blocked_category->is_cookie_allowed( 'brikpanel_vid' ), 'an explicit denial is still honoured when no category is blocked' );

	$settings_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/settings/includes/class-settings.php' );
	guard_check( (bool) preg_match( "/'block_server_cookies'\\s*=>\\s*false/", $settings_source ), 'PHP Set-Cookie blocking is opt-in by default' );

	/* ───────────────────────────────────────────────────────────────────────
	 * Classification vs enforcement (F013).
	 *
	 * The regression these pin is a documented production incident: routing
	 * deletion through the wide classifier meant "we do not know what this
	 * cookie is" resolved to "delete it", and wpDiscuz comment nonces were
	 * shredded server-side on a live site (gooloo.de, commit ad72cd3).
	 * ─────────────────────────────────────────────────────────────────────── */

	// The scanner writes every cookie it cannot recognise as 'uncategorized',
	// and that slug is blocked pre-consent like any other non-necessary
	// category. It is the ABSENCE of a classification, so it must fail
	// permissive. brikpanel_vid in the same fixture is the live control: it
	// proves the blocked list is actually being consulted, so a green
	// "survives" is not just "nothing was blocked at all".
	$catalogued = guard_frontend(
		array( 'analytics', 'marketing', 'uncategorized' ),
		array(),
		array(
			'brikpanel_vid'         => 'analytics',
			'some_plugin_sess'      => 'uncategorized',
			'wpdiscuz_nonce_ab12cd' => 'marketing',
			'lscache_vary'          => 'marketing',
		)
	);
	guard_check( false === $catalogued->is_cookie_allowed( 'brikpanel_vid' ), 'a catalogued analytics cookie is still enforced pre-consent' );
	guard_check( true === $catalogued->is_cookie_allowed( 'some_plugin_sess' ), 'a catalogue row categorised uncategorized survives — unknown must fail permissive' );
	// Even a catalogue row that names a real blocked category cannot delete a
	// cookie the plugin refuses to SHOW in the banner. Deleting the line that
	// delegates is_always_allowed_cookie_name() to is_wp_internal_cookie()
	// turns both of these red.
	guard_check( true === $catalogued->is_cookie_allowed( 'wpdiscuz_nonce_ab12cd' ), 'the wpDiscuz comment nonce survives even when catalogued as marketing' );
	guard_check( true === $catalogued->is_cookie_allowed( 'lscache_vary' ), 'the LiteSpeed page-cache key survives even when catalogued as marketing' );

	// A cookie only the bundled Open Cookie Database recognises is not
	// enforceable: the dataset is a third-party snapshot, refreshable from
	// GitHub, and `sessionid` there means Instagram/Marketing while on an
	// ordinary site it is a first-party session token.
	$ocd_only = guard_frontend( array( 'analytics', 'marketing' ) );
	guard_check( true === $ocd_only->is_cookie_allowed( 'sessionid' ), 'a cookie classified only by the Open Cookie Database is not deletable' );

	// …but the dataset must stay in the diagnostic/admin path, which is the
	// half of the split that is a genuine accuracy win. This also proves the
	// lookup counter is alive, so the "0 lookups" assertions below mean
	// something.
	$GLOBALS['faz_definition_lookups'] = 0;
	$diagnostic = filtered_headers(
		guard_frontend( array( 'analytics', 'marketing' ), array( 'sessionid' => array( 'no' ) ) ),
		array( 'Set-Cookie: sessionid=abc; Path=/; HttpOnly' )
	);
	guard_check( 1 === count( $diagnostic['blocked'] ) && 'marketing' === $diagnostic['blocked'][0]['category'], 'the wide classifier still reports the Open Cookie Database category in diagnostics' );
	guard_check( $GLOBALS['faz_definition_lookups'] >= 1, 'the diagnostic path still materializes the Open Cookie Database' );

	$GLOBALS['faz_definition_lookups'] = 0;
	guard_frontend()->is_cookie_allowed( 'entirely_unknown_cookie' );
	guard_check( 0 === $GLOBALS['faz_definition_lookups'], 'enforcement never materializes the Open Cookie Database, even when a category IS blocked' );

	// Opting the bundled datasets into enforcement is supported, and even then a
	// wildcard hit shorter than ENFORCEABLE_WILDCARD_MIN_LENGTH is refused: the
	// snapshot ships two-character prefixes (`_s`, `ct`, `sr`, `tp`) matched by
	// a bare strpos(). The exact-name assertion beside it proves the filter
	// really did hand the dataset authority, so `_session` surviving is the
	// length rule working, not the filter being ignored.
	$GLOBALS['faz_filter_overrides']['faz_shred_uses_cookie_database'] = true;
	$opted_in = guard_frontend( array( 'analytics', 'marketing' ) );
	guard_check( false === $opted_in->is_cookie_allowed( 'sessionid' ), 'the opt-in filter does hand the bundled dataset enforcement authority for exact names' );
	guard_check( true === $opted_in->is_cookie_allowed( '_session' ), 'an Open Cookie Database wildcard prefix of 2 characters never authorises deletion' );
	unset( $GLOBALS['faz_filter_overrides']['faz_shred_uses_cookie_database'] );

	/* ── The catalogue map itself: internal rows filtered, transient bumped ── */

	function guard_catalog_map() {
		$reflection = new \ReflectionClass( Frontend::class );
		$frontend   = $reflection->newInstanceWithoutConstructor();
		$ref        = new \ReflectionProperty( Frontend::class, 'catalog_cookie_categories_cache' );
		$ref->setAccessible( true );
		$ref->setValue( $frontend, null );
		$method = new \ReflectionMethod( Frontend::class, 'get_catalog_cookie_categories' );
		$method->setAccessible( true );
		return $method->invoke( $frontend );
	}

	$GLOBALS['faz_catalog_categories'] = array(
		array( 'id' => 1, 'slug' => 'marketing' ),
		array( 'id' => 2, 'slug' => 'uncategorized' ),
	);
	$GLOBALS['faz_catalog_cookies'] = array(
		array( 'name' => 'brikpanel_vid', 'category' => 1 ),
		array( 'name' => 'wpdiscuz_nonce_ab12cd', 'category' => 1 ),
		array( 'name' => 'lscache_vary', 'category' => 1 ),
		array( 'name' => 'wordpress_logged_in_ab12', 'category' => 2 ),
	);
	$GLOBALS['faz_transient_writes'] = array();
	$catalog_map = guard_catalog_map();
	guard_check( isset( $catalog_map['brikpanel_vid'] ), 'the catalogue map keeps ordinary catalogued cookies' );
	guard_check(
		! isset( $catalog_map['wpdiscuz_nonce_ab12cd'] ) && ! isset( $catalog_map['lscache_vary'] ) && ! isset( $catalog_map['wordpress_logged_in_ab12'] ),
		'the catalogue map skips wordpress-internal rows, as the banner and policy renderers already do'
	);
	guard_check( isset( $GLOBALS['faz_transient_writes']['faz_server_cookie_category_map_v2'] ), 'the filtered catalogue map is persisted under the versioned transient key' );

	// A map persisted by a build without the filter is still sitting in the
	// options table for up to an hour after deploy. Reusing it would make the
	// fix pass in test and stay broken in production.
	$GLOBALS['faz_transient_reads']['faz_server_cookie_category_map'] = array( 'stale_unfiltered_cookie' => 'marketing' );
	$fresh_map = guard_catalog_map();
	guard_check( ! isset( $fresh_map['stale_unfiltered_cookie'] ), 'a map persisted under the pre-filter transient key is not reused' );
	$GLOBALS['faz_transient_reads']['faz_server_cookie_category_map_v2'] = array( 'versioned_cookie' => 'marketing' );
	guard_check( isset( guard_catalog_map()['versioned_cookie'] ), 'the versioned transient key IS read back — the key bump did not simply disable caching' );
	$GLOBALS['faz_transient_reads'] = array();

	/* ── The escape hatch: a raw whitelist entry exempts a cookie by name ── */

	$whitelist_frontend = guard_frontend();
	$compute            = new \ReflectionMethod( Frontend::class, 'compute_whitelisted_cookie_patterns' );
	$compute->setAccessible( true );
	$computed = $compute->invoke( $whitelist_frontend, array( 'my_first_party_sess', 'js' ), array( 'analytics', 'marketing' ) );
	guard_check( in_array( 'my_first_party_sess', $computed, true ), 'an admin whitelist entry is honoured as a literal cookie name, not only via Known_Providers' );
	guard_check( ! in_array( 'js', $computed, true ), 'a sub-3-character whitelist token is still refused' );

	/* ── The shredder is gated on the same opt-in as the header guard (F013.6) ── */

	function guard_shred( $block_server_cookies, $blocked = array( 'analytics', 'marketing' ), $service_decisions = array(), $settings_extra = array() ) {
		$GLOBALS['faz_setcookie_calls'] = 0;
		$GLOBALS['faz_setcookie_names'] = array();
		$frontend = guard_frontend( $blocked, $service_decisions );
		$values   = array(
			'settings_option_cache' => array_merge(
				array( 'script_blocking' => array( 'block_server_cookies' => $block_server_cookies ) ),
				$settings_extra
			),
			'template'              => '<div data-faz-tag="notice"></div>',
			'settings'              => new class() {
				public function get( $group, $key ) { return array(); }
			},
		);
		foreach ( $values as $property => $value ) {
			$ref = new \ReflectionProperty( Frontend::class, $property );
			$ref->setAccessible( true );
			$ref->setValue( $frontend, $value );
		}
		$_COOKIE = array( 'brikpanel_vid' => 'visitor-id' );
		$frontend->shred_non_consented_cookies();
		// is_cookie_allowed() memoizes every name it is asked about, so a
		// non-empty cache is proof the per-cookie loop actually ran. Counting
		// setcookie() alone cannot see the early bail: with nothing blocked the
		// loop reaches the same "allowed" verdict and deletes nothing either
		// way, which is exactly how a removed bail would go unnoticed.
		$cache = new \ReflectionProperty( Frontend::class, 'cookie_allowed_cache' );
		$cache->setAccessible( true );
		return array(
			'setcookies' => $GLOBALS['faz_setcookie_calls'],
			'survived'   => array_key_exists( 'brikpanel_vid', $_COOKIE ),
			'classified' => count( (array) $cache->getValue( $frontend ) ),
		);
	}

	$shred_on = guard_shred( true );
	guard_check( $shred_on['setcookies'] > 0 && false === $shred_on['survived'], 'with the opt-in on, a blocked catalogued cookie is shredded' );
	guard_check( $shred_on['classified'] > 0, 'the enabled shredder does classify the request cookies — the bail probe below is live' );
	$shred_off = guard_shred( false );
	guard_check( 0 === $shred_off['setcookies'] && true === $shred_off['survived'], 'with the opt-in off, the shredder never runs — an upgrade cannot start deleting cookies' );
	$shred_bail = guard_shred( true, array(), array() );
	guard_check(
		0 === $shred_bail['setcookies'] && true === $shred_bail['survived'] && 0 === $shred_bail['classified'],
		'no blocked category and no service decision bails before classifying anything'
	);

	/* ── F044 — Cache Compatibility Mode stands the SHREDDER down too ────────
	 *
	 * The shredder is the destructive twin of the header guard, and under cache
	 * compatibility get_blocked_categories() returns every non-necessary slug
	 * WITHOUT ever reading the consent cookie. A shredder gated only on its own
	 * `block_server_cookies` opt-in therefore passed its gate, resolved a
	 * CONSENTED visitor's analytics cookie to "not allowed", and deleted it on
	 * every front-end render — permanently, since setcookie() with a past
	 * expiry cannot be replayed by any client-side actor.
	 *
	 * The control comes first on purpose: it proves this exact configuration
	 * DOES shred, so the standdown assertion cannot pass on a shredder that was
	 * never going to act. Replacing the server_cookie_guard_enabled() call in
	 * shred_non_consented_cookies() with a private
	 * `empty( $settings['script_blocking']['block_server_cookies'] )` check —
	 * the shape this had before F044 — turns the second red and leaves the
	 * first green.
	 */
	$shred_cache_control = guard_shred( true, array( 'analytics' ) );
	guard_check(
		$shred_cache_control['setcookies'] > 0 && false === $shred_cache_control['survived'] && $shred_cache_control['classified'] > 0,
		'without cache compatibility, an analytics cookie IS shredded — the standdown pin below has a live control'
	);
	$shred_cache_compat = guard_shred(
		true,
		array( 'analytics' ),
		array(),
		array( 'banner_control' => array( 'cache_compatibility' => true ) )
	);
	guard_check(
		0 === $shred_cache_compat['setcookies'] && true === $shred_cache_compat['survived'] && 0 === $shred_cache_compat['classified'],
		'Cache Compatibility Mode stands the shredder down — a consented visitor keeps their cookies'
	);
	$_COOKIE = array();

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
		$GLOBALS['faz_header_removals']    = 0;
		$GLOBALS['faz_headers_emitted']    = array();
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

	/* ───────────────────────────────────────────────────────────────────────
	 * F044 — Cache Compatibility Mode stands the Set-Cookie guard down.
	 *
	 * Under cache compatibility get_blocked_categories() returns every
	 * non-necessary slug UNCONDITIONALLY, returning before it ever reads the
	 * consent cookie. That is fine for script blocking, which the client
	 * runtime can undo, but a Set-Cookie header that was never sent cannot be
	 * replayed by any client-side actor — so a visitor who HAS consented would
	 * lose every classifiable third-party cookie, permanently. The production
	 * standdown already exists in server_cookie_guard_enabled(); this is the
	 * regression pin it was missing. Deleting the
	 * is_cache_compatibility_enabled() clause there turns these red.
	 * ─────────────────────────────────────────────────────────────────────── */

	$GLOBALS['faz_headers_list_payload'] = array(
		'Content-Type: text/html',
		'Set-Cookie: brikpanel_vid=third-party-value; Path=/; Max-Age=31536000; HttpOnly',
		'Set-Cookie: PHPSESSID=session; Path=/; HttpOnly',
	);

	// Control first: with cache compatibility OFF this exact response IS
	// rewritten — the analytics cookie is dropped and the necessary one
	// re-emitted — so the standdown assertion below cannot pass on a guard that
	// was never going to touch anything.
	$cache_control_reads = guard_header_reads( true );
	$cache_control_out   = implode( "\n", $GLOBALS['faz_headers_emitted'] );
	guard_check(
		1 === $cache_control_reads && 1 === $GLOBALS['faz_header_removals'] && 1 === count( $GLOBALS['faz_headers_emitted'] ),
		'without cache compatibility the guard does rewrite the response Set-Cookie set'
	);
	guard_check(
		false === strpos( $cache_control_out, 'brikpanel_vid' ) && false !== strpos( $cache_control_out, 'PHPSESSID' ),
		'the rewritten response drops the blocked cookie and keeps the necessary one'
	);

	$cache_compat_settings = array(
		'settings_option_cache' => array(
			'script_blocking' => array( 'block_server_cookies' => true ),
			'banner_control'  => array( 'cache_compatibility' => true ),
		),
	);
	$cache_compat_reads = guard_header_reads( true, $cache_compat_settings );
	guard_check(
		0 === $cache_compat_reads && 0 === $GLOBALS['faz_header_removals'],
		'cache compatibility + block_server_cookies leaves a third-party Set-Cookie untouched'
	);

	// And the gate itself, named directly, so the reason is pinned and not just
	// the symptom.
	function guard_enabled_gate( $overrides = array() ) {
		$frontend = guard_frontend();
		$values   = array_merge(
			array(
				'settings_option_cache' => array(
					'script_blocking' => array( 'block_server_cookies' => true ),
				),
				'template'              => '<div data-faz-tag="notice"></div>',
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
		$method = new \ReflectionMethod( Frontend::class, 'server_cookie_guard_enabled' );
		$method->setAccessible( true );
		return $method->invoke( $frontend );
	}

	guard_check( true === guard_enabled_gate(), 'the guard is enabled on an opted-in site with a banner' );
	guard_check( false === guard_enabled_gate( $cache_compat_settings ), 'server_cookie_guard_enabled() returns false under Cache Compatibility Mode' );

	$GLOBALS['faz_headers_list_payload'] = array( 'Content-Type: text/html' );

	/* ───────────────────────────────────────────────────────────────────────
	 * What the blocked-cookie DIAGNOSTIC is allowed to retain.
	 *
	 * record_blocked_server_cookies() is written by anonymous front-end
	 * requests, kept for 24 hours, and rendered in a manage_options table. It
	 * used to store the full REQUEST_URI: sanitize_text_field() strips tags but
	 * keeps the query string, and WordPress query strings routinely carry
	 * ?email=, the WooCommerce order_key, the password-reset key+login pair,
	 * _wpnonce and search terms. Nothing erases it — the plugin's GDPR eraser
	 * is email-keyed and covers consent logs only, and the default uninstall
	 * path is gated on remove_data_on_uninstall (default false).
	 * ─────────────────────────────────────────────────────────────────────── */

	function guard_record( $request_uri, $blocked ) {
		$_SERVER['REQUEST_URI']          = $request_uri;
		$GLOBALS['faz_transient_writes'] = array();
		$GLOBALS['faz_transient_reads']  = array();
		$method = new \ReflectionMethod( Frontend::class, 'record_blocked_server_cookies' );
		$method->setAccessible( true );
		$method->invoke( guard_frontend(), $blocked );
		$written = $GLOBALS['faz_transient_writes']['faz_recent_blocked_server_cookies'];
		return is_array( $written ) ? $written : array();
	}

	$one = array( array( 'name' => 'brikpanel_vid', 'category' => 'analytics' ) );

	// A password-reset link and a WooCommerce order-received URL, which are the
	// two shapes that actually walk into this code on a live site.
	$reset = guard_record(
		'/wp-login.php?action=rp&key=8f3a9c2b1d4e&login=fabio%40example.com&_wpnonce=abc123',
		$one
	);
	guard_check(
		1 === count( $reset ) && '/wp-login.php' === $reset[0]['request'],
		'the blocked-cookie diagnostic stores the request PATH, not the full URI'
	);
	// Named individually so a partial strip cannot pass, and so the failure
	// message says which secret survived.
	$reset_blob = serialize( $reset );
	foreach ( array( 'key=', '8f3a9c2b1d4e', 'login=', 'example.com', '_wpnonce' ) as $secret ) {
		guard_check(
			false === strpos( $reset_blob, $secret ),
			'the query-string token "' . $secret . '" is never written to the diagnostic transient'
		);
	}

	$order = guard_record(
		'/checkout/order-received/1842/?key=wc_order_kR9xQ&email=buyer%40example.com',
		$one
	);
	guard_check(
		'/checkout/order-received/1842/' === $order[0]['request'],
		'a WooCommerce order-received path is retained in full while its order_key and email are not'
	);
	guard_check(
		false === strpos( serialize( $order ), 'wc_order_kR9xQ' ) && false === strpos( serialize( $order ), 'buyer' ),
		'the WooCommerce order_key and customer email never reach the transient'
	);

	// The path is genuinely still there — otherwise every assertion above would
	// pass on an empty string and the admin would have lost the diagnostic.
	$plain = guard_record( '/shop/product/blue-widget/', $one );
	guard_check(
		'/shop/product/blue-widget/' === $plain[0]['request'],
		'a query-free path is stored verbatim, so the diagnostic still answers "which page"'
	);

	// Length. An anonymous visitor picks the URL, so an unbounded value is an
	// unbounded wp_options row rewrite on every request.
	$long = guard_record( '/' . str_repeat( 'a', 8192 ), $one );
	guard_check(
		255 === strlen( $long[0]['request'] ),
		'an 8 KB request path is capped at 255 characters before storage'
	);

	// Retention depth must match what the reader renders: admin/views/
	// system-status.php reverses the list and takes 20.
	$many = array();
	for ( $i = 0; $i < 25; $i++ ) {
		$many[] = array( 'name' => 'cookie_' . $i, 'category' => 'analytics' );
	}
	$capped = guard_record( '/', $many );
	guard_check(
		20 === count( $capped ),
		'the transient retains 20 entries — exactly the number System Status renders'
	);
	guard_check(
		'cookie_24' === $capped[19]['name'] && 'cookie_5' === $capped[0]['name'],
		'the retained window is the most recent 20, not the oldest'
	);

	$status_view = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/views/system-status.php' );
	guard_check(
		false !== strpos( $status_view, "'Request Path'" ) && false === strpos( $status_view, "'Request URI'" ),
		'the System Status column is labelled Request Path, matching what is stored'
	);
	guard_check(
		(bool) preg_match( '/array_slice\(\s*\$blocked_server_cookies,\s*0,\s*20\s*\)/', $status_view ),
		'System Status still renders 20 rows, so the storage cap above is pinned to a real reader'
	);

	echo $run . ' checks, ' . $failed . " failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
