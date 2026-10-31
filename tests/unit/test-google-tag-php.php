<?php
/**
 * Built-in Google tag (#366): ID validation and the markup Google_Tag renders.
 *
 * The IDs end up inside a script URL and a script body, so the guard is a
 * shape whitelist, not escaping; the markup must park every tag in the shape
 * the runtime restores unless the caller says the Advanced-mode exemption is
 * active, and the Tag Manager container must stay parked even then.
 */
define( 'ABSPATH', __DIR__ . '/' );
function wp_json_encode( $v ) { return json_encode( $v ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_url( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }

$root = dirname( __DIR__, 2 );
require_once $root . '/includes/class-known-providers.php';
require_once $root . '/includes/class-google-tag.php';
use FazCookie\Includes\Google_Tag;

$passed = 0;
function t( $cond, $label ) {
	global $passed;
	if ( $cond ) { $passed++; return; }
	fwrite( STDERR, "FAIL: $label\n" );
	exit( 1 );
}

// Accepted shapes, normalised to upper case, deduplicated, order kept.
t( 'G-ABCD1234' === Google_Tag::sanitize_ids( 'g-abcd1234' ), 'lower case is normalised' );
t( 'G-ABCD1234,AW-123456789,GTM-ABC123' === Google_Tag::sanitize_ids( " g-abcd1234 ;AW-123456789\nGTM-abc123, G-ABCD1234 " ), 'comma, semicolon and whitespace separate; duplicates dropped' );
t( 'GT-ABCD1234,DC-12345678' === Google_Tag::sanitize_ids( 'GT-ABCD1234 DC-12345678' ), 'GT- and DC- are accepted' );
t( array( 'G-AAAA1111' ) === Google_Tag::parse_ids( array( 'G-AAAA1111', array( 'x' ), null ) ), 'an array input keeps its scalar entries' );

// Rejected: anything that could carry markup or script, or is not an ID.
foreach ( array(
	'G-ABC',                          // too short
	'UA-12345-1',                     // Universal Analytics, retired
	'G-ABCD1234"><script>',           // markup
	"G-ABCD1234');alert(1);//",       // script break-out
	'GTM-ABC1234&l=evil',             // query injection
	'https://www.googletagmanager.com/gtag/js?id=G-ABCD1234',
	'G_ABCD1234',
	'GTM-ABCDEFGHIJKLM',              // too long for a container
	'',
) as $bad ) {
	t( '' === Google_Tag::sanitize_ids( $bad ), 'rejects ' . var_export( $bad, true ) );
}
t( '' === Google_Tag::sanitize_ids( null ), 'rejects null' );
t( '' === Google_Tag::sanitize_ids( new stdClass() ), 'rejects an object' );
t( 5 === count( Google_Tag::parse_ids( 'G-AAAA1111 G-AAAA2222 G-AAAA3333 G-AAAA4444 G-AAAA5555 G-AAAA6666' ) ), 'capped at five' );

// Providers and categories come from the catalogue.
t( 'google-analytics' === Google_Tag::provider_for( 'G-ABCD1234' ), 'G- is Google Analytics' );
t( 'google-ads' === Google_Tag::provider_for( 'AW-123456789' ), 'AW- is Google Ads' );
t( 'google-tag-manager' === Google_Tag::provider_for( 'GTM-ABC123' ), 'GTM- is Tag Manager' );
t( 'analytics' === Google_Tag::category_for( 'google-analytics', 'x' ), 'GA category read from the catalogue' );
t( 'marketing' === Google_Tag::category_for( 'google-ads', 'x' ), 'Ads category read from the catalogue' );
t( 'fallback' === Google_Tag::category_for( 'no-such-provider', 'fallback' ), 'unknown provider falls back' );

$ids = Google_Tag::parse_ids( 'G-ABCD1234, AW-123456789, GTM-ABC123' );

// Basic: every script parked, each group with its own loader and category.
$html = Google_Tag::render( $ids );
preg_match_all( '/<script\b([^>]*)>/', $html, $tags );
t( 5 === count( $tags[1] ), 'two scripts per gtag group plus one container' );
foreach ( $tags[1] as $attrs ) {
	t( false !== strpos( $attrs, 'type="text/plain"' ), 'parked: ' . $attrs );
	t( false !== strpos( $attrs, 'data-faz-category="' ), 'carries a category: ' . $attrs );
	t( false !== strpos( $attrs, 'data-faz-google-tag="' ), 'marked as the plugin\'s own: ' . $attrs );
}
t( 1 === preg_match( '/data-faz-category="analytics" data-faz-service="google-analytics" async src="https:\/\/www\.googletagmanager\.com\/gtag\/js\?id=G-ABCD1234"/', $html ), 'GA4 loader waits for Analytics' );
t( 1 === preg_match( '/data-faz-category="marketing" data-faz-service="google-ads" async src="https:\/\/www\.googletagmanager\.com\/gtag\/js\?id=AW-123456789"/', $html ), 'Ads loader waits for Marketing, separately' );
t( false !== strpos( $html, 'gtag("config","G-ABCD1234")' ) && false !== strpos( $html, 'gtag("config","AW-123456789")' ), 'one config per ID' );
t( 1 === preg_match( '/data-faz-service="google-tag-manager"[^>]*>\(function\(w,d,s,l,i\)/', $html ), 'container uses the standard loader' );
t( false === strpos( $html, '<noscript' ), 'no noscript iframe: without JS there is no consent' );

// Advanced: gtag groups live, the container still parked.
$adv = Google_Tag::render( $ids, array( 'gtag_unblocked' => true ) );
preg_match_all( '/<script\b([^>]*)>/', $adv, $tags );
$live = array_filter( $tags[1], function ( $a ) { return false === strpos( $a, 'text/plain' ); } );
t( 4 === count( $live ), 'advanced: the four gtag scripts are live' );
t( 1 === preg_match( '/<script type="text\/plain" data-faz-category="analytics" data-faz-service="google-tag-manager"/', $adv ), 'advanced: the container stays parked' );

// dataLayer name: a safe one is used everywhere, an unsafe one is ignored.
$dl = Google_Tag::render( $ids, array( 'data_layer' => 'myLayer' ) );
t( false !== strpos( $dl, 'window["myLayer"]' ) && false !== strpos( $dl, '&amp;l=myLayer' ) && false !== strpos( $dl, '"script","myLayer","GTM-ABC123"' ), 'custom dataLayer reaches gtag and the container' );
$bad = Google_Tag::render( $ids, array( 'data_layer' => 'x"];alert(1);//' ) );
t( false === strpos( $bad, 'alert' ) && false !== strpos( $bad, 'window["dataLayer"]' ), 'an unsafe dataLayer name falls back' );
// Every name Frontend::gcm_datalayer_name() can return must survive, or the
// Advanced-mode default and the tags push to different arrays.
$digit = Google_Tag::render( $ids, array( 'data_layer' => '1abc' ) );
t( false !== strpos( $digit, 'window["1abc"]' ) && false !== strpos( $digit, '"script","1abc","GTM-ABC123"' ), 'a name the GCM default accepts is kept' );

// Invalid IDs passed straight to render() are still dropped.
t( '' === Google_Tag::render( array( 'G-ABCD1234"><img>' ) ), 'render() re-validates its input' );
t( '' === Google_Tag::render( array() ), 'nothing to render' );

// Hints are appended verbatim to every tag.
$h = Google_Tag::render( $ids, array( 'hints' => ' data-no-optimize="1"' ) );
t( 5 === substr_count( $h, 'data-no-optimize="1"' ), 'optimiser hints on every tag' );

// own_tag_provider() (#371 review): every script render() prints is recognised
// with its own provider, and nothing else is. Read back through the DOM the way
// the output buffer and the browser see it (entities decoded).
$faz_own = function ( $markup ) {
	$out = array();
	$doc = new DOMDocument();
	@$doc->loadHTML( '<html><head>' . $markup . '</head></html>' );
	foreach ( $doc->getElementsByTagName( 'script' ) as $el ) {
		$out[] = Google_Tag::own_tag_provider( $el->getAttribute( 'data-faz-google-tag' ), $el->getAttribute( 'src' ), $el->textContent );
	}
	return $out;
};
$mixed = array( 'G-ABCD1234', 'AW-123456789', 'DC-12345678', 'GTM-ABC123' );
t( array( 'google-analytics', 'google-analytics', 'google-ads', 'google-ads', '' ) === $faz_own( Google_Tag::render( $mixed ) ), 'own tag: GA4 and Ads loader + bootstrap recognised by provider; the GTM container never' );
t( array( 'google-analytics', 'google-analytics', 'google-ads', 'google-ads', '' ) === $faz_own( Google_Tag::render( $mixed, array( 'data_layer' => 'my$dl' ) ) ), 'own tag: recognised with a custom dataLayer (l= parameter, $ in the name)' );
t( array( 'google-analytics', 'google-analytics', 'google-ads', 'google-ads', '' ) === $faz_own( Google_Tag::render( $mixed, array( 'gtag_unblocked' => true ) ) ), 'own tag: recognised in the live Advanced-mode markup' );
// Forged or edited markup keeps the URL matching.
t( '' === Google_Tag::own_tag_provider( 'google-ads', 'https://www.googletagmanager.com/gtag/js?id=G-ABCD1234', '' ), 'own tag: marker contradicting the ID is refused (an Ads label on the GA4 loader)' );
t( '' === Google_Tag::own_tag_provider( 'google-ads', 'https://evil.example/gtag/js?id=AW-123456789', '' ), 'own tag: another host is refused' );
t( '' === Google_Tag::own_tag_provider( 'google-ads', 'https://www.googletagmanager.com/gtag/js?id=AW-123456789&next=x', '' ), 'own tag: an extra parameter is refused' );
t( '' === Google_Tag::own_tag_provider( 'google-tag-manager', 'https://www.googletagmanager.com/gtm.js?id=GTM-ABC123', '' ), 'own tag: the Tag Manager marker is never trusted' );
t( '' === Google_Tag::own_tag_provider( '', 'https://www.googletagmanager.com/gtag/js?id=AW-123456789', '' ), 'own tag: no marker, no trust' );
t( '' === Google_Tag::own_tag_provider( 'google-ads', '', 'window["dataLayer"]=window["dataLayer"]||[];function gtag(){window["dataLayer"].push(arguments);}gtag("js",new Date());gtag("config","AW-123456789");fetch("//evil.example");' ), 'own tag: an edited inline body is refused' );
t( '' === Google_Tag::own_tag_provider( 'google-ads', '', 'window["dataLayer"]=window["dataLayer"]||[];function gtag(){window["dataLayer"].push(arguments);}gtag("js",new Date());gtag("config","AW-123456789");gtag("config","G-ABCD1234");' ), 'own tag: an inline body mixing providers is refused' );

echo "$passed passed: Google tag ID whitelist, catalogue categories, parked/live markup and dataLayer handling\n";
