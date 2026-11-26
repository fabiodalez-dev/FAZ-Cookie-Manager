<?php
/**
 * Standalone unit tests: the Advanced Consent Mode gtag exemption does not
 * cover another tracker written in the same inline <script>.
 *
 * Subsystem: gcm-advanced-bundled-tracker-php
 *
 * Advanced Consent Mode (#165) leaves an inline gtag bootstrap running before
 * consent: `gtag('config', …)` behind the denied default sends cookieless
 * pings. The exemption is decided per <script> and covers its whole body, so a
 * header-script box holding the gtag snippet AND a Meta Pixel init in one tag
 * had the pixel's inline code run before consent too. #385 closed that for the
 * Tag Manager container only, and against the built-in catalogue only.
 *
 * Pinned here, on a first visit with Analytics and Marketing blocked, Advanced
 * mode on and the consent default emitted:
 *   1. a body that is only the gtag bootstrap keeps its exemption;
 *   2. the same body next to another tracker waits for consent — whether that
 *      tracker is known from the catalogue (per-service map) or only from the
 *      cookie database, an admin custom rule or the `faz_blocking_rules`
 *      filter (provider map);
 *   3. patterns that name the Google tag itself (an admin `gtag(` rule, a tag
 *      ID, googletagmanager.com) do not cancel the exemption;
 *   4. a gtag.js loaded by src is judged by its URL, not by a body it never
 *      runs;
 *   5. both layers agree: the output buffer and the inline-script filter;
 *   6. the Tag Manager container (#385) gets the same, wider test;
 *   7. "names the Google tag" is a delimited test, not a substring one: a
 *      custom `gtagTracker(` rule, a server-side container on another domain
 *      and a look-alike host are other trackers.
 *
 * Run: php tests/unit/test-gcm-advanced-bundled-tracker-php.php
 *  or: bash scripts/run-unit-tests.sh
 *
 * @package FazCookie\Tests\Unit
 */

namespace FazCookie\Includes {

