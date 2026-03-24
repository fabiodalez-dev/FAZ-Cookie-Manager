<?php
/**
 * Known cookies database for local scanning.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Scanner\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static database of known cookies and their metadata.
 *
 * @class       Cookie_Database
 * @version     3.1.0
 * @package     FazCookie
 */
class Cookie_Database {

	/**
	 * Known cookies with category, duration, and description.
	 *
	 * @var array
	 */
	private static $known_cookies = array(
		// WordPress — frontend-visible (necessary, shown in banner).
		'wpEmojiSettingsSupports' => array(
			'category'    => 'necessary',
			'duration'    => 'session',
			'description' => 'WordPress sets this cookie when a user interacts with emojis on a WordPress site.',
		),
		'wordpress_test_cookie'   => array(
			'category'    => 'necessary',
			'duration'    => 'session',
			'description' => 'WordPress test cookie to check if cookies are enabled.',
		),
		'wordpress_logged_in_'    => array(
			'category'    => 'necessary',
			'duration'    => 'session',
			'description' => 'Indicates logged-in status and user identity.',
			'match'       => 'prefix',
		),
		// WordPress — admin-only (hidden internal category).
		'wp-settings-'            => array(
			'category'    => 'wordpress-internal',
			'duration'    => '1 year',
			'description' => 'Customizes the admin interface for each user.',
			'match'       => 'prefix',
		),
		'wordpress_'              => array(
			'category'    => 'wordpress-internal',
			'duration'    => 'session',
			'description' => 'WordPress authentication cookie for the admin area.',
			'match'       => 'prefix',
		),
		'wp_lang'                 => array(
			'category'    => 'wordpress-internal',
			'duration'    => 'session',
			'description' => 'Stores the selected language during login.',
		),
		'comment_author_email_'   => array(
			'category'    => 'functional',
			'duration'    => '1 year',
			'description' => 'Stores the commenter email for convenience.',
			'match'       => 'prefix',
		),
		'comment_author_url_'     => array(
			'category'    => 'functional',
			'duration'    => '1 year',
			'description' => 'Stores the commenter website URL for convenience.',
			'match'       => 'prefix',
		),
		'comment_author_'         => array(
			'category'    => 'functional',
			'duration'    => '1 year',
			'description' => 'Stores the commenter name for convenience.',
			'match'       => 'prefix',
		),
		// Google Analytics.
		'_ga'                     => array(
			'category'    => 'analytics',
			'duration'    => '2 years',
			'description' => 'Google Analytics cookie used to distinguish users.',
		),
		'_ga_'                    => array(
			'category'    => 'analytics',
			'duration'    => '2 years',
			'description' => 'Google Analytics 4 cookie used to persist session state.',
			'match'       => 'prefix',
		),
		'_gid'                    => array(
			'category'    => 'analytics',
			'duration'    => '24 hours',
			'description' => 'Google Analytics cookie used to distinguish users.',
		),
		'_gat'                    => array(
			'category'    => 'analytics',
			'duration'    => '1 minute',
			'description' => 'Google Analytics cookie used to throttle request rate.',
		),
		'_gac_'                   => array(
			'category'    => 'analytics',
			'duration'    => '90 days',
			'description' => 'Google Analytics cookie containing campaign information.',
			'match'       => 'prefix',
		),
		'__utma'                  => array(
			'category'    => 'analytics',
			'duration'    => '2 years',
			'description' => 'Google Analytics (Classic) cookie used to distinguish users and sessions.',
		),
		'__utmb'                  => array(
			'category'    => 'analytics',
			'duration'    => '30 minutes',
			'description' => 'Google Analytics (Classic) cookie used to determine new sessions.',
		),
		'__utmc'                  => array(
			'category'    => 'analytics',
			'duration'    => 'session',
			'description' => 'Google Analytics (Classic) cookie used with __utmb to determine new sessions.',
		),
		'__utmz'                  => array(
			'category'    => 'analytics',
			'duration'    => '6 months',
			'description' => 'Google Analytics (Classic) cookie that stores the traffic source or campaign.',
		),
		'__utmt'                  => array(
			'category'    => 'analytics',
			'duration'    => '10 minutes',
			'description' => 'Google Analytics (Classic) cookie used to throttle request rate.',
		),
		// Google Ads.
		'_gcl_au'                 => array(
			'category'    => 'marketing',
			'duration'    => '90 days',
			'description' => 'Google Ads conversion linker cookie.',
		),
		'IDE'                     => array(
			'category'    => 'marketing',
			'duration'    => '1 year',
			'description' => 'DoubleClick/Google cookie used for targeted advertising.',
		),
		'DSID'                    => array(
			'category'    => 'marketing',
			'duration'    => '2 weeks',
			'description' => 'Google advertising cookie for ad personalization.',
		),
		// Facebook.
		'_fbp'                    => array(
			'category'    => 'marketing',
			'duration'    => '3 months',
			'description' => 'Facebook Pixel cookie used for advertising and analytics.',
		),
		'_fbc'                    => array(
			'category'    => 'marketing',
			'duration'    => '2 years',
			'description' => 'Facebook click identifier cookie.',
		),
		'fr'                      => array(
			'category'    => 'marketing',
			'duration'    => '3 months',
			'description' => 'Facebook advertising cookie.',
		),
		// Cloudflare.
		'__cf_bm'                 => array(
			'category'    => 'necessary',
			'duration'    => '30 minutes',
			'description' => 'Cloudflare bot management cookie.',
		),
		'__cfduid'                => array(
			'category'    => 'necessary',
			'duration'    => '30 days',
			'description' => 'Cloudflare cookie used for identifying trusted web traffic.',
		),
		// Google reCAPTCHA.
		'_GRECAPTCHA'             => array(
			'category'    => 'necessary',
			'duration'    => '6 months',
			'description' => 'Used by Google reCAPTCHA to distinguish between humans and bots.',
		),
		// GDPR/Cookie consent.
		'fazcookie-consent'       => array(
			'category'    => 'necessary',
			'duration'    => '1 year',
			'description' => 'Cookie consent preferences set by the visitor.',
		),
		// Microsoft.
		'_clck'                   => array(
			'category'    => 'analytics',
			'duration'    => '1 year',
			'description' => 'Microsoft Clarity cookie for analytics.',
		),
		'_clsk'                   => array(
			'category'    => 'analytics',
			'duration'    => '1 day',
			'description' => 'Microsoft Clarity session cookie.',
		),
		'MUID'                    => array(
			'category'    => 'marketing',
			'duration'    => '1 year',
			'description' => 'Microsoft Bing Ads Universal Event Tracking cookie.',
		),
		// Microsoft Ads (Bing UET).
		'_uetsid'                 => array(
			'category'    => 'marketing',
			'duration'    => '1 day',
			'description' => 'Microsoft Bing Ads UET session tracking cookie.',
		),
		'_uetvid'                 => array(
			'category'    => 'marketing',
			'duration'    => '16 days',
			'description' => 'Microsoft Bing Ads UET cross-session tracking cookie.',
		),
		// LinkedIn.
		'bcookie'                 => array(
			'category'    => 'marketing',
			'duration'    => '1 year',
			'description' => 'LinkedIn browser identification cookie.',
		),
		'li_sugr'                 => array(
			'category'    => 'marketing',
			'duration'    => '3 months',
			'description' => 'LinkedIn Insight Tag cookie.',
		),
		'lidc'                    => array(
			'category'    => 'marketing',
			'duration'    => '1 day',
			'description' => 'LinkedIn cookie for data center routing optimization.',
		),
		// HubSpot.
		'__hssc'                  => array(
			'category'    => 'marketing',
			'duration'    => '30 minutes',
			'description' => 'HubSpot session tracking cookie.',
		),
		'__hssrc'                 => array(
			'category'    => 'marketing',
			'duration'    => 'session',
			'description' => 'HubSpot session reset detection cookie.',
		),
		'__hstc'                  => array(
			'category'    => 'marketing',
			'duration'    => '13 months',
			'description' => 'HubSpot main analytics cookie.',
		),
		'hubspotutk'              => array(
			'category'    => 'marketing',
			'duration'    => '13 months',
			'description' => 'HubSpot visitor tracking cookie.',
		),
		'__hs_opt_out'            => array(
			'category'    => 'marketing',
			'duration'    => '13 months',
			'description' => 'HubSpot cookie to remember opt-out preference.',
		),
		// YouTube.
		'YSC'                     => array(
			'category'    => 'marketing',
			'duration'    => 'session',
			'description' => 'YouTube cookie used to track views of embedded videos.',
		),
		'VISITOR_INFO1_LIVE'      => array(
			'category'    => 'marketing',
			'duration'    => '6 months',
			'description' => 'YouTube cookie used to estimate bandwidth and display settings.',
		),
		// Vimeo.
		'vuid'                    => array(
			'category'    => 'marketing',
			'duration'    => '2 years',
			'description' => 'Vimeo analytics cookie to track video views.',
		),
		// Stripe.
		'__stripe_mid'            => array(
			'category'    => 'functional',
			'duration'    => '1 year',
			'description' => 'Stripe fraud prevention cookie for payment processing.',
		),
		'__stripe_sid'            => array(
			'category'    => 'functional',
			'duration'    => '30 minutes',
			'description' => 'Stripe fraud prevention session cookie for payment processing.',
		),
		// Mixpanel.
		'mp_'                     => array(
			'category'    => 'analytics',
			'duration'    => '1 year',
			'description' => 'Mixpanel analytics cookie for tracking user interactions.',
			'match'       => 'prefix',
		),
		'distinct_id'             => array(
			'category'    => 'analytics',
			'duration'    => '1 year',
			'description' => 'Mixpanel cookie used to identify unique users.',
		),
		// Hotjar.
		'_hj'                     => array(
			'category'    => 'analytics',
			'duration'    => '1 year',
			'description' => 'Hotjar analytics cookie.',
			'match'       => 'prefix',
		),
		// WooCommerce — frontend-visible (necessary, shown in banner).
		'woocommerce_cart_hash'   => array(
			'category'    => 'necessary',
			'duration'    => 'session',
			'description' => 'WooCommerce cookie to determine cart contents changes.',
		),
		'woocommerce_items_in_cart' => array(
			'category'    => 'necessary',
			'duration'    => 'session',
			'description' => 'WooCommerce cookie to track items in cart.',
		),
		'wp_woocommerce_session_' => array(
			'category'    => 'necessary',
			'duration'    => '2 days',
			'description' => 'WooCommerce session cookie.',
			'match'       => 'prefix',
		),
		// TikTok.
		'__tea_cache_tokens_'     => array(
			'category'    => 'marketing',
			'duration'    => '1 day',
			'description' => 'TikTok cache token used by the analytics SDK.',
			'match'       => 'prefix',
		),
		'_ttp'                    => array(
			'category'    => 'marketing',
			'duration'    => '13 months',
			'description' => 'TikTok Pixel cookie used to track visitors across websites for advertising.',
		),
		'tt_webid'                => array(
			'category'    => 'marketing',
			'duration'    => '13 months',
			'description' => 'TikTok cookie used to identify and track users for advertising purposes.',
		),
		// Twitter / X.
		'guest_id'                => array(
			'category'    => 'marketing',
			'duration'    => '2 years',
			'description' => 'Twitter cookie used to identify non-logged-in users.',
		),
		'personalization_id'      => array(
			'category'    => 'marketing',
			'duration'    => '2 years',
			'description' => 'Twitter cookie used for personalization and advertising.',
		),
		'ct0'                     => array(
			'category'    => 'marketing',
			'duration'    => 'session',
			'description' => 'Twitter cookie used for security and spam prevention on embedded content.',
		),
		// Snapchat.
		'_scid'                   => array(
			'category'    => 'marketing',
			'duration'    => '13 months',
			'description' => 'Snapchat Pixel cookie for cross-site visitor identification.',
		),
		'sc_at'                   => array(
			'category'    => 'marketing',
			'duration'    => '13 months',
			'description' => 'Snapchat Pixel cookie used for advertising attribution.',
		),
		// Pinterest.
		'_pin_unauth'             => array(
			'category'    => 'marketing',
			'duration'    => '1 year',
			'description' => 'Pinterest cookie used to track unauthenticated users.',
		),
		'_pinterest_sess'         => array(
			'category'    => 'marketing',
			'duration'    => '1 year',
			'description' => 'Pinterest session cookie used for advertising and tracking.',
		),
		// PHP.
		'PHPSESSID'               => array(
			'category'    => 'necessary',
			'duration'    => 'session',
			'description' => 'PHP session cookie for managing user sessions.',
		),
	);

