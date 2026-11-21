<?php
/**
 * DSAR labels never echo a raw stored key, and the request details table is
 * escaped at output time.
 *
 *   - DSAR_Shortcode::get_type_label() fed the raw `_dsar_type` meta back as
 *     the label for an unknown key. That label reaches the admin notification
 *     mail and the erased record's title, where no HTML escaping happens. It
 *     now returns "unknown (<sanitize_key>)", reusing the existing msgid.
 *   - DSAR_Admin::render_details_box() echoed every pre-built cell raw and
 *     relied on each row escaping itself. The cells now pass through wp_kses()
 *     with an allowlist of a[href], code and em, so a row added later without
 *     its own escaping cannot reach the screen as markup.
 *
 * Run: php tests/unit/test-dsar-labels-and-details-php.php
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['meta']  = array();
	$GLOBALS['kses']  = array();
	function __( $t, $d = null ) { return $t; }
	function esc_html__( $t, $d = null ) { return htmlspecialchars( $t, ENT_QUOTES ); }
	function esc_html_e( $t, $d = null ) { echo htmlspecialchars( $t, ENT_QUOTES ); }
	function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
	function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); }
	function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
	function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
	function add_query_arg( $a, $u ) { return $u . '?' . http_build_query( $a ); }
	function get_post_time( $f, $g, $p ) { return '2026-11-01 10:00'; }
	function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['meta'][ $k ] ?? ''; }
	function wp_kses( $html, $allowed ) {
		$GLOBALS['kses'][] = array( $html, $allowed );
		return strip_tags( $html, '<' . implode( '><', array_keys( $allowed ) ) . '>' );
	}

	$inc = dirname( __DIR__, 2 ) . '/includes';
	require $inc . '/class-ip-hasher.php';
	require $inc . '/class-dsar-admin.php';
	require $inc . '/class-dsar-shortcode.php';
	use FazCookie\Includes\DSAR_Admin;
	use FazCookie\Includes\DSAR_Shortcode;

	$checks = 0; $failed = 0;
	function dl_check( $ok, $label ) { global $checks, $failed; ++$checks; if ( $ok ) { echo "  PASS {$label}\n"; return; } ++$failed; echo "  FAIL {$label}\n"; }

	// ── Type labels ───────────────────────────────────────────────────────
	dl_check( 'Right to Erasure (Art. 17 GDPR)' === DSAR_Shortcode::get_type_label( 'erasure' ), 'a known type keeps its label' );
	$evil = "<script>x</script>\r\nBcc: a@b.test";
	$lbl  = DSAR_Shortcode::get_type_label( $evil );
	dl_check( 0 === strpos( $lbl, 'unknown' ), 'an unknown type is labelled Unknown (' . $lbl . ')' );
	dl_check( false === strpos( $lbl, '<' ) && false === strpos( $lbl, "\n" ) && false === strpos( $lbl, ':' ), 'and carries no markup, line break or header text from the stored value' );
	dl_check( 'unknown (legacy_type)' === DSAR_Shortcode::get_type_label( 'legacy_type' ), 'a plain unknown key stays readable inside the label' );
	dl_check( 'unknown' === DSAR_Shortcode::get_type_label( '' ), 'an empty key is just Unknown' );

	// ── Details table escaped at output ──────────────────────────────────
	$GLOBALS['meta'] = array(
		'_dsar_name'       => 'Ada <b>Lovelace</b>',
		'_dsar_email'      => 'ada@example.test',
		'_dsar_type'       => 'access',
		'_dsar_message'    => '',
		'_dsar_status'     => 'pending',
		'_dsar_consent_id' => 'abc123',
	);
	$post = (object) array( 'ID' => 7 );
	ob_start();
	( new ReflectionClass( DSAR_Admin::class ) )->newInstanceWithoutConstructor()->render_details_box( $post );
	$html = ob_get_clean();

	$cells = $GLOBALS['kses'];
	dl_check( 6 === count( $cells ), 'every details cell goes through wp_kses() (' . count( $cells ) . ' of 6)' );
	$allow_ok = ! empty( $cells );
	foreach ( $cells as $c ) {
		if ( array( 'a', 'code', 'em' ) !== array_keys( $c[1] ) || array( 'href' ) !== array_keys( $c[1]['a'] ) || array() !== $c[1]['code'] || array() !== $c[1]['em'] ) {
			$allow_ok = false;
		}
	}
	dl_check( $allow_ok, 'with an allowlist of a[href], code and em only' );
	dl_check( false !== strpos( $html, '<a href="mailto:ada@example.test">ada@example.test</a>' ), 'the mailto link still renders' );
	dl_check( false !== strpos( $html, '<code>abc123</code>' ), 'the consent id still renders as <code>' );
	dl_check( false !== strpos( $html, 'Ada &lt;b&gt;Lovelace&lt;/b&gt;' ) && false === strpos( $html, '<b>' ), 'the name is still escaped text' );

	echo "\n{$checks} checks, {$failed} failed\n";
	echo 0 === $failed ? "ALL PASS\n" : "FAILURES\n";
	exit( 0 === $failed ? 0 : 1 );
}
