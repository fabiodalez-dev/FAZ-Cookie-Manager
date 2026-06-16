<?php
/**
 * Standalone unit tests for Placeholder_Builder::detect_service_from_url().
 *
 * Guards the URL→service mapping used by the content-blocker placeholder so the
 * extended service list keeps resolving correctly and never false-matches an
 * unrelated host. detect_service_from_url() is pure PHP (a static map + stripos),
 * so it runs without a WP runtime.
 *
 * Run from project root:
 *   php tests/unit/test-placeholder-service-detection.php
 *
 * Exit code 0 = all pass; 1 = at least one failure.
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-placeholder-builder.php';

use FazCookie\Frontend\Includes\Placeholder_Builder;

$run = 0;
$pass = 0;
$fail = 0;

function expect_service( $url, $expected ) {
	global $run, $pass, $fail;
	$run++;
	$got = Placeholder_Builder::detect_service_from_url( $url );
	if ( $got === $expected ) {
		$pass++;
		echo "  \033[32m✓\033[0m $expected  ←  $url\n";
	} else {
		$fail++;
		echo "  \033[31m✗\033[0m expected '$expected', got '$got'  ←  $url\n";
	}
}

echo "\n== Placeholder_Builder::detect_service_from_url ==\n\n";

// Pre-existing services (regression guard).
expect_service( 'https://www.youtube.com/embed/abc', 'youtube' );
expect_service( 'https://youtu.be/abc', 'youtube' );
expect_service( 'https://www.youtube-nocookie.com/embed/abc', 'youtube' );
expect_service( 'https://player.vimeo.com/video/123', 'vimeo' );
expect_service( 'https://www.google.com/maps/embed?pb=x', 'google-maps' );
expect_service( 'https://www.facebook.com/plugins/post.php', 'facebook' );
expect_service( 'https://www.instagram.com/p/x/embed', 'instagram' );
expect_service( 'https://platform.twitter.com/embed/x', 'twitter' );
expect_service( 'https://x.com/user/status/1', 'twitter' );
expect_service( 'https://open.spotify.com/embed/track/x', 'spotify' );
expect_service( 'https://www.dailymotion.com/embed/video/x', 'dailymotion' );
expect_service( 'https://w.soundcloud.com/player/?url=x', 'soundcloud' );
expect_service( 'https://player.twitch.tv/?channel=x', 'twitch' );

// Extended services.
expect_service( 'https://www.tiktok.com/@x/video/123', 'tiktok' );
expect_service( 'https://www.linkedin.com/embed/feed/update/x', 'linkedin' );
expect_service( 'https://www.pinterest.com/pin/123/', 'pinterest' );
expect_service( 'https://pin.it/abcd', 'pinterest' );
expect_service( 'https://www.reddit.com/r/x/comments/y/', 'reddit' );
expect_service( 'https://redd.it/abc', 'reddit' );
expect_service( 'https://x.tumblr.com/post/123', 'tumblr' );
expect_service( 'https://www.flickr.com/photos/x/123/', 'flickr' );
expect_service( 'https://www.threads.net/@x/post/y', 'threads' );
expect_service( 'https://bsky.app/profile/x/post/y', 'bluesky' );
expect_service( 'https://t.me/channel/123', 'telegram' );
expect_service( 'https://telegram.org/js/widget.js', 'telegram' );
expect_service( 'https://calendar.google.com/calendar/embed?src=x', 'google-calendar' );
expect_service( 'https://drive.google.com/file/d/x/preview', 'google-drive' );
expect_service( 'https://docs.google.com/forms/d/e/x/viewform', 'google-docs' );
expect_service( 'https://calendly.com/x', 'calendly' );
expect_service( 'https://form.typeform.com/to/x', 'typeform' );
expect_service( 'https://www.openstreetmap.org/export/embed.html', 'openstreetmap' );
expect_service( 'https://api.mapbox.com/styles/v1/x', 'mapbox' );
expect_service( 'https://podcasts.apple.com/us/podcast/x', 'apple-podcasts' );
expect_service( 'https://music.apple.com/us/album/x', 'apple-music' );
expect_service( 'https://bandcamp.com/EmbeddedPlayer/x', 'bandcamp' );
expect_service( 'https://www.mixcloud.com/widget/iframe/?feed=x', 'mixcloud' );
expect_service( 'https://fast.wistia.net/embed/iframe/x', 'wistia' );
expect_service( 'https://www.loom.com/embed/x', 'loom' );
expect_service( 'https://streamable.com/e/x', 'streamable' );
expect_service( 'https://rumble.com/embed/x/', 'rumble' );
expect_service( 'https://codepen.io/x/embed/y', 'codepen' );
expect_service( 'https://jsfiddle.net/x/embedded/', 'jsfiddle' );
expect_service( 'https://disqus.com/embed/comments/?x', 'disqus' );
expect_service( 'https://giphy.com/embed/x', 'giphy' );
expect_service( 'https://www.slideshare.net/slideshow/embed_code/x', 'slideshare' );
expect_service( 'https://e.issuu.com/embed.html?d=x', 'issuu' );

// Must NOT false-match.
expect_service( 'https://example.com/contact', 'default' );
expect_service( 'https://content.medium.com/x', 'default' ); // contains "t.me" but not "t.me/"
expect_service( 'https://maximum.com/x', 'default' );          // contains "x.com"? no — sanity
expect_service( 'https://my-site.test/page', 'default' );
// Domains ending in "x.com" must NOT resolve to Twitter/X (host-anchored fix).
expect_service( 'https://www.dropbox.com/s/x/file', 'default' );
expect_service( 'https://www.netflix.com/title/123', 'default' );
expect_service( 'https://api.mapbox.com/styles/v1/x', 'mapbox' );

echo "\n";
echo "Tests run: $run\n";
echo "\033[32mPassed:    $pass\033[0m\n";
if ( $fail > 0 ) {
	echo "\033[31mFailed:    $fail\033[0m\n";
	exit( 1 );
}
echo "\033[32mAll placeholder service-detection tests passed.\033[0m\n";
exit( 0 );