	/**
	 * Look up a cookie name in the known cookies database.
	 *
	 * @param string $name Cookie name.
	 * @return array|null Cookie info or null if not found.
	 */
	public static function lookup( $name ) {
		// Exact match first.
		if ( isset( self::$known_cookies[ $name ] ) ) {
			return self::$known_cookies[ $name ];
		}
		// Prefix match.
		foreach ( self::$known_cookies as $key => $info ) {
			if ( isset( $info['match'] ) && 'prefix' === $info['match'] ) {
				if ( 0 === strpos( $name, $key ) ) {
					return $info;
				}
			}
		}
		return null;
	}

	/**
	 * Script URL domain → cookies mapping.
	 *
	 * Maps third-party script domains to the cookies they typically set.
	 * Used by the browser-based scanner to infer cookies from detected scripts.
	 *
	 * @var array
	 */
	private static $script_cookies = array(
		'google-analytics.com'  => array( '_ga', '_gid', '_gat' ),
		'googletagmanager.com'  => array( '_ga', '_gid', '_gat', '_gcl_au' ),
		'connect.facebook.net'  => array( '_fbp', '_fbc', 'fr' ),
		'bat.bing.com'          => array( 'MUID', '_uetsid', '_uetvid' ),
		'clarity.ms'            => array( '_clck', '_clsk' ),
		'static.hotjar.com'     => array( '_hjSessionUser_', '_hjSession_' ),
		'snap.licdn.com'        => array( 'li_sugr', 'bcookie', 'lidc' ),
		'youtube.com'           => array( 'YSC', 'VISITOR_INFO1_LIVE' ),
		'doubleclick.net'       => array( 'IDE', 'DSID' ),
		'stripe.com'            => array( '__stripe_mid', '__stripe_sid' ),
		'cdn.mxpnl.com'         => array( 'mp_', 'distinct_id' ),
		'js.hs-scripts.com'     => array( '__hssc', '__hssrc', '__hstc', 'hubspotutk' ),
		'js.hs-analytics.net'   => array( '__hssc', '__hssrc', '__hstc', 'hubspotutk' ),
		'sc-static.net'         => array( '_scid', 'sc_at' ),
		'ads.linkedin.com'      => array( 'li_sugr', 'bcookie', 'lidc' ),
		'platform.twitter.com'  => array( 'guest_id', 'ct0', 'personalization_id' ),
		'tiktok.com'            => array( '_ttp', 'tt_webid', '__tea_cache_tokens_' ),
		'analytics.tiktok.com'  => array( '_ttp', 'tt_webid', '__tea_cache_tokens_' ),
		'pinterest.com'         => array( '_pinterest_sess', '_pin_unauth' ),
	);

