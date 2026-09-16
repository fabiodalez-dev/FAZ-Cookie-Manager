<?php
/**
 * Standalone unit tests for the GPC-exception server-side audit verdict
 * (issue #285 — a consent cookie a script can forge is not proof of a click).
 *
 * Subsystem: gpc-exception-audit (accountability record, not authenticity proof)
 *
 * Drives the REAL shipped class
 *   includes/class-gpc-exception-audit.php
 * whose only public entry point, Gpc_Exception_Audit::decide(), adds
 * server-written verdict keys (meta.gpc_exception_served.<id> /
 * meta.gpc_exception_carried.<id>) to a sanitised consent-log decision map.
 * Nothing about the verdict is trusted from the client: the class strips any
 * client-sent verdict key before computing its own, and recomputes a verdict
 * for every meta.gpc_exception.<id> key it finds.
 *
 * "served" requires ALL of: a GPC signal on the request, no standing DNSMPI
 * opt-out, the consent cookie itself carrying both the svc.<id>:yes grant and
 * the gpcx.<id>:1 marker, the page (Referer, falling back to the payload url)
 * being on record via Embed_Inventory as having offered that embed, and the
 * offered category actually being flagged sell/share. "carried" instead means
 * the previous log row for this consent id already recorded the service under
 * any of the three meta.gpc_exception* prefixes — a later page view re-asserts
 * it, so it is not judged as a fresh serve.
 *
 * No browser, no real DB, no live WP. Embed_Inventory (a collaborator class
 * this file would otherwise autoload the real, DB-backed implementation of)
 * is replaced with a controllable test double defined BEFORE
 * class-gpc-exception-audit.php is loaded, exactly as
 * test-per-service-php.php replaces Known_Providers. $wpdb is a minimal
 * double whose prepare() substitutes %s literally into the query text and
 * whose get_row() parses that text back out, exactly as
 * test-third-country-transfer-php.php and test-age-affirmation-log-php.php do
 * it. The real includes/class-utils.php is required for faz_normalize_page_url()
 * so URL normalisation in the test matches production byte-for-byte.
 *
 * Run:  php tests/unit/test-gpc-exception-audit-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Frontend\Includes {

	/**
	 * Test double for the real, DB-backed Embed_Inventory. The real class's
	 * page_category() itself normalises its $url argument and looks up a row
	 * keyed by (url_hash, service_id); this double skips the DB and answers
	 * straight from a fixture map the test controls, keyed the same way:
	 * "<already-normalised-url>|<service_id>" => "<category-slug>".
	 */
	class Embed_Inventory {
		/** @var array<string,string> */
		public static $map = array();

		public static function page_category( $url, $service_id ) {
			$key = $url . '|' . $service_id;
			return isset( self::$map[ $key ] ) ? self::$map[ $key ] : '';
		}
	}
}

namespace {

