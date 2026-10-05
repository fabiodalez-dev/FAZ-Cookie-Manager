<?php
/**
 * Plugin Name: FAZ E2E HTML4 Rewriter
 * Description: Passes the frontend HTML through DOMDocument::loadHTML()/saveHTML(), as page optimisers such as WPSpeed's image optimiser do, when the request carries ?faz_html4_rewrite=1.
 * Version: 0.1.0
 *
 * @package FazCookieE2E
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'template_redirect',
	static function () {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! isset( $_GET['faz_html4_rewrite'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture switch.
			return;
		}
		// Priority 0: this buffer opens before FAZ's own (priority 10), so it
		// receives the page after FAZ has finished with it — the position a
		// page optimiser is in.
		ob_start(
			static function ( $html ) {
				if ( ! is_string( $html ) || '' === $html || ! class_exists( 'DOMDocument' ) ) {
					return $html;
				}
				$doc = new DOMDocument();
				libxml_use_internal_errors( true );
				$loaded = $doc->loadHTML( mb_encode_numericentity( $html, array( 0x80, 0x10FFFF, 0, 0x1FFFFF ), 'UTF-8' ) );
				libxml_clear_errors();
				if ( ! $loaded ) {
					return $html;
				}
				$out = $doc->saveHTML();
				return is_string( $out ) && '' !== $out ? $out : $html;
			}
		);
	},
	0
);