	/**
	 * Get all known cookies.
	 *
	 * @return array
	 */
	public static function get_all() {
		return self::$known_cookies;
	}

	/**
	 * Look up cookies inferred from detected script URLs.
	 *
	 * Checks each script URL against the known script→cookie mapping and
	 * returns cookie info for any matches found in the known cookies database.
	 *
	 * @param array $script_urls Array of script URL strings.
	 * @return array Array of cookie data arrays with 'source' => 'inferred'.
	 */
	public static function lookup_scripts( $script_urls ) {
		$inferred = array();
		$seen     = array();

		foreach ( $script_urls as $url ) {
			foreach ( self::$script_cookies as $domain => $cookie_names ) {
				if ( false === strpos( $url, $domain ) ) {
					continue;
				}
				foreach ( $cookie_names as $name ) {
					if ( isset( $seen[ $name ] ) ) {
						continue;
					}
					$seen[ $name ] = true;

					$known = self::lookup( $name );
					if ( $known ) {
						$inferred[] = array(
							'name'        => $name,
							'domain'      => $domain,
							'duration'    => $known['duration'],
							'description' => $known['description'],
							'category'    => $known['category'],
							'source'      => 'inferred',
						);
					} else {
						$inferred[] = array(
							'name'        => $name,
							'domain'      => $domain,
							'duration'    => 'unknown',
							'description' => 'Inferred from ' . $domain . ' script.',
							'category'    => 'uncategorized',
							'source'      => 'inferred',
						);
					}
				}
			}
		}

		return $inferred;
	}

	/**
	 * Get the script→cookie mapping table.
	 *
	 * @return array
	 */
	public static function get_script_map() {
		return self::$script_cookies;
	}
}