	// ---------- Bootstrap ----------

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	// ---------- WP function stubs (only what the class under test, and the
	// real faz_normalize_page_url() it calls, touch) ----------

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $s ) {
			$s = (string) $s;
			$s = preg_replace( '/<[^>]*>/', '', $s );
			$s = preg_replace( '/[\r\n\t]+/', ' ', $s );
			$s = preg_replace( '/[\x00-\x1F\x7F]/', '', $s );
			return trim( preg_replace( '/\s{2,}/', ' ', $s ) );
		}
	}
	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
	}
	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( $u ) { return trim( (string) $u ); }
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $u ) { return parse_url( (string) $u ); }
	}
	if ( ! function_exists( 'absint' ) ) {
		function absint( $n ) { return abs( (int) $n ); }
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $v ) { return json_encode( $v ); }
	}

	// ---------- $wpdb double: prepare() substitutes %s literally, get_row()
	// parses the slug back out of the resulting text and answers from a
	// fixture map the test controls. Same shape as
	// test-third-country-transfer-php.php / test-age-affirmation-log-php.php. ----------

	class FazTest_AuditWPDB {
		public $prefix = 'wp_';

		/** @var array<string,array{sell:int,share:int}> slug => flags */
		public static $categories = array();

		public function prepare( $q, ...$a ) {
			if ( 1 === count( $a ) && is_array( $a[0] ) ) {
				$a = $a[0];
			}
			foreach ( $a as $v ) {
				$q = preg_replace( '/%s/', "'" . addslashes( (string) $v ) . "'", $q, 1 );
			}
			return $q;
		}

		public function get_row( $q ) {
			if ( ! preg_match( "/slug = '([^']*)'/", $q, $m ) ) {
				return null;
			}
			$slug = $m[1];
			if ( ! isset( self::$categories[ $slug ] ) ) {
				return null;
			}
			$flags = self::$categories[ $slug ];
			return (object) array(
				'sell_personal_data'  => $flags['sell'],
				'share_personal_data' => $flags['share'],
			);
		}
	}
	$GLOBALS['wpdb'] = new FazTest_AuditWPDB();

	// Real URL normaliser: production and this test must agree on it, or the
	// map keys the test builds would silently never match what the class
	// under test looks up.
	require_once dirname( __DIR__, 2 ) . '/includes/class-utils.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-gpc-exception-audit.php';

	use FazCookie\Includes\Gpc_Exception_Audit;
	use FazCookie\Frontend\Includes\Embed_Inventory;

	// ---------- Minimal assert helper (same shape as
	// test-gpc-exception-throttle-php.php's gpcx_check()) ----------

	$passed = 0;
	$failed = 0;
	function audit_check( $actual, $label ) {
		global $passed, $failed;
		if ( $actual ) {
			$passed++;
			echo "  [PASS] {$label}\n";
		} else {
			$failed++;
			echo "  [FAIL] {$label}\n";
		}
	}

	/** Reset all controllable fixture state between cases. */
	function audit_reset() {
		Embed_Inventory::$map          = array();
		FazTest_AuditWPDB::$categories = array();
		unset( $_COOKIE['fazcookie-dnsmpi'] );
		unset( $_SERVER['HTTP_REFERER'] );
	}

	/** Build a "svc.<id>:<svc>,gpcx.<id>:<gpcx>" consent cookie fragment. */
	function audit_consent( $id, $svc = 'yes', $gpcx = '1' ) {
		$parts = array();
		if ( null !== $svc ) {
			$parts[] = 'svc.' . $id . ':' . $svc;
		}
		if ( null !== $gpcx ) {
			$parts[] = 'gpcx.' . $id . ':' . $gpcx;
		}
		return implode( ',', $parts );
	}

	$served_key  = 'meta.gpc_exception_served.';
	$carried_key = 'meta.gpc_exception_carried.';

	echo "\n== gpc-exception-audit-php — server-side GPC exception verdict ==\n\n";

	// ============================================================
	// 1. served=yes when every circumstance holds.
	// ============================================================
	audit_reset();
	$id  = 'google-maps';
	$url = 'https://example.test/maps';
	Embed_Inventory::$map[ $url . '|' . $id ]  = 'marketing';
	FazTest_AuditWPDB::$categories['marketing'] = array(
		'sell'  => 1,
		'share' => 0,
	);
	$result = Gpc_Exception_Audit::decide(
		array( 'meta.gpc_exception.' . $id => 'yes' ),
		array(
			'signal_gpc' => 1,
			'url'        => $url,
		),
		audit_consent( $id ),
		array()
	);
	audit_check(
		isset( $result[ $served_key . $id ] ) && 'yes' === $result[ $served_key . $id ],
		'1. served=yes when signal, DNSMPI absent, cookie pair, and sale/share category all hold'
	);
	audit_check(
		! isset( $result[ $carried_key . $id ] ),
		'1. a fresh served verdict carries no carried key alongside it'
	);

	// ============================================================
	// 2. served=no, one broken circumstance at a time (five cases).
	// ============================================================

	// 2a. signal_gpc absent.
	audit_reset();
	$id  = 'svc-a';
	$url = 'https://example.test/a';
	Embed_Inventory::$map[ $url . '|' . $id ]  = 'marketing';
	FazTest_AuditWPDB::$categories['marketing'] = array(
		'sell'  => 1,
		'share' => 0,
	);
	$result = Gpc_Exception_Audit::decide(
		array( 'meta.gpc_exception.' . $id => 'yes' ),
		array( 'url' => $url ), // no signal_gpc key at all
		audit_consent( $id ),
		array()
	);
	audit_check(
		'no' === ( $result[ $served_key . $id ] ?? null ),
		'2a. served=no when signal_gpc is absent from the payload'
	);

	// 2b. a standing DNSMPI opt-out is in force.
	audit_reset();
	$id  = 'svc-a';
	$url = 'https://example.test/a';
	Embed_Inventory::$map[ $url . '|' . $id ]  = 'marketing';
	FazTest_AuditWPDB::$categories['marketing'] = array(
		'sell'  => 1,
		'share' => 0,
	);
	$_COOKIE['fazcookie-dnsmpi'] = '1';
	$result                     = Gpc_Exception_Audit::decide(
		array( 'meta.gpc_exception.' . $id => 'yes' ),
		array(
			'signal_gpc' => 1,
			'url'        => $url,
		),
		audit_consent( $id ),
		array()
	);
	audit_check(
		'no' === ( $result[ $served_key . $id ] ?? null ),
		'2b. served=no when a standing Do-Not-Sell cookie is in force'
	);

	// 2c. consent cookie lacks svc.<id>:yes.
	audit_reset();
	$id  = 'svc-a';
	$url = 'https://example.test/a';
	Embed_Inventory::$map[ $url . '|' . $id ]  = 'marketing';
	FazTest_AuditWPDB::$categories['marketing'] = array(
		'sell'  => 1,
		'share' => 0,
	);
	$result = Gpc_Exception_Audit::decide(
		array( 'meta.gpc_exception.' . $id => 'yes' ),
		array(
			'signal_gpc' => 1,
			'url'        => $url,
		),
		audit_consent( $id, null, '1' ), // "gpcx.svc-a:1" only, no svc.* grant
		array()
	);
	audit_check(
		'no' === ( $result[ $served_key . $id ] ?? null ),
		'2c. served=no when the consent cookie lacks the svc.<id>:yes grant'
	);

	// 2d. consent cookie lacks gpcx.<id>:1.
	audit_reset();
	$id  = 'svc-a';
	$url = 'https://example.test/a';
	Embed_Inventory::$map[ $url . '|' . $id ]  = 'marketing';
	FazTest_AuditWPDB::$categories['marketing'] = array(
		'sell'  => 1,
		'share' => 0,
	);
	$result = Gpc_Exception_Audit::decide(
		array( 'meta.gpc_exception.' . $id => 'yes' ),
		array(
			'signal_gpc' => 1,
			'url'        => $url,
		),
		audit_consent( $id, 'yes', null ), // "svc.svc-a:yes" only, no gpcx marker
		array()
	);
	audit_check(
		'no' === ( $result[ $served_key . $id ] ?? null ),
		'2d. served=no when the consent cookie lacks the gpcx.<id>:1 marker'
	);

	// 2e. the page was never offered the embed (inventory empty).
	audit_reset();
	$id  = 'svc-a';
	$url = 'https://example.test/a';
	// Embed_Inventory::$map deliberately left empty by audit_reset().
	$result = Gpc_Exception_Audit::decide(
		array( 'meta.gpc_exception.' . $id => 'yes' ),
		array(
			'signal_gpc' => 1,
			'url'        => $url,
		),
		audit_consent( $id ),
		array()
	);
	audit_check(
		'no' === ( $result[ $served_key . $id ] ?? null ),
		'2e. served=no when the inventory has no record the page ever offered the embed'
	);

	// ============================================================
	// 3. served=no when the category is offered but is neither sale nor share.
	// ============================================================
	audit_reset();
	$id  = 'svc-a';
	$url = 'https://example.test/a';
	Embed_Inventory::$map[ $url . '|' . $id ]  = 'necessary';
	FazTest_AuditWPDB::$categories['necessary'] = array(
		'sell'  => 0,
		'share' => 0,
	);
	$result = Gpc_Exception_Audit::decide(
		array( 'meta.gpc_exception.' . $id => 'yes' ),
		array(
			'signal_gpc' => 1,
			'url'        => $url,
		),
		audit_consent( $id ),
		array()
	);
	audit_check(
		'no' === ( $result[ $served_key . $id ] ?? null ),
		'3. served=no when the offered category is flagged neither sell nor share'
	);

	// ============================================================
	// 4. client-sent verdict keys are stripped and recomputed, not trusted.
	// ============================================================
	audit_reset();
	$id  = 'svc-b';
	$url = 'https://example.test/b';
	Embed_Inventory::$map[ $url . '|' . $id ]  = 'marketing';
	FazTest_AuditWPDB::$categories['marketing'] = array(
		'sell'  => 1,
		'share' => 0,
	);
	$result = Gpc_Exception_Audit::decide(
		array(
			'meta.gpc_exception.' . $id          => 'yes',
			$served_key . $id                    => 'yes', // forged
			$carried_key . $id                   => 'yes', // forged
		),
		array( 'url' => $url ), // circumstances do NOT hold: no signal_gpc
		audit_consent( $id ),
		array()
	);
	audit_check(
		'no' === ( $result[ $served_key . $id ] ?? null ),
		'4. a forged served=yes from the client is recomputed to no when circumstances do not hold'
	);
	audit_check(
		! isset( $result[ $carried_key . $id ] ),
		'4. a forged carried=yes from the client is dropped, not carried forward, when the previous row is empty'
	);

	// ============================================================
	// 5. carried=yes (no served key) when the previous row already carries the
	//    service under any of the three recognised prefixes.
	// ============================================================
	foreach ( array( 'meta.gpc_exception.', $served_key, $carried_key ) as $prefix ) {
		audit_reset();
		$id       = 'svc-c';
		$previous = array( 'categories' => array( $prefix . $id => 'yes' ) );
		$result   = Gpc_Exception_Audit::decide(
			array( 'meta.gpc_exception.' . $id => 'yes' ),
			array(
				'signal_gpc' => 1,
				'url'        => 'https://example.test/c',
			),
			'', // irrelevant: the carried short-circuit never inspects the cookie
			$previous
		);
		audit_check(
			'yes' === ( $result[ $carried_key . $id ] ?? null ),
			"5. carried=yes when the previous row already carries the service under prefix '{$prefix}'"
		);
		audit_check(
			! isset( $result[ $served_key . $id ] ),
			"5. no served key is added when the verdict is carried (prefix '{$prefix}')"
		);
	}

	// ============================================================
	// 6. a map with no meta.gpc_exception.* key comes back untouched.
	// ============================================================
	audit_reset();
	$clean  = array(
		'necessary' => 'yes',
		'analytics' => 'no',
	);
	$result = Gpc_Exception_Audit::decide( $clean, array(), '', array() );
	audit_check(
		$result === $clean,
		'6. a map with no meta.gpc_exception.* key is returned unchanged, no verdict keys added'
	);

	// ============================================================
	// 7. HTTP_REFERER is preferred over the payload's own url.
	// ============================================================
	$id           = 'svc-d';
	$known_url    = 'https://example.test/known';
	$unknown_url  = 'https://example.test/unknown';

	// 7a. Referer names the known page, payload names the unknown one -> yes.
	audit_reset();
	Embed_Inventory::$map[ $known_url . '|' . $id ] = 'marketing';
	FazTest_AuditWPDB::$categories['marketing']     = array(
		'sell'  => 1,
		'share' => 0,
	);
	$_SERVER['HTTP_REFERER'] = $known_url;
	$result                  = Gpc_Exception_Audit::decide(
		array( 'meta.gpc_exception.' . $id => 'yes' ),
		array(
			'signal_gpc' => 1,
			'url'        => $unknown_url,
		),
		audit_consent( $id ),
		array()
	);
	audit_check(
		'yes' === ( $result[ $served_key . $id ] ?? null ),
		'7a. a Referer naming a page the inventory knows wins over an unknown payload url -> yes'
	);

	// 7b. Swapped: Referer names the unknown page, payload names the known one -> no.
	audit_reset();
	Embed_Inventory::$map[ $known_url . '|' . $id ] = 'marketing';
	FazTest_AuditWPDB::$categories['marketing']     = array(
		'sell'  => 1,
		'share' => 0,
	);
	$_SERVER['HTTP_REFERER'] = $unknown_url;
	$result                  = Gpc_Exception_Audit::decide(
		array( 'meta.gpc_exception.' . $id => 'yes' ),
		array(
			'signal_gpc' => 1,
			'url'        => $known_url,
		),
		audit_consent( $id ),
		array()
	);
	audit_check(
		'no' === ( $result[ $served_key . $id ] ?? null ),
		'7b. swapped: an unknown Referer wins over a known payload url -> no'
	);

	// ============================================================
	// 8. a service id with regex metacharacters must not let an unrelated
	//    cookie token match through unescaped "." / "*".
	// ============================================================
	audit_reset();
	$id  = 'a.b*c';
	$url = 'https://example.test/regex';
	Embed_Inventory::$map[ $url . '|' . $id ]  = 'marketing';
	FazTest_AuditWPDB::$categories['marketing'] = array(
		'sell'  => 1,
		'share' => 0,
	);
	// If the id were interpolated into the regex unescaped, the pattern for
	// the svc grant would be /(?:^|,)svc\.a.b*c:yes(?=,|$)/ — "." matches any
	// character and "b*" allows zero b's, so "svc.aZc:yes" (no literal dot,
	// no "b" at all) would wrongly satisfy it. A correctly preg_quote()'d
	// pattern requires the literal substring "a.b*c" and must reject it.
	$result = Gpc_Exception_Audit::decide(
		array( 'meta.gpc_exception.' . $id => 'yes' ),
		array(
			'signal_gpc' => 1,
			'url'        => $url,
		),
		'svc.aZc:yes,gpcx.aZc:1',
		array()
	);
	audit_check(
		'no' === ( $result[ $served_key . $id ] ?? null ),
		'8. a service id with regex metacharacters ("a.b*c") does not let an unrelated cookie token match'
	);

	echo "\nPassed: {$passed}; Failed: {$failed}\n";
	exit( $failed > 0 ? 1 : 0 );
}
