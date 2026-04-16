<?php
/**
 * Plugin Name: FAZ E2E WP Consent API Mock
 * Description: Minimal mock of the WP Consent API plugin for FAZ E2E tests.
 * Version: 0.1.0
 *
 * @package FazCookieE2E
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_CONSENT_API' ) ) {
	/**
	 * Minimal marker class used by FAZ to decide whether to enqueue wca.js.
	 */
	class WP_CONSENT_API {}
}

add_action(
	'wp_head',
	static function () {
		if ( is_admin() ) {
			return;
		}
		?>
		<script id="faz-e2e-wp-consent-api-mock">
		window._fazWpConsentCalls = window._fazWpConsentCalls || [];
		window._fazWpConsentTypeEvents = window._fazWpConsentTypeEvents || [];
		window.wp_set_consent = window.wp_set_consent || function (key, status) {
			window._fazWpConsentCalls.push({ key: key, status: status });
		};
		document.addEventListener('wp_consent_type_defined', function () {
			window._fazWpConsentTypeEvents.push(window.wp_consent_type || '');
		});
		</script>
		<?php
	},
	0
);
