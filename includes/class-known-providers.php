<?php
/**
 * Known third-party provider database for script blocking.
 *
 * Static class with comprehensive URL/inline-code patterns mapped to
 * consent categories.  Used by the output-buffer blocker (server-side)
 * and the client-side createElement / MutationObserver interceptor.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Known_Providers
 */
class Known_Providers {

	/**
	 * Return every known third-party service.
	 *
	 * Each entry contains:
	 *   - label    (string)   Human-readable service name.
	 *   - category (string)   Default consent-category slug.
	 *   - patterns (string[]) URL fragments / inline-code signatures.
	 *   - cookies  (string[]) Optional — cookie names set by the service.
	 *
	 * @return array
	 */
	public static function get_all() {
		return array(

			/* ── Google Analytics ──────────────────────────── */
			'google-analytics' => array(
				'label'    => 'Google Analytics',
				'category' => 'analytics',
				'patterns' => array(
					'google-analytics.com/analytics.js',
					'google-analytics.com/ga.js',
					'googletagmanager.com/gtag/js',
					'www.google-analytics.com/analytics.js',
					"gtag('js'",
					"gtag('config'",
					'_getTracker',
					'ga.create',
					"ga('create'",
					"ga('send'",
					'mi_track_user',
					'monsterinsights',
					'google-analytics-for-wordpress/assets/js/',
					'exactmetrics',
					'analytify',
					'gainwp',
					'jeep-google-analytics',
					'ht-easy-ga4',
					'ga-google-analytics',
					'beehive-analytics',
					'conversios',
					'enhanced-e-commerce-for-woocommerce',
					'google-analytics-wd',
				),
				'cookies'  => array( '_ga', '_ga_*', '_gid', '_gat', '__utma', '__utmb', '__utmc', '__utmz', '__utmt' ),
			),

			/* ── Google Tag Manager ───────────────────────── */
			'google-tag-manager' => array(
				'label'    => 'Google Tag Manager',
				'category' => 'analytics',
				'patterns' => array(
					'googletagmanager.com/gtm.js',
					'googletagmanager.com/gtm',
					'gtm.start',
					'gtm4wp',
					'gtmkit',
					'gtm4wp-',
					'zeeker-gtm',
				),
				'cookies'  => array(),
			),

			/* ── Google Ads / DoubleClick ─────────────────── */
			'google-ads' => array(
				'label'    => 'Google Ads',
				'category' => 'marketing',
				'patterns' => array(
					'googleadservices.com/pagead/conversion',
					'googleadservices.com/pagead',
					'googlesyndication.com',
					'pagead2.googlesyndication.com',
					'adservice.google.com',
					'google_ads',
					'google_conversion',
					'googleads',
					'doubleclick.net',
					'securepubads.g.doubleclick.net',
				),
				'cookies'  => array( '_gcl_au', '_gcl_aw', 'IDE', 'test_cookie' ),
			),

			/* ── Site Kit by Google ───────────────────────── */
			'google-sitekit' => array(
				'label'    => 'Site Kit by Google',
				'category' => 'analytics',
				'patterns' => array(
					'google-site-kit',
					'googlesitekit',
				),
				'cookies'  => array(),
			),

			/* ── Meta / Facebook Pixel ────────────────────── */
			'facebook' => array(
				'label'    => 'Meta Pixel (Facebook)',
				'category' => 'marketing',
				'patterns' => array(
					'connect.facebook.net',
					'facebook.com/tr',
					'fbevents.js',
					'fbq(',
					'fbq (',
					"fbq('init'",
					"fbq('track'",
					'<!-- Facebook Pixel Code -->',
					'<!-- Meta Pixel Code -->',
					'FacebookPixelPlugin',
					'pixel-caffeine',
					'facebook-for-woocommerce',
					'facebook-for-wordpress',
					'fatcatapps-pixel',
					'kliken',
					'facebook.com/plugins',
					'www.facebook.com/plugins',
					'fb-root',
				),
				'cookies'  => array( '_fbp', '_fbc', 'fr', 'datr', 'sb' ),
			),

			/* ── TikTok ───────────────────────────────────── */
			'tiktok' => array(
				'label'    => 'TikTok Pixel',
				'category' => 'marketing',
				'patterns' => array(
					'analytics.tiktok.com',
					'tiktok.com/i18n/pixel',
					'ttq.load(',
					'ttq.load (',
					'tiktok-events',
					'__tea_cache_tokens_',
				),
				'cookies'  => array( '_ttp', 'tt_webid', 'tt_webid_v2' ),
			),

			/* ── Pinterest ────────────────────────────────── */
			'pinterest' => array(
				'label'    => 'Pinterest Tag',
				'category' => 'marketing',
				'patterns' => array(
					's.pinimg.com/ct/core.js',
					'assets.pinterest.com',
					'pintrk(',
					'pintrk (',
					'pinterest-for-woocommerce',
				),
				'cookies'  => array( '_pin_unauth', '_pinterest_ct_ua' ),
			),

			/* ── Twitter / X ──────────────────────────────── */
			'twitter' => array(
				'label'    => 'Twitter/X Pixel',
				'category' => 'marketing',
				'patterns' => array(
					'platform.twitter.com',
					'twitter-widgets.js',
					'ads-twitter.com',
					'uwt.js',
					'twq(',
					'static.ads-twitter.com',
				),
				'cookies'  => array( 'personalization_id', 'guest_id' ),
			),

			/* ── LinkedIn ─────────────────────────────────── */
			'linkedin' => array(
				'label'    => 'LinkedIn Insight Tag',
				'category' => 'marketing',
				'patterns' => array(
					'platform.linkedin.com',
					'snap.licdn.com',
					'linkedin.com/embed',
					'insight.min.js',
					'_linkedin_partner_id',
					'lintrk(',
				),
				'cookies'  => array( 'li_sugr', 'bcookie', 'lidc', 'UserMatchHistory', 'AnalyticsSyncHistory', 'ln_or' ),
			),

			/* ── Snapchat ─────────────────────────────────── */
			'snapchat' => array(
				'label'    => 'Snapchat Pixel',
				'category' => 'marketing',
				'patterns' => array(
					'sc-static.net/scevent.min.js',
					'snapchat.com',
					'snaptr(',
				),
				'cookies'  => array( '_scid', 'sc_at' ),
			),

			/* ── Microsoft Advertising / Bing UET ─────────── */
			'microsoft-ads' => array(
				'label'    => 'Microsoft Advertising (Bing UET)',
				'category' => 'marketing',
				'patterns' => array(
					'bat.bing.com',
					'bat.bing.com/bat.js',
					'UET tag',
					'uetq',
				),
				'cookies'  => array( '_uetsid', '_uetvid', 'MUID' ),
			),

			/* ── Microsoft Clarity ────────────────────────── */
			'clarity' => array(
				'label'    => 'Microsoft Clarity',
				'category' => 'analytics',
				'patterns' => array(
					'clarity.ms',
					'clarity.ms/tag/',
				),
				'cookies'  => array( '_clsk', '_clck', 'CLID' ),
			),

			/* ── Hotjar ───────────────────────────────────── */
			'hotjar' => array(
				'label'    => 'Hotjar',
				'category' => 'analytics',
				'patterns' => array(
					'static.hotjar.com',
					'hotjar.com/c/hotjar',
					'_hjSettings',
				),
				'cookies'  => array( '_hjid', '_hjSessionUser_*', '_hjSession_*', '_hjAbsoluteSessionInProgress', '_hjFirstSeen' ),
			),

			/* ── Matomo / Piwik ────────────────────────────── */
			'matomo' => array(
				'label'    => 'Matomo (Piwik)',
				'category' => 'analytics',
				'patterns' => array(
					'matomo.js',
					'piwik.js',
					'matomo.php',
					'piwik.php',
				),
				'cookies'  => array( '_pk_id.*', '_pk_ses.*', '_pk_ref.*' ),
			),

			/* ── HubSpot ──────────────────────────────────── */
			'hubspot' => array(
				'label'    => 'HubSpot',
				'category' => 'marketing',
				'patterns' => array(
					'js.hs-scripts.com/',
					'hbspt.forms.create',
					'js.hsforms.net',
					'track.hubspot.com',
					'js.hs-analytics.net',
				),
				'cookies'  => array( '__hstc', 'hubspotutk', '__hssc', '__hssrc' ),
			),

			/* ── YouTube ──────────────────────────────────── */
			'youtube' => array(
				'label'    => 'YouTube',
				'category' => 'marketing',
				'patterns' => array(
					'youtube.com/embed',
					'youtube-nocookie.com/embed',
					'youtu.be',
					'youtube.com/iframe_api',
					'ytimg.com',
					'yotuwp',
				),
				'cookies'  => array( 'YSC', 'VISITOR_INFO1_LIVE', 'LOGIN_INFO' ),
			),

			/* ── Vimeo ────────────────────────────────────── */
			'vimeo' => array(
				'label'    => 'Vimeo',
				'category' => 'marketing',
				'patterns' => array(
					'player.vimeo.com',
					'i.vimeocdn.com',
				),
				'cookies'  => array( 'vuid' ),
			),

			/* ── Google Maps ──────────────────────────────── */
			'google-maps' => array(
				'label'    => 'Google Maps',
				'category' => 'functional',
				'patterns' => array(
					'maps.googleapis.com',
					'maps.google.com',
					'google.com/maps',
					'new google.maps.',
					'wp-google-maps',
				),
				'cookies'  => array(),
			),

			/* ── Google reCAPTCHA ─────────────────────────── */
			'google-recaptcha' => array(
				'label'    => 'Google reCAPTCHA',
				'category' => 'functional',
				'patterns' => array(
					'google.com/recaptcha',
					'gstatic.com/recaptcha',
					'grecaptcha',
					'recaptcha/api',
				),
				'cookies'  => array(),
			),

			/* ── Google Fonts ─────────────────────────────── */
			'google-fonts' => array(
				'label'    => 'Google Fonts',
				'category' => 'functional',
				'patterns' => array(
					'fonts.googleapis.com',
					'fonts.gstatic.com',
				),
				'cookies'  => array(),
			),

			/* ── Adobe Fonts (Typekit) ────────────────────── */
			'adobe-fonts' => array(
				'label'    => 'Adobe Fonts (Typekit)',
				'category' => 'functional',
				'patterns' => array(
					'use.typekit.net',
					'p.typekit.net',
				),
				'cookies'  => array(),
			),

			/* ── Instagram ────────────────────────────────── */
			'instagram' => array(
				'label'    => 'Instagram Embed',
				'category' => 'marketing',
				'patterns' => array(
					'instagram.com/embed',
					'cdninstagram.com',
					'instawidget.net',
					'plugins/instagram-feed/js/',
					'plugins/instagram-feed-pro/js/',
				),
				'cookies'  => array(),
			),

			/* ── Disqus ───────────────────────────────────── */
			'disqus' => array(
				'label'    => 'Disqus',
				'category' => 'functional',
				'patterns' => array(
					'disqus.com',
					'disqus.com/embed.js',
				),
				'cookies'  => array( 'disqus_unique' ),
			),

			/* ── PayPal ───────────────────────────────────── */
			'paypal' => array(
				'label'    => 'PayPal',
				'category' => 'functional',
				'patterns' => array(
					'www.paypal.com/tagmanager/pptm.js',
					'www.paypalobjects.com/api/checkout.js',
					'paypal.com/sdk/js',
				),
				'cookies'  => array(),
			),

			/* ── Spotify ──────────────────────────────────── */
			'spotify' => array(
				'label'    => 'Spotify Embed',
				'category' => 'functional',
				'patterns' => array(
					'open.spotify.com/embed',
				),
				'cookies'  => array(),
			),

			/* ── SoundCloud ───────────────────────────────── */
			'soundcloud' => array(
				'label'    => 'SoundCloud',
				'category' => 'functional',
				'patterns' => array(
					'w.soundcloud.com/player',
				),
				'cookies'  => array(),
			),

			/* ── Dailymotion ──────────────────────────────── */
			'dailymotion' => array(
				'label'    => 'Dailymotion',
				'category' => 'functional',
				'patterns' => array(
					'dailymotion.com/embed/video',
				),
				'cookies'  => array(),
			),

			/* ── Twitch ───────────────────────────────────── */
			'twitch' => array(
				'label'    => 'Twitch',
				'category' => 'functional',
				'patterns' => array(
					'player.twitch.tv',
					'embed.twitch.tv',
				),
				'cookies'  => array(),
			),

			/* ── AddThis ──────────────────────────────────── */
			'addthis' => array(
				'label'    => 'AddThis',
				'category' => 'marketing',
				'patterns' => array(
					'addthis.com',
					'addthis_widget.js',
					's7.addthis.com',
				),
				'cookies'  => array( '__atuvc', '__atuvs' ),
			),

			/* ── ShareThis ────────────────────────────────── */
			'sharethis' => array(
				'label'    => 'ShareThis',
				'category' => 'marketing',
				'patterns' => array(
					'sharethis.com',
					'platform-api.sharethis.com',
				),
				'cookies'  => array(),
			),

			/* ── LiveChat ─────────────────────────────────── */
			'livechat' => array(
				'label'    => 'LiveChat',
				'category' => 'functional',
				'patterns' => array(
					'cdn.livechatinc.com/tracking.js',
				),
				'cookies'  => array(),
			),

			/* ── Calendly ─────────────────────────────────── */
			'calendly' => array(
				'label'    => 'Calendly',
				'category' => 'functional',
				'patterns' => array(
					'assets.calendly.com',
				),
				'cookies'  => array(),
			),

			/* ── OpenStreetMap ─────────────────────────────── */
			'openstreetmaps' => array(
				'label'    => 'OpenStreetMap',
				'category' => 'functional',
				'patterns' => array(
					'openstreetmap.org',
					'osm/js/osm',
				),
				'cookies'  => array(),
			),

			/* ── Clicky Analytics ─────────────────────────── */
			'clicky' => array(
				'label'    => 'Clicky Analytics',
				'category' => 'analytics',
				'patterns' => array(
					'static.getclicky.com/js',
					'clicky_site_ids',
				),
				'cookies'  => array( '_jsuid', 'clicky_olark' ),
			),

			/* ── Yandex Metrica ────────────────────────────── */
			'yandex' => array(
				'label'    => 'Yandex Metrica',
				'category' => 'analytics',
				'patterns' => array(
					'mc.yandex.ru/metrika',
					'mc.yandex.ru/watch',
				),
				'cookies'  => array( '_ym_uid', '_ym_d', '_ym_isad' ),
			),

			/* ── PixelYourSite ─────────────────────────────── */
			'pixelyoursite' => array(
				'label'    => 'PixelYourSite',
				'category' => 'marketing',
				'patterns' => array(
					'pixelyoursite',
					'pys.js',
				),
				'cookies'  => array( 'pys_session_limit', 'pys_first_visit', 'pys_landing_page', 'last_pysTrafficSA' ),
			),

			/* ── Pixel Manager for WooCommerce ────────────── */
			'pixel-manager-woo' => array(
				'label'    => 'Pixel Manager for WooCommerce',
				'category' => 'marketing',
				'patterns' => array(
					'pixel-manager-pro-for-woocommerce',
					'pixel-manager-for-woocommerce',
					'wpmDataLayer',
				),
				'cookies'  => array(),
			),

			/* ── WP Statistics ─────────────────────────────── */
			'wp-statistics' => array(
				'label'    => 'WP Statistics',
				'category' => 'analytics',
				'patterns' => array(
					'wp-statistics/assets/js/',
					'wp_statistics_',
				),
				'cookies'  => array(),
			),

			/* ── Burst Statistics ──────────────────────────── */
			'burst-statistics' => array(
				'label'    => 'Burst Statistics',
				'category' => 'analytics',
				'patterns' => array(
					'burst-frontend',
					'burst-statistics',
					'burst_uid',
				),
				'cookies'  => array( 'burst_uid' ),
			),

			/* ── SlimStat Analytics ────────────────────────── */
			'slimstat' => array(
				'label'    => 'SlimStat Analytics',
				'category' => 'analytics',
				'patterns' => array(
					'wp-slimstat',
					'slimstat',
				),
				'cookies'  => array( 'slimstat_tracking_code' ),
			),

			/* ── Independent Analytics ─────────────────────── */
			'independent-analytics' => array(
				'label'    => 'Independent Analytics',
				'category' => 'analytics',
				'patterns' => array(
					'independent-analytics',
					'iawp-',
				),
				'cookies'  => array(),
			),

			/* ── Taboola ──────────────────────────────────── */
			'taboola' => array(
				'label'    => 'Taboola',
				'category' => 'marketing',
				'patterns' => array(
					'cdn.taboola.com',
					'trc.taboola.com',
					'_tfa.push',
				),
				'cookies'  => array( 't_gid', 'taboola_usg' ),
			),

			/* ── Outbrain ─────────────────────────────────── */
			'outbrain' => array(
				'label'    => 'Outbrain',
				'category' => 'marketing',
				'patterns' => array(
					'widgets.outbrain.com',
					'outbrain.com/outbrain.js',
				),
				'cookies'  => array(),
			),

			/* ── Intercom ─────────────────────────────────── */
			'intercom' => array(
				'label'    => 'Intercom',
				'category' => 'functional',
				'patterns' => array(
					'widget.intercom.io',
					'js.intercomcdn.com',
					'Intercom(',
				),
				'cookies'  => array( 'intercom-session-*', 'intercom-id-*' ),
			),

			/* ── Drift ────────────────────────────────────── */
			'drift' => array(
				'label'    => 'Drift',
				'category' => 'functional',
				'patterns' => array(
					'js.driftt.com',
					'drift.com',
				),
				'cookies'  => array( 'driftt_aid' ),
			),

			/* ── Crisp ────────────────────────────────────── */
			'crisp' => array(
				'label'    => 'Crisp Chat',
				'category' => 'functional',
				'patterns' => array(
					'client.crisp.chat',
				),
				'cookies'  => array( 'crisp-client/*' ),
			),

			/* ── Tidio ────────────────────────────────────── */
			'tidio' => array(
				'label'    => 'Tidio Chat',
				'category' => 'functional',
				'patterns' => array(
					'code.tidio.co',
				),
				'cookies'  => array(),
			),

			/* ── Optimizely ───────────────────────────────── */
			'optimizely' => array(
				'label'    => 'Optimizely',
				'category' => 'analytics',
				'patterns' => array(
					'cdn.optimizely.com',
					'optimizely.com/js/',
				),
				'cookies'  => array( 'optimizelyEndUserId', 'optimizelySegments' ),
			),

			/* ── Lucky Orange ─────────────────────────────── */
			'lucky-orange' => array(
				'label'    => 'Lucky Orange',
				'category' => 'analytics',
				'patterns' => array(
					'luckyorange.com',
					'd10lpsik1i8c69.cloudfront.net',
				),
				'cookies'  => array( '_lo_uid', '_lo_v' ),
			),

			/* ── Mouseflow ────────────────────────────────── */
			'mouseflow' => array(
				'label'    => 'Mouseflow',
				'category' => 'analytics',
				'patterns' => array(
					'cdn.mouseflow.com',
					'mouseflow.com/projects/',
				),
				'cookies'  => array( 'mf_user', 'mf_*' ),
			),

			/* ── Crazy Egg ────────────────────────────────── */
			'crazy-egg' => array(
				'label'    => 'Crazy Egg',
				'category' => 'analytics',
				'patterns' => array(
					'script.crazyegg.com/pages/',
				),
				'cookies'  => array( 'is_returning' ),
			),

			/* ── Freshchat ────────────────────────────────── */
			'freshchat' => array(
				'label'    => 'Freshchat',
				'category' => 'functional',
				'patterns' => array(
					'wchat.freshchat.com',
				),
				'cookies'  => array(),
			),

			/* ── Zendesk ──────────────────────────────────── */
			'zendesk' => array(
				'label'    => 'Zendesk',
				'category' => 'functional',
				'patterns' => array(
					'static.zdassets.com',
					'zopim.com',
				),
				'cookies'  => array(),
			),

			/* ── Stripe ───────────────────────────────────── */
			'stripe' => array(
				'label'    => 'Stripe',
				'category' => 'functional',
				'patterns' => array(
					'js.stripe.com',
					'm.stripe.network',
				),
				'cookies'  => array( '__stripe_mid', '__stripe_sid' ),
			),
		);
	}

	/**
	 * Build a flat map of cookie-name patterns → category slugs.
	 *
	 * Used by cookie shredding to decide which cookies to delete
	 * when their category has not been consented.
	 *
	 * @return array [ '_fbp' => 'marketing', '_ga' => 'analytics', ... ]
	 */
	public static function get_cookie_map() {
		$map = array();
		foreach ( self::get_all() as $service ) {
			if ( empty( $service['cookies'] ) ) {
				continue;
			}
			foreach ( $service['cookies'] as $cookie_pattern ) {
				$map[ $cookie_pattern ] = $service['category'];
			}
		}
		return $map;
	}

	/**
	 * Get all URL/inline patterns mapped to category.
	 *
	 * @return array [ 'connect.facebook.net' => 'marketing', ... ]
	 */
	public static function get_pattern_map() {
		$map = array();
		foreach ( self::get_all() as $service ) {
			foreach ( $service['patterns'] as $pattern ) {
				if ( ! isset( $map[ $pattern ] ) ) {
					$map[ $pattern ] = $service['category'];
				}
			}
		}
		return $map;
	}
}
