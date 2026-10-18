<?php
/**
 * faz_is_bot() must match Pinterest's crawlers, not the people using the
 * Pinterest app.
 *
 * The pattern list carried a bare 'Pinterest', which is also a substring of
 * the Pinterest app's in-app browser User-Agent ("… [Pinterest/iOS]",
 * "… [Pinterest/Android]"). Those visitors were classified as crawlers and,
 * with hide_from_bots on (the default), received the banner-less render: no
 * consent notice for a human. The crawlers Pinterest documents are
 * Pinterestbot/1.0 (desktop and mobile) and the rich-pin fetcher
 * "Pinterest/0.2 (+http://www.pinterest.com/bot.html)".
 *
 * Loads the real includes/class-utils.php.
 *
 * Run: php tests/unit/test-bot-detection-pinterest-php.php
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	function apply_filters( $tag, $value ) { return $value; }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function wp_unslash( $value ) { return $value; }

	require dirname( __DIR__, 2 ) . '/includes/class-utils.php';

	$passed = 0;
	$failed = 0;
	function bot_check( $ua, $expected, $label ) {
		global $passed, $failed;
		$_SERVER['HTTP_USER_AGENT'] = $ua;
		if ( $expected === faz_is_bot() ) { $passed++; echo "  PASS {$label}\n"; return; }
		$failed++;
		echo "  FAIL {$label}\n";
	}

	echo "Pinterest crawlers\n";
	bot_check( 'Mozilla/5.0 (compatible; Pinterestbot/1.0; +http://www.pinterest.com/bot.html)', true, 'Pinterestbot desktop' );
	bot_check( 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.96 Mobile Safari/537.36 (compatible; Pinterestbot/1.0; +http://www.pinterest.com/bot.html)', true, 'Pinterestbot mobile' );
	bot_check( 'Pinterest/0.2 (+http://www.pinterest.com/bot.html)', true, 'rich-pin fetcher Pinterest/0.2' );

	echo "People in the Pinterest app\n";
	bot_check( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [Pinterest/iOS]', false, 'Pinterest in-app browser, iOS' );
	bot_check( 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36 [Pinterest/Android]', false, 'Pinterest in-app browser, Android' );

	echo "Unchanged neighbours\n";
	bot_check( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', true, 'Googlebot' );
	bot_check( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', false, 'Safari' );

	echo "bot-detection-pinterest: {$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
