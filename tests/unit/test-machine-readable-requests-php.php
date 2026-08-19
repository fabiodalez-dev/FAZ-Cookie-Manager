<?php
/**
 * Content blocking must never rewrite a machine-readable representation.
 *
 * WordPress applies `the_content` inside get_the_content_feed(), and a feed
 * request reaches template_redirect like any other. Neither the is_admin()
 * guard on filter_content_blocking() nor the is_admin()/ajax/cron guard on
 * start_output_buffer() catches that, so a post carrying an embed shipped to
 * feed readers as a `data-faz-src` placeholder — and script.js does not exist
 * in a feed, so nothing could ever restore it. The embed was simply gone for
 * every RSS subscriber, every Mailchimp RSS-to-email campaign and every
 * headless consumer, with no error to show for it. Reproduced end to end on
 * the test site before the fix: `curl /feed/` returned one `data-faz-src` and
 * eight `faz-placeholder` markers where the iframe should have been.
 *
 * Blocking is also pointless there: consent is browser-side state, a feed
 * reader has no banner to accept, and the third-party resource is fetched (or
 * not) by the reader under its own rules.
 *
 * These tests pin the predicate itself — each conditional that must trip it,
 * each ordinary page shape that must NOT, and the filter escape hatch.
 *
 * Run: php tests/unit/test-machine-readable-requests-php.php
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['faz_cond']    = array();
$GLOBALS['faz_filters'] = array();

function is_feed() {
	return ! empty( $GLOBALS['faz_cond']['feed'] );
}
function is_robots() {
	return ! empty( $GLOBALS['faz_cond']['robots'] );
}
function is_trackback() {
	return ! empty( $GLOBALS['faz_cond']['trackback'] );
}
function is_favicon() {
	return ! empty( $GLOBALS['faz_cond']['favicon'] );
}
function apply_filters( $tag, $value ) {
	return isset( $GLOBALS['faz_filters'][ $tag ] ) ? $GLOBALS['faz_filters'][ $tag ] : $value;
}
function wp_doing_cron() {
	return ! empty( $GLOBALS['faz_cond']['cron'] );
}

require_once dirname( __DIR__, 2 ) . '/includes/class-utils.php';

$run    = 0;
$failed = 0;
function mr_check( $condition, $label ) {
	global $run, $failed;
	++$run;
	if ( $condition ) {
		echo "  [PASS] {$label}\n";
		return;
	}
	++$failed;
	echo "  [FAIL] {$label}\n";
}

/** Reset every conditional and filter between cases. */
function mr_reset() {
	$GLOBALS['faz_cond']    = array();
	$GLOBALS['faz_filters'] = array();
}

echo "== A machine-readable request is recognised ==\n";

foreach ( array( 'feed', 'robots', 'trackback', 'favicon' ) as $shape ) {
	mr_reset();
	$GLOBALS['faz_cond'][ $shape ] = true;
	mr_check( true === faz_is_machine_readable_request(), "is_{$shape}() marks the request machine-readable" );
}

echo "== An ordinary page is NOT — this is what stops the guard eating the site ==\n";

// The load-bearing negative. If this ever goes true, blocking silently stops
// everywhere and every tracker loads pre-consent: a compliance failure far
// worse than the bug being fixed.
mr_reset();
mr_check( false === faz_is_machine_readable_request(), 'a plain front-end page is not machine-readable' );

// 404 and search results are pages a visitor SEES, and a third-party embed can
// appear in both (a 404 template with a YouTube background, a search result
// excerpt carrying an iframe). Deliberately not in the predicate.
mr_reset();
$GLOBALS['faz_cond']['404'] = true;
mr_check( false === faz_is_machine_readable_request(), 'a 404 page still blocks — a visitor sees it' );

mr_reset();
$GLOBALS['faz_cond']['search'] = true;
mr_check( false === faz_is_machine_readable_request(), 'a search results page still blocks — a visitor sees it' );

echo "== The filter can extend the set, and cannot silently disable blocking ==\n";

mr_reset();
$GLOBALS['faz_filters']['faz_is_machine_readable_request'] = true;
mr_check( true === faz_is_machine_readable_request(), 'a site can add its own machine-readable route via the filter' );

// A filter returning false must not override a real feed: the conditionals are
// checked first and return early, so a misbehaving third-party filter cannot
// reintroduce the bug.
mr_reset();
$GLOBALS['faz_cond']['feed']                              = true;
$GLOBALS['faz_filters']['faz_is_machine_readable_request'] = false;
mr_check( true === faz_is_machine_readable_request(), 'a filter returning false cannot un-protect a genuine feed' );

echo "== Both blocking entry points consult the predicate ==\n";

// Source assertions, deliberately narrow: the behaviour above is unit-testable
// but the WIRING is not, because both callers need a booted WordPress. What
// these pin is that neither entry point can be edited back to running in a
// feed without this file going red.
$frontend_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/frontend/class-frontend.php' );

$content_at = strpos( $frontend_src, 'public function filter_content_blocking' );
$buffer_at  = strpos( $frontend_src, 'public function start_output_buffer' );
mr_check( false !== $content_at, 'filter_content_blocking() exists' );
mr_check( false !== $buffer_at, 'start_output_buffer() exists' );

// The call must appear within the first ~40 lines of each method, i.e. among
// the early bails, not somewhere after the expensive work has already run.
$content_head = substr( $frontend_src, $content_at, 2000 );
$buffer_head  = substr( $frontend_src, $buffer_at, 2000 );
mr_check(
	false !== strpos( $content_head, 'faz_is_machine_readable_request()' ),
	'filter_content_blocking() bails early on a machine-readable request'
);
mr_check(
	false !== strpos( $buffer_head, 'faz_is_machine_readable_request()' ),
	'start_output_buffer() bails early on a machine-readable request'
);

echo "== Every content path guards feeds AND cron ==\n";

// f637c26 fixed filter_content_blocking() and start_output_buffer() but left
// filter_oembed_blocking() open — and WP_Embed::autoembed runs on `the_content`
// at priority 8, which get_the_content_feed() applies, so a post carrying a
// bare YouTube URL still shipped to every subscriber as a placeholder div.
// Reproduced before the fix; verified after: feed 0 markers, page 6.
//
// wp_doing_cron() is the second half. faz_is_front_end_request() does not
// cover cron (the file documents this), so a newsletter plugin rendering post
// content on WP-Cron mailed out data-faz-src placeholders and text/plain
// scripts — and there is no JS in an email either.
$faz_guarded = array(
	'filter_content_blocking',
	'filter_oembed_blocking',
	'start_output_buffer',
);
foreach ( $faz_guarded as $faz_method ) {
	$faz_at = strpos( $frontend_src, 'public function ' . $faz_method );
	mr_check( false !== $faz_at, "{$faz_method}() exists" );
	if ( false === $faz_at ) {
		continue;
	}
	$faz_head = substr( $frontend_src, $faz_at, 2000 );
	mr_check(
		false !== strpos( $faz_head, 'faz_is_machine_readable_request()' ),
		"{$faz_method}() bails on a machine-readable request"
	);
	// start_output_buffer() already had its own cron guard before this change.
	mr_check(
		false !== strpos( $faz_head, 'wp_doing_cron()' ),
		"{$faz_method}() bails on cron"
	);
}

echo "\n{$run} checks, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
