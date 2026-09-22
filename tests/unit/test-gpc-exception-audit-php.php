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

		/**
		 * Keyed the way the real class keys: scheme stripped on both sides,
		 * so a Referer and a render that disagree on the scheme still meet —
		 * and so this double cannot stay green on a shape production dropped.
		 */
		public static function page_category( $url, $service_id ) {
			$strip = static function ( $u ) {
				return (string) preg_replace( '#^[a-z][a-z0-9+.\-]*://#i', '', (string) $u );
			};
			foreach ( self::$map as $key => $category ) {
				if ( $strip( $key ) === $strip( $url ) . '|' . $service_id ) {
					return $category;
				}
			}
			return '';
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

		/** @var int Category lookups issued, for the per-request memo case. */
		public static $row_reads = 0;

		public function get_row( $q ) {
			++self::$row_reads;
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
		FazTest_AuditWPDB::$row_reads = 0;
		// The category memo lives for one request; each case is one request.
		$rc = new \ReflectionClass( Gpc_Exception_Audit::class );
		if ( $rc->hasProperty( 'sale_share_memo' ) ) {
			$prop = $rc->getProperty( 'sale_share_memo' );
			$prop->setAccessible( true );
			$prop->setValue( null, array() );
		}
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
			// The carry gate (I-1) requires the CURRENT cookie to still hold
			// the pairs — an ordinary re-assertion the runtime wrote. Without
			// them, a client re-assertion is judged as a fresh claim instead.
			audit_consent( $id ),
			$previous
		);
		// A verdict in the previous row carries that verdict; a bare client
		// key (a row written before verdicts existed) carries '' — the claim
		// stays on record but the server has never judged it (M-1).
		$expected = 'meta.gpc_exception.' === $prefix ? '' : 'yes';
		audit_check(
			$expected === ( $result[ $carried_key . $id ] ?? null ),
			"5. carried='{$expected}' when the previous row carries the service under prefix '{$prefix}' and the cookie still holds the pairs"
		);
		audit_check(
			! isset( $result[ $served_key . $id ] ),
			"5. no served key is added when the verdict is carried (prefix '{$prefix}')"
		);
	}

	// ============================================================
	// 5b. (I-1) a forged re-assertion after withdrawal must not come back
	//     as carried=yes. The previous row recorded a served exception; the
	//     visitor then withdrew (cookie pairs gone); a page script re-asserts
	//     meta.gpc_exception.<id> with the pairs absent. Before the fix the
	//     carried short-circuit answered yes from the previous row alone, so
	//     the forged claim inherited the genuine verdict.
	// ============================================================
	audit_reset();
	$id       = 'svc-withdraw';
	$previous = array( 'categories' => array( $served_key . $id => 'yes' ) );
	$result   = Gpc_Exception_Audit::decide(
		array( 'meta.gpc_exception.' . $id => 'yes' ),
		array(
			'signal_gpc' => 1,
			'url'        => 'https://example.test/c',
		),
		'', // withdrawal: no consent cookie at all
		$previous
	);
	audit_check(
		'yes' !== ( $result[ $carried_key . $id ] ?? null ),
		'5b. a forged re-assertion after withdrawal does not inherit carried=yes from the previous row'
	);
	audit_check(
		'no' === ( $result[ $served_key . $id ] ?? null ),
		'5b. the forged re-assertion is judged as a fresh claim and reads served=no'
	);
	audit_check(
		! isset( $result[ $carried_key . $id ] ),
		'5b. no carried key is written for the forged re-assertion'
	);

	// 5c. The same re-assertion with only ONE pair missing is equally refused:
	//     the grant without the gpcx marker describes no state the runtime
	//     was ever in, so it may not ride the carry chain either.
	audit_reset();
	$id       = 'svc-halfwithdraw';
	$previous = array( 'categories' => array( $served_key . $id => 'yes' ) );
	$result   = Gpc_Exception_Audit::decide(
		array( 'meta.gpc_exception.' . $id => 'yes' ),
		array(
			'signal_gpc' => 1,
			'url'        => 'https://example.test/c',
		),
		audit_consent( $id, 'yes', null ), // svc grant present, gpcx marker gone
		$previous
	);
	audit_check(
		'yes' !== ( $result[ $carried_key . $id ] ?? null ) && 'no' === ( $result[ $served_key . $id ] ?? null ),
		'5c. a re-assertion whose cookie lost only the gpcx marker is judged fresh, not carried'
	);

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


	// ============================================================
	// 9. A Referer reduced to its origin falls back to the payload url.
	// ============================================================
	// `Referrer-Policy: origin` / `strict-origin` (and some privacy
	// extensions) send only the origin, so on every page but the home page the
	// Referer named a page that never offered the embed and a genuine click
	// read as unverified. The fallback is narrow: only a bare origin, only when
	// the origin itself has no row, only to a deeper path on the same host.
	$id        = 'svc-e';
	$deep      = 'https://example.test/contact';
	$fallback  = function ( $referer, $payload_url, array $map ) use ( $id, $served_key ) {
		audit_reset();
		foreach ( $map as $page ) {
			Embed_Inventory::$map[ $page . '|' . $id ] = 'marketing';
		}
		FazTest_AuditWPDB::$categories['marketing'] = array( 'sell' => 1, 'share' => 0 );
		$_SERVER['HTTP_REFERER'] = $referer;
		$result = Gpc_Exception_Audit::decide(
			array( 'meta.gpc_exception.' . $id => 'yes' ),
			array( 'signal_gpc' => 1, 'url' => $payload_url ),
			audit_consent( $id ),
			array()
		);
		return isset( $result[ $served_key . $id ] ) ? $result[ $served_key . $id ] : null;
	};
	audit_check( 'yes' === $fallback( 'https://example.test/', $deep, array( $deep ) ), '9a. a bare-origin Referer with no row falls back to the payload page -> yes' );
	audit_check( 'yes' === $fallback( 'https://example.test', $deep, array( $deep ) ), '9b. the same without the trailing slash -> yes' );
	audit_check( 'no' === $fallback( 'https://example.test/', 'https://elsewhere.test/contact', array( 'https://elsewhere.test/contact' ) ), '9c. the fallback never crosses to another host -> no' );
	audit_check( 'no' === $fallback( 'https://example.test/', 'https://example.test/', array() ), '9d. a payload that is itself the bare origin adds nothing -> no' );
	audit_check( 'yes' === $fallback( 'https://example.test/', 'https://example.test/unknown', array( 'https://example.test/' ) ), '9e. a bare origin that HAS a row is judged on its own row -> yes' );
	audit_check( 'no' === $fallback( 'https://example.test/other', $deep, array( $deep ) ), '9f. a full-path Referer never falls back (7b contract) -> no' );

	// ============================================================
	// 10. The number of exceptions judged per row is bounded.
	// ============================================================
	audit_check( defined( Gpc_Exception_Audit::class . '::MAX_IDS' ) && 50 === Gpc_Exception_Audit::MAX_IDS, '10. MAX_IDS is 50' );
	audit_reset();
	$many = array();
	for ( $i = 0; $i < 60; $i++ ) {
		$many[ 'meta.gpc_exception.svc' . $i ] = 'yes';
	}
	$result   = Gpc_Exception_Audit::decide( $many, array( 'url' => 'https://example.test/x' ), '', array() );
	$verdicts = array_filter( array_keys( $result ), function ( $k ) use ( $served_key, $carried_key ) {
		return 0 === strpos( $k, $served_key ) || 0 === strpos( $k, $carried_key );
	} );
	audit_check( defined( Gpc_Exception_Audit::class . '::MAX_IDS' ) && Gpc_Exception_Audit::MAX_IDS === count( $verdicts ), '10. only the first MAX_IDS exceptions receive a verdict key' );
	audit_check( ! isset( $result[ $served_key . 'svc55' ] ), '10. an exception beyond the bound gets no verdict at all' );

	// ============================================================
	// 11. A carried verdict remembers what the first one said.
	// ============================================================
	// "carried" used to render the same whatever the first row concluded, so an
	// exception the server had judged unverified turned into an innocuous
	// "carried" one page later — laundering the verdict it was carrying.
	// The carry gate (I-1) requires the current cookie to still hold the
	// pairs; without them every case below would fall through to served().
	$carry = function ( array $previous_categories ) use ( $carried_key ) {
		audit_reset();
		$result = Gpc_Exception_Audit::decide(
			array( 'meta.gpc_exception.svc-f' => 'yes' ),
			array( 'signal_gpc' => 1, 'url' => 'https://example.test/f' ),
			audit_consent( 'svc-f' ),
			array( 'categories' => $previous_categories )
		);
		return isset( $result[ $carried_key . 'svc-f' ] ) ? $result[ $carried_key . 'svc-f' ] : null;
	};
	audit_check( 'no' === $carry( array( $served_key . 'svc-f' => 'no' ) ), '11a. carried from an unverified serve -> carried=no' );
	audit_check( 'no' === $carry( array( $carried_key . 'svc-f' => 'no' ) ), '11b. carried from an unverified carry -> carried=no' );
	audit_check( 'yes' === $carry( array( $served_key . 'svc-f' => 'yes' ) ), '11c. carried from a verified serve -> carried=yes' );
	audit_check( 'yes' === $carry( array( $served_key . 'svc-f' => 'yes', $carried_key . 'svc-f' => 'no' ) ), '11d. an explicit served value wins over a carried one' );
	audit_check( '' === $carry( array( 'meta.gpc_exception.svc-f' => 'yes' ) ), '11e. a legacy row with only the client key carries forward unjudged (empty verdict)' );
	audit_check( 'yes' === $carry( array( 'meta.gpc_exception.svc-f' => 'yes', $carried_key . 'svc-f' => 'yes' ) ), '11f. a verdict wins over the bare client key' );
	audit_check( '' === $carry( array( $carried_key . 'svc-f' => '' ) ), '11g. an unjudged carry stays unjudged on the next page, not yes' );

	// ============================================================
	// 12. The category flags are read once per request.
	// ============================================================
	audit_reset();
	FazTest_AuditWPDB::$categories['marketing'] = array( 'sell' => 1, 'share' => 0 );
	Embed_Inventory::$map['https://example.test/g|one'] = 'marketing';
	Embed_Inventory::$map['https://example.test/g|two'] = 'marketing';
	Gpc_Exception_Audit::decide(
		array( 'meta.gpc_exception.one' => 'yes', 'meta.gpc_exception.two' => 'yes' ),
		array( 'signal_gpc' => 1, 'url' => 'https://example.test/g' ),
		audit_consent( 'one' ) . ',' . audit_consent( 'two' ),
		array()
	);
	audit_check( 1 === FazTest_AuditWPDB::$row_reads, '12. two exceptions in one category cost one category lookup' );

	// ============================================================
	// 13. Page identity across schemes and routing parameters.
	// ============================================================
	// Behind a TLS-terminating proxy the render and the consent post can see
	// different schemes; the page is still the same page.
	audit_check( 'yes' === $fallback( 'http://example.test/contact', 'http://example.test/contact', array( $deep ) ), '13a. an http:// Referer finds the row an https:// render wrote -> yes' );

	// A plain-permalink page with no Referer at all: the payload now carries the
	// routing query, and it must match the row the render keyed on it.
	audit_reset();
	Embed_Inventory::$map['https://example.test/?p=123|' . $id] = 'marketing';
	FazTest_AuditWPDB::$categories['marketing'] = array( 'sell' => 1, 'share' => 0 );
	$result = Gpc_Exception_Audit::decide(
		array( 'meta.gpc_exception.' . $id => 'yes' ),
		array( 'signal_gpc' => 1, 'url' => 'https://example.test/?p=123&utm_source=x' ),
		audit_consent( $id ),
		array()
	);
	audit_check( 'yes' === ( $result[ $served_key . $id ] ?? null ), '13b. a ?p=123 payload with no Referer matches the ?p=123 row -> yes' );
	audit_reset();
	Embed_Inventory::$map['https://example.test/?p=123|' . $id] = 'marketing';
	FazTest_AuditWPDB::$categories['marketing'] = array( 'sell' => 1, 'share' => 0 );
	$result = Gpc_Exception_Audit::decide(
		array( 'meta.gpc_exception.' . $id => 'yes' ),
		array( 'signal_gpc' => 1, 'url' => 'https://example.test/' ),
		audit_consent( $id ),
		array()
	);
	audit_check( 'no' === ( $result[ $served_key . $id ] ?? null ), '13c. the bare home page does not borrow a ?p=123 row -> no' );

	echo "\nPassed: {$passed}; Failed: {$failed}\n";
	exit( $failed > 0 ? 1 : 0 );
}
