<?php
/**
 * Standalone unit tests for what survives the placeholder's <template>.
 *
 * Subsystem: placeholder-template-attrs-php
 *
 * Placeholder_Builder::build() stores the parked iframe (or script) inside
 * <template class="faz-placeholder-content"> after wp_kses() with a
 * hand-written allow-list, and _fazUnblockServerSide() clones that content
 * live after consent. The list had the functional attributes (allow,
 * allowfullscreen, loading) and not the publisher's own hardening: sandbox,
 * referrerpolicy, name, scrolling, data-* and aria-* were stripped, so a
 * `<iframe sandbox="allow-scripts" referrerpolicy="no-referrer">` came back
 * after consent unsandboxed and sending the full referrer — a weaker posture
 * than the one the publisher authored, with no error anywhere. A script lost
 * crossorigin, integrity, referrerpolicy, nonce and id the same way.
 *
 * kses stays: the clone goes live after consent, so it is the last filter
 * before live insertion of oEmbed/post-derived markup. The fix widens the
 * list; this file pins what the list must carry and that the filter still
 * drops what it should.
 *
 * The wp_kses() double here filters attributes the way core does — an
 * attribute survives when the element's list names it, or when it is a
 * `data-*` attribute and the list carries the `data-*` wildcard; core has no
 * aria-* wildcard, so those are listed by name.
 *
 * Run: php tests/unit/test-placeholder-template-attrs-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
	if ( ! function_exists( 'esc_html__' ) ) {
		function esc_html__( $text, $domain = '' ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( $url ) {
			return (string) $url;
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			return $value;
		}
	}
	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $key ) {
			return false;
		}
	}
	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $key, $value, $ttl = 0 ) {
			return true;
		}
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url, $component = -1 ) {
			return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); // phpcs:ignore
		}
	}
	// Embed_Inventory::note(), which build() calls, reaches for these.
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $str ) ) );
		}
	}
	if ( ! function_exists( 'is_admin' ) ) {
		function is_admin() {
			return false;
		}
	}
	if ( ! function_exists( 'wp_doing_cron' ) ) {
		function wp_doing_cron() {
			return true; // The shutdown flush returns early: no row to write here.
		}
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $key, $default = false ) {
			return $default;
		}
	}
	if ( ! function_exists( 'update_option' ) ) {
		function update_option( $key, $value, $autoload = null ) {
			return true;
		}
	}
	if ( ! function_exists( 'add_action' ) ) {
		function add_action( $tag, $callback, $priority = 10, $args = 1 ) {
			return true;
		}
	}
	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type, $gmt = 0 ) {
			return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
		}
	}
	if ( ! function_exists( 'wp_kses_allowed_html' ) ) {
		function wp_kses_allowed_html( $context = 'post' ) {
			// Core's post list has no iframe and no script entry; the plugin's
			// own entries are the only ones that apply here.
			return array( 'p' => array(), 'a' => array( 'href' => true ) );
		}
	}
	if ( ! function_exists( 'wp_kses' ) ) {
		/**
		 * Attribute filtering the way wp_kses_attr_check() does it: unknown
		 * element → dropped; attribute kept when named, or when it is data-*
		 * and the element's list carries 'data-*'. Nothing else survives.
		 */
		function wp_kses( $html, $allowed ) {
			return preg_replace_callback(
				'/<([a-zA-Z][a-zA-Z0-9-]*)((?:\s+[^\s=>\/]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?)*)\s*(\/?)>/',
				function ( $m ) use ( $allowed ) {
					$tag = strtolower( $m[1] );
					if ( ! isset( $allowed[ $tag ] ) ) {
						return '';
					}
					$list = array_change_key_case( (array) $allowed[ $tag ], CASE_LOWER );
					$kept = '';
					if ( preg_match_all( '/\s+([^\s=>\/]+)(\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?/', $m[2], $attrs, PREG_SET_ORDER ) ) {
						foreach ( $attrs as $a ) {
							$name = strtolower( $a[1] );
							$ok   = ! empty( $list[ $name ] )
								|| ( 0 === strpos( $name, 'data-' ) && ! empty( $list['data-*'] ) && preg_match( '/^data-[a-z0-9_-]+$/', $name ) );
							if ( $ok ) {
								$kept .= ' ' . $a[1] . ( isset( $a[2] ) ? $a[2] : '' );
							}
						}
					}
					return '<' . $m[1] . $kept . ( $m[3] ? '/' : '' ) . '>';
				},
				(string) $html
			);
		}
	}

	require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-embed-inventory.php';
	require_once dirname( __DIR__, 2 ) . '/frontend/includes/class-placeholder-builder.php';

	use FazCookie\Frontend\Includes\Placeholder_Builder;

	$tests_run    = 0;
	$tests_passed = 0;
	$tests_failed = 0;

	function assert_true( $cond, $label ) {
		global $tests_run, $tests_passed, $tests_failed;
		$tests_run++;
		if ( $cond ) {
			$tests_passed++;
			echo "  \033[32m✓\033[0m " . $label . "\n";
		} else {
			$tests_failed++;
			echo "  \033[31m✗\033[0m " . $label . "\n";
		}
	}

	function faz_template_content( $html ) {
		return preg_match( '#<template class="faz-placeholder-content">(.*?)</template>#s', $html, $m ) ? $m[1] : '';
	}

	echo "\n-- the publisher's iframe attributes survive the template --\n";

	$iframe = '<iframe class="faz-hidden" data-faz-category="marketing" data-faz-src="https://www.youtube-nocookie.com/embed/ID"'
		. ' sandbox="allow-scripts allow-same-origin" referrerpolicy="no-referrer" name="yt1" scrolling="no"'
		. ' aria-label="Video" aria-hidden="false" data-lazy="1" width="560" height="315" frameborder="0" loading="lazy"'
		. ' allow="autoplay" allowfullscreen title="T" onload="leak()"></iframe>';
	$tpl    = faz_template_content( Placeholder_Builder::build( 'youtube', 'YouTube', 'marketing', $iframe ) );

	assert_true( '' !== $tpl && false !== strpos( $tpl, '<iframe' ), 'premise: the parked iframe is inside the template' );
	assert_true( false !== strpos( $tpl, 'data-faz-src="https://www.youtube-nocookie.com/embed/ID"' ), 'the parked src survives' );
	assert_true( false !== strpos( $tpl, 'sandbox="allow-scripts allow-same-origin"' ), 'sandbox survives: the consented embed must not gain what the publisher removed' );
	assert_true( false !== strpos( $tpl, 'referrerpolicy="no-referrer"' ), 'referrerpolicy survives' );
	assert_true( false !== strpos( $tpl, 'name="yt1"' ), 'name survives' );
	assert_true( false !== strpos( $tpl, 'scrolling="no"' ), 'scrolling survives' );
	assert_true( false !== strpos( $tpl, 'aria-label="Video"' ) && false !== strpos( $tpl, 'aria-hidden="false"' ), 'aria-label and aria-hidden survive' );
	assert_true( false !== strpos( $tpl, 'data-lazy="1"' ), 'another plugin\'s data-* attribute survives' );
	assert_true( false !== strpos( $tpl, 'allow="autoplay"' ) && false !== strpos( $tpl, 'allowfullscreen' ) && false !== strpos( $tpl, 'loading="lazy"' ), 'and the functional attributes still do' );
	assert_true( false === strpos( $tpl, 'onload=' ), 'while an event handler is still dropped: kses is widened, not skipped' );

	echo "\n-- the script's integrity attributes survive too --\n";

	$script = '<script type="text/plain" data-faz-category="marketing" data-faz-src="https://cdn.tracker.test/t.js"'
		. ' crossorigin="anonymous" integrity="sha384-abc" referrerpolicy="no-referrer" nonce="n1" id="s1" data-cfasync="false" async onerror="leak()"></script>';
	$tpl    = faz_template_content( Placeholder_Builder::build( 'default', 'Embedded content', 'marketing', $script ) );

	assert_true( false !== strpos( $tpl, 'crossorigin="anonymous"' ), 'crossorigin survives' );
	assert_true( false !== strpos( $tpl, 'integrity="sha384-abc"' ), 'integrity survives: a restored script must still be checked against its hash' );
	assert_true( false !== strpos( $tpl, 'referrerpolicy="no-referrer"' ), 'referrerpolicy survives' );
	assert_true( false !== strpos( $tpl, 'nonce="n1"' ), 'nonce survives' );
	assert_true( false !== strpos( $tpl, 'id="s1"' ), 'id survives' );
	assert_true( false !== strpos( $tpl, 'data-cfasync="false"' ), 'a data-* attribute survives' );
	assert_true( false === strpos( $tpl, 'onerror=' ), 'an event handler is dropped' );

	echo "\n";
	if ( 0 === $tests_failed ) {
		echo "ALL PASS ({$tests_passed})\n";
		exit( 0 );
	}
	echo "FAILED: {$tests_failed}, passed: {$tests_passed}\n";
	exit( 1 );
}