	class Known_Providers {
		public static function get_all() {
			return array();
		}
		public static function get_cookie_map() {
			return array();
		}
		public static function get_pattern_map() {
			return array();
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	function wp_strip_all_tags( $str ) {
		return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) );
	}
	function sanitize_text_field( $str ) {
		return trim( wp_strip_all_tags( preg_replace( '/[\r\n\t ]+/', ' ', (string) $str ) ) );
	}
	function wp_unslash( $value ) {
		return $value;
	}
	function esc_attr( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
	function esc_url( $value ) {
		return str_replace( '&', '&amp;', (string) $value );
	}
	function apply_filters( $tag, $value ) {
		return $value;
	}
	function wp_parse_url( $url, $component = -1 ) {
		$url = (string) $url;
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'placeholder:' . $url;
		}
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component ); // phpcs:ignore
	}
	function get_transient( $key ) {
		return false;
	}
	function set_transient( $key, $value, $ttl = 0 ) {
		return true;
	}
	function absint( $v ) {
		return abs( (int) $v );
	}
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
	function faz_sanitize_bool( $v ) {
		return is_string( $v ) ? in_array( strtolower( $v ), array( '1', 'true', 'yes', 'on' ), true ) : (bool) $v;
	}
	function is_admin() {
		return false;
	}
	function faz_disable_banner() {
		return false;
	}
	function get_the_ID() {
		return 1;
	}

	class FazTest_BundleGcm {
		public $gtm = false;
		public function is_advanced_mode() {
			return true;
		}
		public function is_advanced_gtm_mode() {
			return (bool) $this->gtm;
		}
		public function get( $key ) {
			return null;
		}
	}
	class FazTest_BundleSettings {
		public function get( $group = '', $key = '' ) {
			return 'status' === $key ? true : array();
		}
	}

	$root = dirname( __DIR__, 2 );
	require_once $root . '/frontend/includes/class-embed-inventory.php';
	require_once $root . '/frontend/class-frontend.php';

	use FazCookie\Frontend\Frontend;

	$run  = 0;
	$fail = 0;
	function b_ok( $cond, $label ) {
		global $run, $fail;
		++$run;
		if ( $cond ) {
			echo "  PASS {$label}\n";
			return;
		}
		++$fail;
		echo "  FAIL {$label}\n";
	}

	/**
	 * First visit under an opt-in law, Advanced mode on, default emitted.
	 *
	 * The per-service map holds what the catalogue knows; the provider map
	 * additionally holds what only the cookie database, an admin custom rule
	 * or the developer filter contribute — those have no service id.
	 */
	function b_frontend( $gtm = false ) {
		$rc  = new ReflectionClass( Frontend::class );
		$fe  = $rc->newInstanceWithoutConstructor();
		$gcm = new FazTest_BundleGcm();
		$gcm->gtm = $gtm;
		$set = array(
			'gcm_settings'              => $gcm,
			'settings'                  => new FazTest_BundleSettings(),
			'template'                  => array( 'html' => '<div></div>' ),
			'plugin_name'               => 'faz-cookie-manager',
			'gcm_default_emitted'       => true,
			'blocked_categories_cache'  => array( 'analytics', 'marketing' ),
			'provider_map_cache'        => array(
				// Catalogue (also in the per-service map below).
				'googletagmanager.com/gtag/js' => 'analytics',
				'googletagmanager.com/gtm.js'  => 'analytics',
				"gtag('config'"                => 'analytics',
				'connect.facebook.net'         => 'marketing',
				'fbq('                         => 'marketing',
				// Cookie database: a third-party cookie domain.
				'pixel.adnetwork.example'      => 'marketing',
				// Admin custom rules (Settings → Script Blocking).
				'acmeTrack('                   => 'marketing',
				'GTM-ABC123'                   => 'analytics',
				'gtag('                        => 'analytics',
				'G-TEST1234'                   => 'analytics',
				// faz_blocking_rules filter.
				'_paq.push('                   => 'analytics',
				'stats.g.doubleclick.net'      => 'marketing',
			),
			'pattern_service_cache'     => array(
				'googletagmanager.com/gtag/js' => array( 'google-analytics' ),
				'googletagmanager.com/gtm.js'  => array( 'google-tag-manager' ),
				"gtag('config'"                => array( 'google-analytics' ),
				'connect.facebook.net'         => array( 'meta-pixel' ),
				'fbq('                         => array( 'meta-pixel' ),
			),
			'service_consent_cache'     => array(),
			'whitelist_cache'           => array(),
			'settings_option_cache'     => array(),
			'provider_match_meta_cache' => null,
			'service_match_meta_cache'  => null,
		);
		// Per-case additions: extra rules, declared rows, a stored svc.* choice.
		$over = isset( $GLOBALS['b_over'] ) ? $GLOBALS['b_over'] : array();
		foreach ( array( 'provider_map_cache', 'pattern_service_cache', 'service_consent_cache' ) as $prop ) {
			if ( isset( $over[ $prop ] ) ) {
				$set[ $prop ] = array_merge( $set[ $prop ], $over[ $prop ] );
			}
		}
		$set['enforceable_cache'] = isset( $over['enforceable_cache'] ) ? $over['enforceable_cache'] : array();
		foreach ( $set as $prop => $value ) {
			$p = $rc->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $fe, $value );
		}
		return $fe;
	}
	function b_call( $fe, $method, ...$args ) {
		$m = new ReflectionMethod( Frontend::class, $method );
		$m->setAccessible( true );
		return $m->invoke( $fe, ...$args );
	}
	/** Whether the tag survives the output buffer byte for byte. */
	function b_buffer_live( $tag, $gtm = false ) {
		$out = b_call( b_frontend( $gtm ), 'process_output_buffer', '<html><head>' . $tag . '</head><body></body></html>' );
		return false !== strpos( $out, $tag );
	}
	/** Whether the inline-script filter leaves the body executable. */
	function b_filter_live( $body, $gtm = false ) {
		return false === b_call( b_frontend( $gtm ), 'inline_script_block_category', '', $body, '', '' );
	}

	$gtag = "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-TEST1234');";
	$dq   = 'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config","G-TEST1234");';

	echo "the gtag bootstrap on its own keeps the exemption\n";
	b_ok( b_buffer_live( '<script>' . $gtag . '</script>' ), 'output buffer: single-quoted bootstrap is left running' );
	b_ok( b_buffer_live( '<script>' . $dq . '</script>' ), 'output buffer: double-quoted bootstrap is left running' );
	b_ok( b_filter_live( $gtag ), 'inline-script filter: the bootstrap is left running' );
	b_ok( b_buffer_live( '<script>' . $gtag . "gtag('event','conversion',{send_to:'AW-123456789/abc'});</script>" ), 'a bootstrap with a Google Ads conversion event is still only Google' );
	b_ok( b_buffer_live( '<script>var s=document.createElement("script");s.src="https://www.googletagmanager.com/gtag/js?id=G-TEST1234";document.head.appendChild(s);' . $gtag . '</script>' ), 'an inline gtag.js loader with its bootstrap is still only Google' );

	echo "\nthe bootstrap next to another tracker waits for consent\n";
	$bundles = array(
		'a Meta Pixel init (catalogue code signature)'            => "fbq('init','123');fbq('track','PageView');",
		'the Meta Pixel loader (catalogue URL)'                   => '!function(f,b,e,v,n,t,s){t=b.createElement(e);t.src="https://connect.facebook.net/en_US/fbevents.js";}(window,document,"script");',
		'a tracker known only from an admin custom rule'          => 'acmeTrack("pageview",{id:42});',
		'a tracker known only from the faz_blocking_rules filter' => 'var _paq=window._paq=window._paq||[];_paq.push(["trackPageView"]);',
		'a host known only from the cookie database'              => 'new Image().src="https://pixel.adnetwork.example/p.gif?u="+encodeURIComponent(location.href);',
	);
	foreach ( $bundles as $what => $other ) {
		b_ok( ! b_buffer_live( '<script>' . $gtag . $other . '</script>' ), 'output buffer: bootstrap + ' . $what );
		b_ok( ! b_buffer_live( '<script>' . $other . $dq . '</script>' ), 'output buffer: ' . $what . ' + bootstrap (order does not matter)' );
		b_ok( ! b_filter_live( $gtag . $other ), 'inline-script filter: bootstrap + ' . $what );
	}
	$out = b_call( b_frontend(), 'process_output_buffer', '<html><head><script>' . $gtag . "fbq('init','123');</script></head><body></body></html>" );
	b_ok( 1 === preg_match( '/<script[^>]*type="text\/plain"[^>]*>/', $out ) && false !== strpos( $out, 'data-faz-category="' ), 'the bundled script is parked with a category, so consent releases it' );

	echo "\npatterns that name the Google tag itself do not cancel the exemption\n";
	b_ok( b_buffer_live( '<script>' . $gtag . '</script>' ), 'an admin `gtag(` rule and a tag-ID rule match the bootstrap, and it still runs' );
	b_ok( b_buffer_live( '<script>' . $gtag . 'var u="https://stats.g.doubleclick.net/dc.js";</script>' ), 'a Consent-Mode-managed Google host named in the body is not another tracker' );

	echo "\na script with a src is judged by its URL\n";
	$loader = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST1234"></script>';
	b_ok( b_buffer_live( $loader ), 'the gtag.js loader keeps its exemption' );
	$fb = '<script async src="https://connect.facebook.net/en_US/fbevents.js"></script>';
	b_ok( ! b_buffer_live( $fb ), 'a non-Google tracker by src stays blocked' );

	echo "\nthe Tag Manager container (#385) gets the wider test too\n";
	$gtm_body = '(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!="dataLayer"?"&l="+l:"";j.async=true;j.src="https://www.googletagmanager.com/gtm.js?id="+i+dl;f.parentNode.insertBefore(j,f);})(window,document,"script","dataLayer","GTM-ABC123");';
	b_ok( b_buffer_live( '<script>' . $gtm_body . '</script>', true ), 'the container snippet on its own is left running' );
	b_ok( ! b_buffer_live( '<script>' . $gtm_body . "fbq('init','1');</script>", true ), 'container + catalogue tracker waits (as before)' );
	b_ok( ! b_buffer_live( '<script>' . $gtm_body . 'acmeTrack("pageview");</script>', true ), 'container + a tracker known only from a custom rule waits' );
	b_ok( ! b_buffer_live( '<script>' . $gtm_body . '_paq.push(["trackPageView"]);</script>', true ), 'container + a tracker known only from the filter waits' );

	echo "\n`names the Google tag` is a delimited test\n";
	$google_patterns = array(
		'gtag('                                    => true,
		'gtag'                                     => true,
		"gtag('config'"                            => true,
		'window.gtag('                             => true,
		'dataLayer'                                => true,
		'window.dataLayer'                         => true,
		'dataLayer.push({'                         => true,
		'dataLayer.push('                          => true,
		'gtm.start'                                => true,
		'gtm.js'                                   => true,
		'gtag/js'                                  => true,
		'G-TEST1234'                               => true,
		'GTM-ABC123'                               => true,
		'AW-123456789'                             => true,
		'googletagmanager.com'                     => true,
		'googletagmanager.com/gtm.js'              => true,
		'https://www.googletagmanager.com/gtag/js' => true,
		'//www.google-analytics.com/analytics.js'  => true,
		'.doubleclick.net'                         => true,
		'stats.g.doubleclick.net'                  => true,
		'gtagTracker('                             => false,
		'mygtag('                                  => false,
		'gtag_report('                             => false,
		'dataLayerX.push('                         => false,
		'datalayer.tracker.example'                => false,
		'gtag.tracker.example/t.js'                => false,
		'sgtm.tracker.example/gtm.js'              => false,
		'sgtm.tracker.example/gtag/js'             => false,
		'googletagmanager.com.evil.example'        => false,
		'notgoogletagmanager.com'                  => false,
		'notdoubleclick.net/x.js'                  => false,
		'tracker.example/p?u=googletagmanager.com' => false,
		'tracker.example/doubleclick.net/x.js'     => false,
		'acmeTrack('                               => false,
	);
	foreach ( $google_patterns as $pattern => $expected ) {
		b_ok( $expected === b_call( b_frontend(), 'is_consent_mode_google_pattern', $pattern ), '`' . $pattern . '` ' . ( $expected ? 'names the Google tag' : 'is another tracker' ) );
	}
	$GLOBALS['b_over'] = array(
		'provider_map_cache' => array(
			'gtagTracker('                      => 'marketing',
			'sgtm.tracker.example/gtm.js'       => 'marketing',
			'googletagmanager.com.evil.example' => 'marketing',
		),
	);
	b_ok( b_buffer_live( '<script>' . $gtag . '</script>' ), 'with those three rules in place the bootstrap on its own still runs' );
	b_ok( b_filter_live( $gtag ), 'inline-script filter: and so it does there' );
	$lookalikes = array(
		'a custom `gtagTracker(` rule'                 => "gtagTracker('pageview');",
		'a server-side container on another domain'    => 'var s=document.createElement("script");s.src="https://sgtm.tracker.example/gtm.js?id=X";document.head.appendChild(s);',
		'a host that only starts with a Google domain' => 'new Image().src="https://googletagmanager.com.evil.example/p.gif";',
	);
	foreach ( $lookalikes as $what => $other ) {
		b_ok( ! b_buffer_live( '<script>' . $gtag . $other . '</script>' ), 'output buffer: bootstrap + ' . $what . ' waits' );
		b_ok( ! b_filter_live( $gtag . $other ), 'inline-script filter: bootstrap + ' . $what . ' waits' );
	}
	b_ok( ! b_buffer_live( "<script>gtag('config','G-TEST1234');gtagTracker('pageview');</script>" ), 'the reported body, gtag config + gtagTracker, waits' );
	b_ok( ! b_buffer_live( '<script>' . $gtm_body . "gtagTracker('pageview');</script>", true ), 'container + `gtagTracker(` waits' );

	$GLOBALS['b_over'] = array();

	echo "\nadmin help\n";
	$view = (string) file_get_contents( $root . '/admin/views/gcm.php' );
	b_ok( false !== strpos( $view, 'Keep the Google tag snippet in a script of its own' ), 'the Advanced Consent Mode help says a shared script keeps waiting' );

	echo "\n";
	if ( 0 === $fail ) {
		echo "ALL PASS — {$run}/{$run}\n";
		exit( 0 );
	}
	echo "{$fail} FAILED — " . ( $run - $fail ) . "/{$run} passed\n";
	exit( 1 );
}
