<?php
/**
 * Plugin Name: FAZ E2E Template Stripper
 * Description: Removes the FAZ banner template element from frontend HTML to exercise the missing-template runtime path.
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
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		ob_start(
			static function ( $html ) {
				if ( ! is_string( $html ) || '' === $html ) {
					return $html;
				}
				return preg_replace( '#<(?:template|script)\b[^>]*\bid=("|\')fazBannerTemplate\\1[^>]*>.*?</(?:template|script)>#is', '', $html ) ?? $html;
			}
		);
	},
	0
);
