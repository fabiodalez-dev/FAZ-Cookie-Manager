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
					'_getTracker(',
					'ga.create',
					"ga('create'",
					"ga('send'",
					'__gaTracker',
					'GoogleAnalyticsObject',
					'_gaq',
					'ga-disable-',
					'mi_track_user',
					'mi_version',
					'monsterinsights',
					'monsterinsights_frontend',
					'monsterinsights-frontend-script',
					'monsterinsights-vue-script',
					'google-analytics-for-wordpress/assets/js/',
					'google-analytics-premium/pro/assets/',
					'exactmetrics',
					'exactmetrics_frontend',
					'exactmetrics-frontend-script',
					'google-analytics-dashboard-for-wp/assets/js/',
					'analytify',
					'analytify-gtag',
					'gainwp',
					'gainwp-tracking-code',
					'aiwpOptions',
					'aiwp-tracking-code',
					'jeep-google-analytics',
					'ht-easy-ga4',
					'ht-easy-ga4-gtag',
					'ga-google-analytics',
					'beehive-analytics',
					'conversios',
					'enhanced-e-commerce-for-woocommerce',
					'google-analytics-wd',
					'google_gtagjs',
					'woocommerce-google-analytics-integration',
					'caos-analytics',
					'gadwp-tracking-analytics-events',
					'gadwp-pagescrolldepth-tracking',
					'ga_events_frontend_bundle',
					'ga_events_main_script',
					'ga-external-tracking',
					'googleanalytics_get_script',
					'analytics-insights',
					'wpac-integration-for-google-analytics',
					'wpac-integration-google-analytics',
				),
				'cookies'  => array( '_ga', '_ga_*', '_gid', '_gat', '_gat_*', '__utma', '__utmb', '__utmc', '__utmz', '__utmt' ),
			),

			/* ── Google Tag Manager ───────────────────────── */
			'google-tag-manager' => array(
				'label'    => 'Google Tag Manager',
				'category' => 'analytics',
				'patterns' => array(
					'googletagmanager.com/gtm.js',
					'googletagmanager.com/gtm',
					'googletagmanager.com/ns.html',
					'gtm.start',
					'gtm4wp',
					'gtmkit',
					'gtm4wp-',
					'zeeker-gtm',
					'dataLayer.push({',
					'google_tag_manager',
					'easy-google-tag-manager',
					'google-tag-manager-integration-for-woocommerce',
					'server-side-tracking-via-gtm',
					'conversion-pixel-and-tracking-tag-manager',
					'conversion-pixel-tracking-tag-manager',
					'tracking-and-consent-manager',
					'wp-full-picture',
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
					'google_gtagjs-js',
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
					'pixel-caffeine/build/frontend.js',
					'facebook-for-woocommerce',
					'facebook-for-wordpress',
					'wc-facebook-pixel-events',
					'wc-facebook-pixel-event-placeholder',
					'fatcatapps-pixel',
					'official-facebook-pixel',
					'meta-pixel-for-wordpress',
					'meta-for-woocommerce',
					'meta-pixel-event-tracker-for-woocommerce',
					'all-in-one-capi',
					'all-in-one-capi-for-meta-pinterest-gtm',
					'kliken',
					'analytics.sitewit.com',
					'weapi.kliken.com',
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
					'ttq.page(',
					'tiktok-events',
					'add-tiktok-pixel-for-tiktok-ads',
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
					'ct.pinterest.com',
					'pintrk(',
					'pintrk (',
					'pinterest-for-woocommerce',
					'add-pinterest-conversion-tags',
					'pinmarklet.js',
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
					't.co/i/adsct',
					'analytics.twitter.com',
				),
				'cookies'  => array( 'personalization_id', 'guest_id', 'muc_ads' ),
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
					'tr.snapchat.com',
				),
				'cookies'  => array( '_scid', '_scid_r', 'sc_at' ),
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
					'uetTag',
				),
				'cookies'  => array( '_uetsid', '_uetsid_exp', '_uetvid', '_uetvid_exp', 'MUID' ),
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
					'hs-script-loader',
				),
				'cookies'  => array( '__hstc', 'hubspotutk', '__hssc', '__hssrc', '__hs_opt_out' ),
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

			/* ── Google reCAPTCHA (v2 & v3) ──────────────── */
			'google-recaptcha' => array(
				'label'    => 'Google reCAPTCHA',
				'category' => 'necessary',
				'patterns' => array(
					'google.com/recaptcha',
					'gstatic.com/recaptcha',
					'grecaptcha',
					'recaptcha/api.js',
					'recaptcha/enterprise.js',
				),
				'cookies'  => array( '_GRECAPTCHA' ),
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
					'disquscdn.com',
					'.disqus.com/embed.js',
				),
				'cookies'  => array( 'disqus_unique', '__jid' ),
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
				'label'    => 'Spotify',
				'category' => 'functional',
				'patterns' => array(
					'open.spotify.com/embed',
					'embed.spotify.com',
				),
				'cookies'  => array( 'sp_t', 'sp_landing' ),
			),

			/* ── SoundCloud ───────────────────────────────── */
			'soundcloud' => array(
				'label'    => 'SoundCloud',
				'category' => 'functional',
				'patterns' => array(
					'w.soundcloud.com',
					'api.soundcloud.com',
					'soundcloud.com/player',
				),
				'cookies'  => array(),
			),

			/* ── Dailymotion ──────────────────────────────── */
			'dailymotion' => array(
				'label'    => 'Dailymotion',
				'category' => 'marketing',
				'patterns' => array(
					'dailymotion.com/embed',
					'geo.dailymotion.com',
					'api.dmcdn.net',
				),
				'cookies'  => array( 'dmvk', 'ts', 'v1st', 'usprivacy' ),
			),

			/* ── Twitch ───────────────────────────────────── */
			'twitch' => array(
				'label'    => 'Twitch',
				'category' => 'marketing',
				'patterns' => array(
					'player.twitch.tv',
					'embed.twitch.tv',
					'clips.twitch.tv',
				),
				'cookies'  => array( 'twitch.lohp.countryCode' ),
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
					'sharethis.com/js/',
					'count-server.sharethis.com',
				),
				'cookies'  => array( '__unam' ),
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
					'calendly.com/assets/external',
					'calendly.com/widget',
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
					'static.getclicky.com',
					'in.getclicky.com',
					'clicky_site_ids',
					'clicky.init(',
				),
				'cookies'  => array( '_jsuid', 'clicky_olark', '_eventqueue', '_referrer_og', '_utm_og' ),
			),

			/* ── Yandex Metrica ────────────────────────────── */
			'yandex' => array(
				'label'    => 'Yandex Metrica',
				'category' => 'analytics',
				'patterns' => array(
					'mc.yandex.ru',
					'metrika.yandex.ru',
					'cdn.jsdelivr.net/npm/yandex-metrica-watch',
					"ym(document",
					'Ya.Metrika',
				),
				'cookies'  => array( '_ym_uid', '_ym_d', '_ym_isad', '_ym_visorc', 'yandexuid', 'yabs-sid' ),
			),

			/* ── PixelYourSite ─────────────────────────────── */
			'pixelyoursite' => array(
				'label'    => 'PixelYourSite',
				'category' => 'marketing',
				'patterns' => array(
					'pixelyoursite',
					'pixelyoursite/dist',
					'pixelyoursite-pro/dist',
					'pys.js',
					'pys-js-extra',
					'pysOptions',
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
					'pixel-tag-manager-for-woocommerce',
					'woocommerce-conversion-tracking',
					'woocommerce-google-adwords-conversion-tracking-tag',
					'wpmDataLayer',
					'pmwDataLayer',
					'_pmwq',
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
					'burst-cookieless',
					'burst.min.js',
					'burst_uid',
					'burst-time-tracking-script',
					'burst-tracking-script',
					'window.burst',
				),
				'cookies'  => array( 'burst_uid', 'burst_*' ),
			),

			/* ── SlimStat Analytics ────────────────────────── */
			'slimstat' => array(
				'label'    => 'SlimStat Analytics',
				'category' => 'analytics',
				'patterns' => array(
					'wp-slimstat',
					'wp_slimstat',
					'slimstat',
					'SlimStatParams',
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
						'iawp-javascript',
						'iawp-layout-javascript',
						'window.IAWP',
					),
					'cookies'  => array(),
				),

				/* ── Header/Footer & snippet injectors ───────── */
				'script-injectors' => array(
					'label'    => 'Header/Footer and Snippet Injectors',
					'category' => 'functional',
					'patterns' => array(
						'wpcode',
						'insert-headers-and-footers',
						'header-footer-code-manager',
						'head-footer-code',
						'ht-script',
						'custom-css-js',
						'simple-custom-css-and-js',
						'code-snippets',
						'woody',
						'insert-php',
						'ad-inserter',
						'cm-header-footer-script-loader',
						'tag-manager-header-body-and-footer',
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
					'nr.taboola.com',
					'_tfa.push',
					'_taboola',
				),
				'cookies'  => array( 't_gid', 't_pt_gid', 'taboola_usg' ),
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
				'category' => 'marketing',
				'patterns' => array(
					'widget.intercom.io',
					'js.intercomcdn.com',
					'api-iam.intercom.io',
					'Intercom(',
					'intercomSettings',
				),
				'cookies'  => array( 'intercom-session-*', 'intercom-id-*' ),
			),

			/* ── Drift ────────────────────────────────────── */
			'drift' => array(
				'label'    => 'Drift',
				'category' => 'marketing',
				'patterns' => array(
					'js.driftt.com',
					'drift.com',
					'driftt.com',
				),
				'cookies'  => array( 'drift_aid', 'drift_aaid', 'driftt_aid' ),
			),

			/* ── Crisp ────────────────────────────────────── */
			'crisp' => array(
				'label'    => 'Crisp Chat',
				'category' => 'functional',
				'patterns' => array(
					'client.crisp.chat',
					'settings.crisp.chat',
					'$crisp',
					'CRISP_WEBSITE_ID',
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
					'o2.mouseflow.com',
					'mouseflow.com/projects/',
				),
				'cookies'  => array( 'mf_user', 'mf_*' ),
			),

			/* ── Crazy Egg ────────────────────────────────── */
			'crazy-egg' => array(
				'label'    => 'Crazy Egg',
				'category' => 'analytics',
				'patterns' => array(
					'script.crazyegg.com',
					'dnn506yrbagrg.cloudfront.net',
				),
				'cookies'  => array( 'is_returning', '_ceir', '_CEFT', 'cebs' ),
			),

			/* ── Freshchat / Freshdesk ────────────────────── */
			'freshchat' => array(
				'label'    => 'Freshdesk / Freshchat',
				'category' => 'functional',
				'patterns' => array(
					'wchat.freshchat.com',
					'snippets.freshdesk.com',
					'assets.freshdesk.com',
				),
				'cookies'  => array(),
			),

			/* ── Zendesk ──────────────────────────────────── */
			'zendesk' => array(
				'label'    => 'Zendesk',
				'category' => 'functional',
				'patterns' => array(
					'static.zdassets.com',
					'ekr.zdassets.com',
					'zendesk.com/embeddable',
					'zopim.com',
				),
				'cookies'  => array( '__zlcmid', '__zldp', '__zlcprivacy' ),
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

			/* ── Tawk.to ──────────────────────────────────── */
			'tawkto' => array(
				'label'    => 'Tawk.to',
				'category' => 'functional',
				'patterns' => array(
					'embed.tawk.to',
					'va.tawk.to',
					'Tawk_API',
					'Tawk_LoadStart',
				),
				'cookies'  => array( 'TawkConnectionTime', '__tawkuuid', 'tawk-*' ),
			),

			/* ── Olark ───────────────────────────────────── */
			'olark' => array(
				'label'    => 'Olark',
				'category' => 'functional',
				'patterns' => array(
					'static.olark.com',
					'olark.com/jw',
					'olark.identify',
				),
				'cookies'  => array( 'hblid', 'olfsk', 'wcsid', '_okglobalid_*' ),
			),

			/* ── Smartlook ───────────────────────────────── */
			'smartlook' => array(
				'label'    => 'Smartlook',
				'category' => 'analytics',
				'patterns' => array(
					'rec.smartlook.com',
					'web-sdk.smartlook.com',
					'smartlook(',
				),
				'cookies'  => array( 'SL_C_*', 'SL_L_*' ),
			),

			/* ── FullStory ───────────────────────────────── */
			'fullstory' => array(
				'label'    => 'FullStory',
				'category' => 'analytics',
				'patterns' => array(
					'fullstory.com/s/fs.js',
					'edge.fullstory.com',
					'rs.fullstory.com',
					'_fs_namespace',
					"FS.identify(",
				),
				'cookies'  => array( '_fs_uid', 'fs_uid' ),
			),

			/* ── LogRocket ───────────────────────────────── */
			'logrocket' => array(
				'label'    => 'LogRocket',
				'category' => 'analytics',
				'patterns' => array(
					'cdn.logrocket.io',
					'cdn.lr-ingest.io',
					'cdn.lr-in.com',
					'LogRocket.init(',
				),
				'cookies'  => array( '_lr_tabs_*', '_lr_env_*', 'LogRocket' ),
			),

			/* ── Heap Analytics ───────────────────────────── */
			'heap' => array(
				'label'    => 'Heap Analytics',
				'category' => 'analytics',
				'patterns' => array(
					'cdn.heapanalytics.com',
					'heapanalytics.com/js/',
					'heap.load(',
				),
				'cookies'  => array( '_hp2_id.*', '_hp2_ses_props.*' ),
			),

			/* ── Mixpanel ────────────────────────────────── */
			'mixpanel' => array(
				'label'    => 'Mixpanel',
				'category' => 'analytics',
				'patterns' => array(
					'cdn.mxpnl.com',
					'api-js.mixpanel.com',
					'api.mixpanel.com',
					'mixpanel.init(',
					'mixpanel.track(',
				),
				'cookies'  => array( 'mp_*_mixpanel' ),
			),

			/* ── Amplitude ───────────────────────────────── */
			'amplitude' => array(
				'label'    => 'Amplitude',
				'category' => 'analytics',
				'patterns' => array(
					'cdn.amplitude.com',
					'api.amplitude.com',
					'amplitude.getInstance(',
				),
				'cookies'  => array( 'amplitude_id_*' ),
			),

			/* ── Segment ─────────────────────────────────── */
			'segment' => array(
				'label'    => 'Segment',
				'category' => 'analytics',
				'patterns' => array(
					'cdn.segment.com/analytics.js',
					'cdn.segment.io',
					'api.segment.io',
					'analytics.load(',
					'analytics.track(',
				),
				'cookies'  => array( 'ajs_anonymous_id', 'ajs_user_id', 'ajs_group_id' ),
			),

			/* ── Plausible Analytics ──────────────────────── */
			'plausible' => array(
				'label'    => 'Plausible Analytics',
				'category' => 'analytics',
				'patterns' => array(
					'plausible.io/js/',
					'plausible.io/api/event',
					'plausible-analytics',
				),
				'cookies'  => array(),
			),

			/* ── Fathom Analytics ─────────────────────────── */
			'fathom' => array(
				'label'    => 'Fathom Analytics',
				'category' => 'analytics',
				'patterns' => array(
					'cdn.usefathom.com',
					'usefathom.com/script.js',
					'fathom-analytics',
				),
				'cookies'  => array(),
			),

			/* ── Jetpack Stats / WordPress.com Stats ──────── */
			'jetpack-stats' => array(
				'label'    => 'Jetpack Stats',
				'category' => 'analytics',
				'patterns' => array(
					'pixel.wp.com',
					'stats.wp.com',
					'jetpack_stats',
					'stats.wp.com/e-',
					'stats.wp.com/w.js',
				),
				'cookies'  => array( 'tk_ai', 'tk_qs', 'tk_or' ),
			),

			/* ── Google Optimize ──────────────────────────── */
			'google-optimize' => array(
				'label'    => 'Google Optimize',
				'category' => 'analytics',
				'patterns' => array(
					'googleoptimize.com/optimize.js',
					'google-optimize',
				),
				'cookies'  => array( '_gaexp', '_opt_awcid', '_opt_awmid', '_opt_awgid', '_opt_utmc' ),
			),

			/* ── VWO (Visual Website Optimizer) ───────────── */
			'vwo' => array(
				'label'    => 'VWO',
				'category' => 'analytics',
				'patterns' => array(
					'dev.visualwebsiteoptimizer.com',
					'wingify.com',
					'vwo_code',
					'VWO.push(',
				),
				'cookies'  => array( '_vis_opt_*', '_vwo_uuid*', '_vwo_ds', '_vwo_sn' ),
			),

			/* ── Convert.com ─────────────────────────────── */
			'convert' => array(
				'label'    => 'Convert',
				'category' => 'analytics',
				'patterns' => array(
					'cdn-3.convertexperiments.com',
					'convert.com/js/',
				),
				'cookies'  => array( '_conv_v', '_conv_s', '_conv_r' ),
			),

			/* ── Pendo ───────────────────────────────────── */
			'pendo' => array(
				'label'    => 'Pendo',
				'category' => 'analytics',
				'patterns' => array(
					'cdn.pendo.io',
					'pendo.io/agent/',
					'pendo.initialize(',
				),
				'cookies'  => array( '_pendo_*' ),
			),

			/* ── OptinMonster ────────────────────────────── */
			'optinmonster' => array(
				'label'    => 'OptinMonster',
				'category' => 'marketing',
				'patterns' => array(
					'a.optinmonster.com',
					'optinmonster',
					'api.opmnstr.com',
					'optinmonster-',
				),
				'cookies'  => array( 'om-*' ),
			),

			/* ── Sumo ────────────────────────────────────── */
			'sumo' => array(
				'label'    => 'Sumo',
				'category' => 'marketing',
				'patterns' => array(
					'sumo.com/sumo.js',
					'load.sumo.com',
					'load.sumome.com',
				),
				'cookies'  => array( '__smToken', '__smListBuilderToken' ),
			),

			/* ── Hello Bar ───────────────────────────────── */
			'hellobar' => array(
				'label'    => 'Hello Bar',
				'category' => 'marketing',
				'patterns' => array(
					'my.hellobar.com',
					'cdn.hellobar.com',
				),
				'cookies'  => array(),
			),

			/* ── AddToAny ────────────────────────────────── */
			'addtoany' => array(
				'label'    => 'AddToAny',
				'category' => 'functional',
				'patterns' => array(
					'static.addtoany.com',
					'addtoany.com/menu',
					'a2a_config',
					'addtoany-core',
					'addtoany-jquery',
				),
				'cookies'  => array(),
			),

			/* ── Mailchimp for WP ────────────────────────── */
			'mailchimp' => array(
				'label'    => 'Mailchimp',
				'category' => 'marketing',
				'patterns' => array(
					'chimpstatic.com',
					'list-manage.com',
					'mc.us',
					'mailchimp-for-wp',
					'mc4wp',
				),
				'cookies'  => array(),
			),

			/* ── Gravatar ────────────────────────────────── */
			'gravatar' => array(
				'label'    => 'Gravatar',
				'category' => 'functional',
				'patterns' => array(
					'gravatar.com/avatar',
					'secure.gravatar.com',
					's.gravatar.com',
				),
				'cookies'  => array(),
			),

			/* ── Google AdSense ──────────────────────────── */
			'google-adsense' => array(
				'label'    => 'Google AdSense',
				'category' => 'marketing',
				'patterns' => array(
					'pagead2.googlesyndication.com/pagead/js/adsbygoogle.js',
					'adsbygoogle',
					'adsbygoogle.push(',
					'google_ad_client',
				),
				'cookies'  => array(),
			),

			/* ── Amazon Associates / Affiliate ───────────── */
			'amazon' => array(
				'label'    => 'Amazon',
				'category' => 'marketing',
				'patterns' => array(
					'amazon-adsystem.com',
					'assoc-amazon.com',
					'rcm-na.amazon-adsystem.com',
					'ws-na.amazon-adsystem.com',
				),
				'cookies'  => array(),
			),

			/* ── Cloudflare Web Analytics ────────────────── */
			'cloudflare-analytics' => array(
				'label'    => 'Cloudflare Web Analytics',
				'category' => 'analytics',
				'patterns' => array(
					'static.cloudflareinsights.com/beacon.min.js',
					'cloudflareinsights.com',
				),
				'cookies'  => array(),
			),

			/* ── New Relic ───────────────────────────────── */
			'newrelic' => array(
				'label'    => 'New Relic',
				'category' => 'analytics',
				'patterns' => array(
					'js-agent.newrelic.com',
					'bam.nr-data.net',
					'NREUM',
					'newrelic.info',
				),
				'cookies'  => array(),
			),

			/* ── Sentry ──────────────────────────────────── */
			'sentry' => array(
				'label'    => 'Sentry',
				'category' => 'functional',
				'patterns' => array(
					'browser.sentry-cdn.com',
					'sentry.io/api/',
					'Sentry.init(',
				),
				'cookies'  => array(),
			),

			/* ── Cookiebot (competitor) ──────────────────── */
			'cookiebot' => array(
				'label'    => 'Cookiebot',
				'category' => 'functional',
				'patterns' => array(
					'consent.cookiebot.com',
					'consentcdn.cookiebot.com',
				),
				'cookies'  => array( 'CookieConsent', 'CookieConsentBulkTicket' ),
			),

			/* ── WooCommerce built-in analytics ──────────── */
			'woocommerce' => array(
				'label'    => 'WooCommerce',
				'category' => 'analytics',
				'patterns' => array(
					'wc-tracks',
					'woocommerce-google-analytics-integration',
					'wcTracks',
				),
				'cookies'  => array(),
			),

			/* ── Trustpilot ──────────────────────────────── */
			'trustpilot' => array(
				'label'    => 'Trustpilot',
				'category' => 'marketing',
				'patterns' => array(
					'widget.trustpilot.com',
					'invitejs.trustpilot.com',
					'tp.srtk.net',
				),
				'cookies'  => array(),
			),

			/* ── Typeform ────────────────────────────────── */
			'typeform' => array(
				'label'    => 'Typeform',
				'category' => 'functional',
				'patterns' => array(
					'embed.typeform.com',
					'renderer.typeform.com',
				),
				'cookies'  => array(),
			),

			/* ── JivoChat ────────────────────────────────── */
			'jivochat' => array(
				'label'    => 'JivoChat',
				'category' => 'functional',
				'patterns' => array(
					'code.jivosite.com',
					'jivosite.com/widget',
				),
				'cookies'  => array(),
			),

			/* ── Usercentrics (competitor) ────────────────── */
			'usercentrics' => array(
				'label'    => 'Usercentrics',
				'category' => 'functional',
				'patterns' => array(
					'app.usercentrics.eu',
					'usercentrics.eu/sdk',
				),
				'cookies'  => array( 'uc_settings', 'uc_user_interaction' ),
			),

			/* ── OneTrust (competitor) ────────────────────── */
			'onetrust' => array(
				'label'    => 'OneTrust',
				'category' => 'functional',
				'patterns' => array(
					'cdn.cookielaw.org',
					'optanon.blob.core.windows.net',
					'cookie-cdn.cookiepro.com',
					'OptanonConsent',
				),
				'cookies'  => array( 'OptanonConsent', 'OptanonAlertBoxClosed' ),
			),

			/* ── Matomo Tag Manager ──────────────────────── */
			'matomo-tag-manager' => array(
				'label'    => 'Matomo Tag Manager',
				'category' => 'analytics',
				'patterns' => array(
					'cdn.matomo.cloud/container_',
					'MatomoTagManager',
				),
				'cookies'  => array(),
			),

			/* ── Reddit Pixel ────────────────────────────── */
			'reddit' => array(
				'label'    => 'Reddit Pixel',
				'category' => 'marketing',
				'patterns' => array(
					'www.redditstatic.com/ads/',
					'rdt(',
					'alb.reddit.com',
				),
				'cookies'  => array( '_rdt_uuid' ),
			),

			/* ── Quora Pixel ─────────────────────────────── */
			'quora' => array(
				'label'    => 'Quora Pixel',
				'category' => 'marketing',
				'patterns' => array(
					'a.quora.com/qevents.js',
					'qp(',
				),
				'cookies'  => array(),
			),

			/* ── Pardot / Salesforce ─────────────────────── */
			'pardot' => array(
				'label'    => 'Pardot (Salesforce)',
				'category' => 'marketing',
				'patterns' => array(
					'pi.pardot.com',
					'pardot.com/pd.js',
					'go.pardot.com',
				),
				'cookies'  => array( 'pardot', 'visitor_id*-hash' ),
			),

			/* ── Marketo ─────────────────────────────────── */
			'marketo' => array(
				'label'    => 'Marketo',
				'category' => 'marketing',
				'patterns' => array(
					'munchkin.marketo.net',
					'Munchkin.init(',
				),
				'cookies'  => array( '_mkto_trk' ),
			),

			/* ── ActiveCampaign ──────────────────────────── */
			'activecampaign' => array(
				'label'    => 'ActiveCampaign',
				'category' => 'marketing',
				'patterns' => array(
					'trackcmp.net',
					'actcampaign.com',
					'activehosted.com/f/embed.php',
				),
				'cookies'  => array(),
			),

			/* ── Klaviyo ─────────────────────────────────── */
			'klaviyo' => array(
				'label'    => 'Klaviyo',
				'category' => 'marketing',
				'patterns' => array(
					'static.klaviyo.com',
					'a.klaviyo.com',
					'klaviyo.js',
					'_learnq.push(',
				),
				'cookies'  => array( '__kla_id' ),
			),

			/* ── Elementor (external embeds) ─────────────── */
			'elementor' => array(
				'label'    => 'Elementor',
				'category' => 'functional',
				'patterns' => array(
					'elementor/assets/lib/share-link',
				),
				'cookies'  => array(),
			),

			/* ── WPForms ─────────────────────────────────── */
			'wpforms' => array(
				'label'    => 'WPForms',
				'category' => 'necessary',
				'patterns' => array(
					'wpforms-recaptcha',
					'wpforms-hcaptcha',
				),
				'cookies'  => array(),
			),

			/* ── hCaptcha ────────────────────────────────── */
			'hcaptcha' => array(
				'label'    => 'hCaptcha',
				'category' => 'necessary',
				'patterns' => array(
					'hcaptcha.com/1/api.js',
					'js.hcaptcha.com',
				),
				'cookies'  => array(),
			),

			/* ── Cloudflare Turnstile ────────────────────── */
			'cloudflare-turnstile' => array(
				'label'    => 'Cloudflare Turnstile',
				'category' => 'necessary',
				'patterns' => array(
					'challenges.cloudflare.com/turnstile',
				),
				'cookies'  => array( 'cf_clearance' ),
			),

			/* ── Wordfence ───────────────────────────────── */
			'wordfence' => array(
				'label'    => 'Wordfence',
				'category' => 'necessary',
				'patterns' => array(
					'wordfence/assets/js/',
					'wfLogHumanRan',
				),
				'cookies'  => array( 'wfvt_*' ),
			),

			/* ── VideoPress ──────────────────────────────── */
			'videopress' => array(
				'label'    => 'VideoPress',
				'category' => 'functional',
				'patterns' => array(
					'videopress.com/embed',
					'videopress.com/videopress-iframe.js',
					'video.wordpress.com',
				),
				'cookies'  => array(),
			),

			/* ── SpeakerDeck ─────────────────────────────── */
			'speakerdeck' => array(
				'label'    => 'SpeakerDeck',
				'category' => 'functional',
				'patterns' => array(
					'speakerdeck.com',
				),
				'cookies'  => array(),
			),

			/* ── SlideShare ──────────────────────────────── */
			'slideshare' => array(
				'label'    => 'SlideShare',
				'category' => 'functional',
				'patterns' => array(
					'slideshare.net',
				),
				'cookies'  => array(),
			),

			/* ── Mixcloud ────────────────────────────────── */
			'mixcloud' => array(
				'label'    => 'Mixcloud',
				'category' => 'functional',
				'patterns' => array(
					'mixcloud.com/widget/',
				),
				'cookies'  => array(),
			),

			/* ── Issuu ───────────────────────────────────── */
			'issuu' => array(
				'label'    => 'Issuu',
				'category' => 'functional',
				'patterns' => array(
					'e.issuu.com',
				),
				'cookies'  => array(),
			),

			/* ── Imgur ───────────────────────────────────── */
			'imgur' => array(
				'label'    => 'Imgur',
				'category' => 'functional',
				'patterns' => array(
					'imgur.com/a/',
					'imgur.com/gallery/',
					's.imgur.com',
				),
				'cookies'  => array(),
			),

			/* ── TED ─────────────────────────────────────── */
			'ted' => array(
				'label'    => 'TED',
				'category' => 'functional',
				'patterns' => array(
					'embed.ted.com',
				),
				'cookies'  => array(),
			),

			/* ── Kickstarter ─────────────────────────────── */
			'kickstarter' => array(
				'label'    => 'Kickstarter',
				'category' => 'functional',
				'patterns' => array(
					'kickstarter.com',
				),
				'cookies'  => array(),
			),

			/* ── Screencast ──────────────────────────────── */
			'screencast' => array(
				'label'    => 'Screencast',
				'category' => 'functional',
				'patterns' => array(
					'screencast.com',
				),
				'cookies'  => array(),
			),

			/* ── Animoto ─────────────────────────────────── */
			'animoto' => array(
				'label'    => 'Animoto',
				'category' => 'functional',
				'patterns' => array(
					'animoto.com',
				),
				'cookies'  => array(),
			),

			/* ── Cloudup ──────────────────────────────────── */
			'cloudup' => array(
				'label'    => 'Cloudup',
				'category' => 'functional',
				'patterns' => array(
					'cloudup.com',
				),
				'cookies'  => array(),
			),

			/* ── ReverbNation ────────────────────────────── */
			'reverbnation' => array(
				'label'    => 'ReverbNation',
				'category' => 'functional',
				'patterns' => array(
					'reverbnation.com',
				),
				'cookies'  => array(),
			),

			/* ── Embedly ─────────────────────────────────── */
			'embedly' => array(
				'label'    => 'Embedly',
				'category' => 'functional',
				'patterns' => array(
					'embedly.com',
					'embed.ly',
					'cdn.embedly.com',
				),
				'cookies'  => array(),
			),

			/* ── Mautic ──────────────────────────────────── */
			'mautic' => array(
				'label'    => 'Mautic',
				'category' => 'marketing',
				'patterns' => array(
					'MauticTrackingObject',
					'mautic.js',
					'mtracking.gif',
				),
				'cookies'  => array( 'mtc_id', 'mtc_sid', 'mautic_device_id' ),
			),

			/* ── WooCommerce Order Attribution ────────────── */
			'woocommerce-attribution' => array(
				'label'    => 'WooCommerce Order Attribution',
				'category' => 'analytics',
				'patterns' => array(
					'wc-order-attribution',
					'sourcebuster-js',
				),
				'cookies'  => array( 'sbjs_*' ),
			),

			/* ── Pixel Caffeine ──────────────────────────── */
			'pixel-caffeine' => array(
				'label'    => 'Pixel Caffeine',
				'category' => 'marketing',
				'patterns' => array(
					'aepc-pixel-events',
					'aepc_pixel',
				),
				'cookies'  => array(),
			),

			/* ── Simple Share Buttons ────────────────────── */
			'simple-share-buttons' => array(
				'label'    => 'Simple Share Buttons',
				'category' => 'marketing',
				'patterns' => array(
					'ssba-sharethis',
				),
				'cookies'  => array(),
			),

			/* ── HubSpot LeadIn ──────────────────────────── */
			'hubspot-leadin' => array(
				'label'    => 'HubSpot LeadIn',
				'category' => 'marketing',
				'patterns' => array(
					'leadin-script-loader-js',
					'leadin-scriptloader-js',
					'hs-script-loader',
				),
				'cookies'  => array(),
			),

			/* ── Custom Facebook Feed ────────────────────── */
			'custom-facebook-feed' => array(
				'label'    => 'Custom Facebook Feed',
				'category' => 'marketing',
				'patterns' => array(
					'cffscripts',
					'cfflinkhashtags',
					'custom-facebook-feed',
				),
				'cookies'  => array(),
			),

			/* ── Smash Balloon Instagram Feed ────────────── */
			'smash-balloon-instagram' => array(
				'label'    => 'Smash Balloon Instagram Feed',
				'category' => 'marketing',
				'patterns' => array(
					'sb_instagram_scripts',
					'sb-instagram',
				),
				'cookies'  => array(),
			),

			/* ── Ninja Forms reCAPTCHA ───────────────────── */
			'ninja-forms-recaptcha' => array(
				'label'    => 'Ninja Forms reCAPTCHA',
				'category' => 'necessary',
				'patterns' => array(
					'nf-google-recaptcha',
				),
				'cookies'  => array(),
			),

			/* ── Tumblr ──────────────────────────────────── */
			'tumblr' => array(
				'label'    => 'Tumblr',
				'category' => 'marketing',
				'patterns' => array(
					'assets.tumblr.com',
					'tumblr.com/share',
				),
				'cookies'  => array(),
			),

			/* ── Polldaddy / Crowdsignal ─────────────────── */
			'polldaddy' => array(
				'label'    => 'Crowdsignal (Polldaddy)',
				'category' => 'functional',
				'patterns' => array(
					'polldaddy.com',
					'crowdsignal.com',
					'survey.fm',
				),
				'cookies'  => array(),
			),

			/* ── Baidu Analytics ─────────────────────────── */
			'baidu_analytics' => array(
				'label'    => 'Baidu Analytics',
				'category' => 'analytics',
				'patterns' => array(
					'hm.baidu.com',
					'tongji.baidu.com',
				),
				'cookies'  => array( 'Hm_lvt_*', 'Hm_lpvt_*', 'BAIDUID' ),
			),

			/* ── Customer.io ─────────────────────────────── */
			'customerio' => array(
				'label'    => 'Customer.io',
				'category' => 'marketing',
				'patterns' => array(
					'assets.customer.io',
					'track.customer.io',
					'customerioforms.com',
				),
				'cookies'  => array( '_cio', '_cioanonid' ),
			),

			/* ── Drip ────────────────────────────────────── */
			'drip' => array(
				'label'    => 'Drip',
				'category' => 'marketing',
				'patterns' => array(
					'tag.getdrip.com',
					'api.getdrip.com',
				),
				'cookies'  => array( '_drip_client_*' ),
			),

			/* ── ConvertKit ──────────────────────────────── */
			'convertkit' => array(
				'label'    => 'ConvertKit',
				'category' => 'marketing',
				'patterns' => array(
					'f.convertkit.com',
					'convertkit.com/js',
					'app.convertkit.com',
				),
				'cookies'  => array( 'ck_subscriber_id', '_ck_visitor' ),
			),

			/* ── Zoho (SalesIQ, Forms, etc.) ─────────────── */
			'zoho' => array(
				'label'    => 'Zoho',
				'category' => 'marketing',
				'patterns' => array(
					'salesiq.zoho.com',
					'js.zohostatic.com',
					'css.zohostatic.com',
					'zoho.com/salesiq',
				),
				'cookies'  => array( 'zsiqchat', 'zia_*' ),
			),

			/* ── WP Recipe Maker ─────────────────────────── */
			'wp_recipe_maker' => array(
				'label'    => 'WP Recipe Maker',
				'category' => 'functional',
				'patterns' => array(
					'wp-recipe-maker/dist/',
					'wprm-recipe-',
				),
				'cookies'  => array(),
			),

			/* ── Wistia ──────────────────────────────────── */
			'wistia' => array(
				'label'    => 'Wistia',
				'category' => 'marketing',
				'patterns' => array(
					'fast.wistia.com',
					'fast.wistia.net',
					'embedwistia-a.akamaihd.net',
				),
				'cookies'  => array( 'wistia' ),
			),

			/* ── Awin / Zanox ────────────────────────────── */
			'awin' => array(
				'label'    => 'Awin (Zanox)',
				'category' => 'marketing',
				'patterns' => array(
					'dwin1.com',
					'dwin2.com',
					'awin1.com',
				),
				'cookies'  => array( 'aw*' ),
			),

			/* ── CJ Affiliate (Commission Junction) ──────── */
			'cj_affiliate' => array(
				'label'    => 'CJ Affiliate',
				'category' => 'marketing',
				'patterns' => array(
					'emjcd.com',
					'ftjcfx.com',
					'tqlkg.com',
					'anrdoezrs.net',
					'dpbolvw.net',
					'jdoqocy.com',
					'kqzyfj.com',
				),
				'cookies'  => array(),
			),

			/* ── Skimlinks ───────────────────────────────── */
			'skimlinks' => array(
				'label'    => 'Skimlinks',
				'category' => 'marketing',
				'patterns' => array(
					's.skimresources.com',
					'r.skimresources.com',
				),
				'cookies'  => array(),
			),

			/* ── Google Publisher Tag (GPT / DFP ads) ────── */
			'google_gpt' => array(
				'label'    => 'Google Publisher Tag (GPT)',
				'category' => 'marketing',
				'patterns' => array(
					'securepubads.g.doubleclick.net',
					'pagead2.googlesyndication.com/tag',
					'googletag.cmd.push',
					'googletag.enableServices',
				),
				'cookies'  => array(),
			),

			/* ── Quantcast ───────────────────────────────── */
			'quantcast' => array(
				'label'    => 'Quantcast',
				'category' => 'marketing',
				'patterns' => array(
					'quantserve.com',
					'quantcount.com',
					'edge.quantserve.com',
				),
				'cookies'  => array( '__qca', 'mc' ),
			),

			/* ── Pixel Manager for WooCommerce ───────────── */
			'pixel_manager_woo' => array(
				'label'    => 'Pixel Manager for WooCommerce',
				'category' => 'marketing',
				'patterns' => array(
					'wpm-frontend',
					'window.wpm',
					'wpmDataLayer',
					'pixel-tag-manager-for-woocommerce',
					'woocommerce-conversion-tracking',
				),
				'cookies'  => array(),
			),

			/* ── Kissmetrics ─────────────────────────────── */
			'kissmetrics' => array(
				'label'    => 'Kissmetrics',
				'category' => 'analytics',
				'patterns' => array(
					'i.kissmetrics.com',
					'_kmq',
					'scripts.kissmetrics.com',
				),
				'cookies'  => array( 'km_ai', 'km_vs', 'km_ni' ),
			),

			/* ── Criteo ──────────────────────────────────── */
			'criteo' => array(
				'label'    => 'Criteo',
				'category' => 'marketing',
				'patterns' => array(
					'static.criteo.net',
					'dynamic.criteo.com',
					'dis.criteo.com',
					'sslwidget.criteo.com',
					'criteo.com/js/',
					'criteo_q',
					'Criteo.events.push',
				),
				'cookies'  => array( 'cto_bundle', 'cto_bidid', 'cto_tld_test', 'cto_writetest', 'cto_lwid', 'criteo_write_test' ),
			),

			/* ── Adobe Analytics (Omniture) ──────────────── */
			'adobe-analytics' => array(
				'label'    => 'Adobe Analytics',
				'category' => 'analytics',
				'patterns' => array(
					'adobedtm.com',
					'omtrdc.net',
					'demdex.net',
					'2o7.net',
					'assets.adobedtm.com',
					'launch-',
					's_code.js',
					'AppMeasurement.js',
					'AppMeasurement',
					's.t()',
				),
				'cookies'  => array( 's_cc', 's_sq', 's_vi', 's_fid', 'AMCV_*', 'AMCVS_*', 's_ecid', 'demdex', 'dpm' ),
			),

			/* ── PostHog ─────────────────────────────────── */
			'posthog' => array(
				'label'    => 'PostHog',
				'category' => 'analytics',
				'patterns' => array(
					'app.posthog.com',
					'us.posthog.com',
					'eu.posthog.com',
					'posthog-js',
					'posthog.init(',
				),
				'cookies'  => array( 'ph_*' ),
			),

			/* ── Contentsquare ───────────────────────────── */
			'contentsquare' => array(
				'label'    => 'Contentsquare',
				'category' => 'analytics',
				'patterns' => array(
					't.contentsquare.net',
					'contentsquare.com',
					'contentsquare.net/tag',
					'_uxa.push',
				),
				'cookies'  => array( '_cs_c', '_cs_id', '_cs_s', '_cs_mk', '_cs_same_site' ),
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
