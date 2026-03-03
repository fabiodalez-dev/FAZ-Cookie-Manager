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
		// Google Ads.
		'_gcl_au'                 => array(
			'category'    => 'advertisement',
			'duration'    => '90 days',
			'description' => 'Google Ads conversion linker cookie.',
		),
		'IDE'                     => array(
			'category'    => 'advertisement',
			'duration'    => '1 year',
			'description' => 'DoubleClick/Google cookie used for targeted advertising.',
		),
		'DSID'                    => array(
			'category'    => 'advertisement',
			'duration'    => '2 weeks',
			'description' => 'Google advertising cookie for ad personalization.',
		),
		// Facebook.
		'_fbp'                    => array(
			'category'    => 'advertisement',
			'duration'    => '3 months',
			'description' => 'Facebook Pixel cookie used for advertising and analytics.',
		),
		'_fbc'                    => array(
			'category'    => 'advertisement',
			'duration'    => '2 years',
			'description' => 'Facebook click identifier cookie.',
		),
		'fr'                      => array(
			'category'    => 'advertisement',
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
			'category'    => 'advertisement',
			'duration'    => '1 year',
			'description' => 'Microsoft Bing Ads Universal Event Tracking cookie.',
		),
		// LinkedIn.
		'bcookie'                 => array(
			'category'    => 'advertisement',
			'duration'    => '1 year',
			'description' => 'LinkedIn browser identification cookie.',
		),
		'li_sugr'                 => array(
			'category'    => 'advertisement',
			'duration'    => '3 months',
			'description' => 'LinkedIn Insight Tag cookie.',
		),
		// HubSpot.
		'__hssc'                  => array(
			'category'    => 'analytics',
			'duration'    => '30 minutes',
			'description' => 'HubSpot session tracking cookie.',
		),
		'__hssrc'                 => array(
			'category'    => 'analytics',
			'duration'    => 'session',
			'description' => 'HubSpot session reset detection cookie.',
		),
		'__hstc'                  => array(
			'category'    => 'analytics',
			'duration'    => '13 months',
			'description' => 'HubSpot main analytics cookie.',
		),
		'hubspotutk'              => array(
			'category'    => 'analytics',
			'duration'    => '13 months',
			'description' => 'HubSpot visitor tracking cookie.',
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
		'tiktok.com'            => array( '_ttp', 'tt_webid' ),
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
