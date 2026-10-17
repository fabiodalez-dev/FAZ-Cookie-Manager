<?php
/**
 * Plugin Name: FAZ E2E Page-Optimiser Lab
 * Description: Test-only. Lets the LiteSpeed Cache and FlyingPress optimisation specs drive both optimisers on the nginx test site, observe their per-request cache decisions, capture PHP diagnostics, and (for falsifiability runs) strip FAZ Cookie Manager's own optimiser protections. Never shipped — lives under tests/e2e/fixtures.
 * Version: 0.1.0
 *
 * Every switch below is an option, so a spec turns behaviour on and off with
 * `wp option update` and nothing here acts on a site that has not asked for it:
 *
 *   faz_e2e_ls_guest_optm = 'yes'
 *       Defines LITESPEED_GUEST_OPTM before LiteSpeed Cache reads it. On a
 *       non-LiteSpeed server LSCWP's page optimiser bypasses every request
 *       (Control::is_cacheable() is false because nothing marks the request
 *       cacheable); Guest Optimisation is the one path that skips that check.
 *       LSCWP then also forces js_defer=2 and asynchronous CSS.
 *
 *   faz_e2e_ls_mark_cacheable = 'yes'
 *       Fires LSCWP's own `litespeed_control_set_cacheable` action on `wp`,
 *       which is what a LiteSpeed server does for it. The optimiser then runs
 *       with the configured js_defer (0/1/2) instead of the forced Guest value,
 *       and a `litespeed_control_set_nocache` issued by FAZ still wins, because
 *       Control::is_cacheable() requires the not-cacheable bit to be clear.
 *
 *   faz_e2e_break_faz_exclusions = 'yes'
 *       FALSIFIABILITY SWITCH. Removes every LiteSpeed / FlyingPress exclusion
 *       callback FAZ registers, undoes the in-memory FlyingPress delay-exclusion
 *       injection, and strips the data-no-defer / data-no-optimize /
 *       data-no-minify / data-cfasync / data-ao-skip / data-noptimize hints
 *       from FAZ's own tags. With it on, the "FAZ handle stays live" specs must
 *       go red; if they do not, they are not testing anything.
 *
 *   faz_e2e_optm_probe_page = '<post_name>'
 *       On that singular page, prints external probes in the footer: a Google
 *       tag script, a Meta pixel <img> and a YouTube <iframe>. FAZ's output
 *       buffer blocks or parks all three before consent. A same-origin <img>
 *       and <iframe> are printed next to them as positive controls for the
 *       optimisers' lazy-load passes.
 *
 *   faz_e2e_force_country_dependent = 'yes'
 *       Forces FAZ's `faz_country_dependent_banner_output` filter true, so the
 *       country-variant cache veto can be exercised without a geo database.
 *
 *   faz_e2e_no_country_source = 'yes'
 *       Makes the install one with NO way to resolve a visitor country: the
 *       Cloudflare header is untrusted (beating the dev fake-CF mu-plugin) and
 *       `faz_has_country_signal_source` answers false. That is the state of
 *       most shared hosting, and the one in which, with Cache Compatibility
 *       Mode at its default (off), FAZ's country veto is silent and the page is
 *       left to the page cache — so it is the state the consent-cache tests
 *       must run in.
 *
 *   faz_e2e_fp_preload_only = '<JSON array of URLs>'
 *       Narrows FlyingPress's preload crawl to these URLs, so
 *       `wp flying-press preload-cache` warms only the page under test instead
 *       of crawling the whole install while the spec waits.
 *
 *   faz_e2e_capture_errors = 'yes'
 *       Routes this request's PHP error log into WP_CONTENT_DIR /
 *       faz-e2e-optm-php.log (the test site runs with WP_DEBUG_LOG off), so a
 *       spec can assert FAZ emitted no warning or notice.
 *
 * A request carrying `X-Faz-E2E-Probe: <token>` additionally records the final
 * FlyingPress `flying_press_is_cacheable` verdict and LiteSpeed's not-cacheable
 * bit into the option `faz_e2e_optm_probe_<token>`, because both decisions are
 * taken at output-buffer time, after the response headers have left.
 *
 * @package FazCookie\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a fixture switch is on.
 *
 * @param string $name Option name.
 * @return bool
 */
function faz_e2e_optm_flag( $name ) {
	return 'yes' === get_option( $name, 'no' );
}

/* -------------------------------------------------------------------------
 * LiteSpeed Cache drivers.
 * ---------------------------------------------------------------------- */

if ( faz_e2e_optm_flag( 'faz_e2e_ls_guest_optm' ) && ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	if ( ! defined( 'LITESPEED_GUEST_OPTM' ) ) {
		define( 'LITESPEED_GUEST_OPTM', true );
	}
}

if ( faz_e2e_optm_flag( 'faz_e2e_ls_mark_cacheable' ) ) {
	add_action(
		'wp',
		static function () {
			if ( is_admin() ) {
				return;
			}
			// LSCWP registers this action on every server type (src/api.cls.php);
			// only the automatic `wp` hook that would call it is LiteSpeed-only.
			do_action( 'litespeed_control_set_cacheable', 'FAZ E2E page-optimiser lab' );
		},
		6
	);
}

/* -------------------------------------------------------------------------
 * Country-dependent output switch.
 * ---------------------------------------------------------------------- */

if ( faz_e2e_optm_flag( 'faz_e2e_force_country_dependent' ) ) {
	add_filter( 'faz_country_dependent_banner_output', '__return_true', PHP_INT_MAX );
}

/* -------------------------------------------------------------------------
 * "No country source" switch.
 * ---------------------------------------------------------------------- */

if ( faz_e2e_optm_flag( 'faz_e2e_no_country_source' ) ) {
	add_filter( 'faz_trust_cf_ipcountry_header', '__return_false', PHP_INT_MAX );
	add_filter( 'faz_trust_geoip_country_code', '__return_false', PHP_INT_MAX );
	add_filter( 'faz_has_country_signal_source', '__return_false', PHP_INT_MAX );
}

/* -------------------------------------------------------------------------
 * FlyingPress preload narrowing.
 * ---------------------------------------------------------------------- */

add_filter(
	'flying_press_preload_urls',
	static function ( $urls ) {
		$raw = get_option( 'faz_e2e_fp_preload_only', '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return $urls;
		}
		$only = json_decode( $raw, true );
		if ( ! is_array( $only ) ) {
			return $urls;
		}
		$normalise = static function ( $url ) {
			return untrailingslashit( strtolower( (string) $url ) );
		};
		$wanted = array_map( $normalise, $only );
		return array_values(
			array_filter(
				(array) $urls,
				static function ( $url ) use ( $wanted, $normalise ) {
					return in_array( $normalise( $url ), $wanted, true );
				}
			)
		);
	},
	PHP_INT_MAX
);

/* -------------------------------------------------------------------------
 * External probes on the page under test.
 * ---------------------------------------------------------------------- */

add_action(
	'wp_footer',
	static function () {
		$slug = (string) get_option( 'faz_e2e_optm_probe_page', '' );
		if ( '' === $slug || ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || $slug !== $post->post_name ) {
			return;
		}
		// Real third-party hosts on purpose: FlyingPress self-hosts only
		// external files and LiteSpeed localizes only listed external domains,
		// so a same-origin stand-in would make those optimisations inert.
		echo '<script src="https://www.googletagmanager.com/gtag/js?id=G-FAZE2E0001" async data-faz-e2e-probe="gtag"></script>' . "\n";
		echo '<img src="https://www.facebook.com/tr?id=1234567890&amp;ev=PageView&amp;noscript=1" width="1" height="1" alt="" data-faz-e2e-probe="fbpixel">' . "\n";
		echo '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315" title="FAZ E2E YouTube" data-faz-e2e-probe="youtube"></iframe>' . "\n";
		// Positive controls: same-origin, never blocked by FAZ, so an optimiser
		// that lazy-loads media at all visibly rewrites these two. Without them
		// a "the parked embed was not resurrected" assertion would pass just as
		// well on a run where lazy-loading never happened.
		echo '<img src="' . esc_url( includes_url( 'images/w-logo-blue.png' ) ) . '" width="80" height="80" alt="" data-faz-e2e-probe="control-img">' . "\n";
		echo '<iframe src="' . esc_url( includes_url( 'images/blank.gif' ) ) . '" width="10" height="10" title="FAZ E2E control frame" data-faz-e2e-probe="control-iframe"></iframe>' . "\n";
	},
	6
);

/* -------------------------------------------------------------------------
 * Cache-decision probe.
 * ---------------------------------------------------------------------- */

/**
 * The probe token of the current request, or '' when none was sent.
 *
 * @return string
 */
function faz_e2e_optm_probe_token() {
	if ( empty( $_SERVER['HTTP_X_FAZ_E2E_PROBE'] ) ) {
		return '';
	}
	$token = strtolower( (string) wp_unslash( $_SERVER['HTTP_X_FAZ_E2E_PROBE'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	return 1 === preg_match( '/^[a-z0-9-]{1,40}$/', $token ) ? $token : '';
}

/**
 * Merge values into this request's probe record.
 *
 * @param array $values Values to record.
 * @return void
 */
function faz_e2e_optm_probe_record( array $values ) {
	$token = faz_e2e_optm_probe_token();
	if ( '' === $token ) {
		return;
	}
	$name    = 'faz_e2e_optm_probe_' . $token;
	$current = get_option( $name, array() );
	if ( ! is_array( $current ) ) {
		$current = array();
	}
	update_option( $name, array_merge( $current, $values ), false );
}

if ( '' !== faz_e2e_optm_probe_token() ) {
	// Last word on FlyingPress's verdict: whatever every other callback
	// decided, this records it and passes it through unchanged.
	add_filter(
		'flying_press_is_cacheable',
		static function ( $is_cacheable ) {
			faz_e2e_optm_probe_record( array( 'fp' => $is_cacheable ? 'cacheable' : 'vetoed' ) );
			return $is_cacheable;
		},
		PHP_INT_MAX
	);

	add_action(
		'shutdown',
		static function () {
			$record = array(
				'donotcachepage' => ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ? 'yes' : 'no',
			);
			if ( class_exists( '\LiteSpeed\Control' ) && method_exists( '\LiteSpeed\Control', 'isset_notcacheable' ) ) {
				$record['ls'] = \LiteSpeed\Control::isset_notcacheable() ? 'no-cache' : 'cacheable';
			} else {
				$record['ls'] = 'absent';
			}
			faz_e2e_optm_probe_record( $record );
		},
		PHP_INT_MAX
	);
}

/* -------------------------------------------------------------------------
 * PHP diagnostics capture.
 * ---------------------------------------------------------------------- */

if ( faz_e2e_optm_flag( 'faz_e2e_capture_errors' ) ) {
	// phpcs:disable WordPress.PHP.IniSet.Risky
	ini_set( 'log_errors', '1' );
	ini_set( 'error_log', WP_CONTENT_DIR . '/faz-e2e-optm-php.log' );
	// phpcs:enable
	// Canary: a probed request writes one known line, so a spec reading an
	// empty log can tell "nothing was logged" from "the redirect did not take"
	// (an FPM pool with php_admin_value[error_log] silently ignores ini_set).
	if ( '' !== faz_e2e_optm_probe_token() ) {
		error_log( 'FAZ-E2E-OPTM-CANARY ' . faz_e2e_optm_probe_token() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

/* -------------------------------------------------------------------------
 * Falsifiability switch: strip FAZ's own optimiser protections.
 * ---------------------------------------------------------------------- */

/**
 * Whether a script/style handle belongs to FAZ Cookie Manager.
 *
 * @param string $handle Registered handle, or a rendered tag id.
 * @return bool
 */
function faz_e2e_optm_is_faz_handle( $handle ) {
	$handle = (string) $handle;
	return 'faz-cookie-manager' === $handle
		|| 0 === strpos( $handle, 'faz-cookie-manager-' )
		|| 'faz-fw' === $handle
		|| 0 === strpos( $handle, 'faz-fw-' );
}

/**
 * Remove the FAZ Frontend callbacks named in $methods from $hook.
 *
 * The Frontend instance is not stored anywhere reachable, so the callbacks are
 * found by walking the hook's table and matching the bound object's class.
 *
 * @param string   $hook    Hook name.
 * @param string[] $methods Method names to remove.
 * @return void
 */
function faz_e2e_optm_remove_faz_callbacks( $hook, array $methods ) {
	global $wp_filter;
	if ( ! isset( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof WP_Hook ) ) {
		return;
	}
	$table = $wp_filter[ $hook ]->callbacks;
	foreach ( $table as $priority => $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$function = $callback['function'];
			if (
				is_array( $function )
				&& isset( $function[0], $function[1] )
				&& is_object( $function[0] )
				&& is_a( $function[0], 'FazCookie\Frontend\Frontend' )
				&& in_array( $function[1], $methods, true )
			) {
				remove_filter( $hook, $function, $priority );
			}
		}
	}
}

/**
 * Remove every optimiser exclusion FAZ registered.
 *
 * @return void
 */
function faz_e2e_optm_strip_faz_exclusions() {
	$map = array(
		'script_loader_tag'                     => array( 'tag_own_scripts_nooptimize' ),
		'wp_inline_script_attributes'           => array( 'tag_own_inline_attributes_nooptimize' ),
		'style_loader_tag'                      => array( 'tag_own_styles_nooptimize' ),
		'litespeed_optm_js_defer_exc'           => array( 'litespeed_exclude_own_scripts' ),
		'litespeed_optm_js_delay_inc'           => array( 'litespeed_exclude_own_scripts_from_include' ),
		'litespeed_optimize_js_excludes'        => array( 'litespeed_exclude_own_scripts' ),
		'litespeed_optimize_css_excludes'       => array( 'litespeed_exclude_own_scripts' ),
		'litespeed_optm_gm_js_exc'              => array( 'litespeed_exclude_own_scripts' ),
		'flying_press_exclude_from_delay:js'    => array( 'flying_press_exclude_own_scripts' ),
		'flying_press_exclude_from_defer:js'    => array( 'flying_press_exclude_own_scripts' ),
		'flying_press_exclude_from_minify:js'   => array( 'flying_press_exclude_own_scripts' ),
		'flying_press_exclude_from_minify:css'  => array( 'flying_press_exclude_own_scripts' ),
		// The restore net undoes whatever FlyingPress still rewrote, so a
		// stripped run would look clean without this: remove it too.
		'flying_press_optimization:after'       => array( 'flying_press_restore_own_assets' ),
		'plugins_loaded'                        => array( 'flying_press_apply_runtime_delay_exclusions' ),
		'wp'                                    => array( 'flying_press_apply_runtime_delay_exclusions' ),
		'flying_press_update_config:after'      => array( 'flying_press_apply_runtime_delay_exclusions' ),
	);
	foreach ( $map as $hook => $methods ) {
		faz_e2e_optm_remove_faz_callbacks( $hook, $methods );
	}
}

/**
 * Put FlyingPress's in-memory delay excludes back to the stored value, undoing
 * any injection that ran before the callbacks were removed.
 *
 * @return void
 */
function faz_e2e_optm_reset_flying_press_runtime_excludes() {
	if ( ! class_exists( '\FlyingPress\Config' ) || ! property_exists( '\FlyingPress\Config', 'config' ) ) {
		return;
	}
	try {
		$property = new ReflectionProperty( '\FlyingPress\Config', 'config' );
		if ( ! $property->isStatic() ) {
			return;
		}
		if ( ! $property->isPublic() ) {
			$property->setAccessible( true );
		}
		$config = $property->getValue();
		if ( ! is_array( $config ) || ! array_key_exists( 'js_delay_excludes', $config ) ) {
			return;
		}
		$stored = get_option( 'FLYING_PRESS_CONFIG', array() );
		$config['js_delay_excludes'] = ( is_array( $stored ) && isset( $stored['js_delay_excludes'] ) && is_array( $stored['js_delay_excludes'] ) )
			? $stored['js_delay_excludes']
			: array();
		$property->setValue( null, $config );
	} catch ( Throwable $error ) {
		unset( $error );
	}
}

/**
 * Remove the cache-optimiser hint attributes from a rendered tag blob.
 *
 * @param string $tag Rendered markup.
 * @return string
 */
function faz_e2e_optm_strip_hint_attributes( $tag ) {
	$stripped = preg_replace(
		'/\s+data-(?:no-defer|no-optimize|no-minify|ao-skip|noptimize|cfasync)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?/i',
		'',
		(string) $tag
	);
	return is_string( $stripped ) ? $stripped : $tag;
}

if ( faz_e2e_optm_flag( 'faz_e2e_break_faz_exclusions' ) ) {
	// FAZ (directory faz-cookie-manager) loads before this fixture
	// (faz-e2e-…) in the alphabetical active_plugins order, so its
	// constructor has already registered everything. Strip now and again at
	// the very start of plugins_loaded, before FAZ's PHP_INT_MAX callback.
	faz_e2e_optm_strip_faz_exclusions();
	add_action( 'plugins_loaded', 'faz_e2e_optm_strip_faz_exclusions', 0 );
	add_action( 'wp', 'faz_e2e_optm_strip_faz_exclusions', 0 );
	add_action( 'wp', 'faz_e2e_optm_reset_flying_press_runtime_excludes', 1 );

	add_filter(
		'script_loader_tag',
		static function ( $tag, $handle ) {
			return faz_e2e_optm_is_faz_handle( $handle ) ? faz_e2e_optm_strip_hint_attributes( $tag ) : $tag;
		},
		PHP_INT_MAX,
		2
	);
	add_filter(
		'style_loader_tag',
		static function ( $tag, $handle ) {
			return faz_e2e_optm_is_faz_handle( $handle ) ? faz_e2e_optm_strip_hint_attributes( $tag ) : $tag;
		},
		PHP_INT_MAX,
		2
	);
	add_filter(
		'wp_inline_script_attributes',
		static function ( $attributes ) {
			if ( ! is_array( $attributes ) || empty( $attributes['id'] ) || ! faz_e2e_optm_is_faz_handle( (string) $attributes['id'] ) ) {
				return $attributes;
			}
			foreach ( array( 'data-no-defer', 'data-no-optimize', 'data-no-minify', 'data-cfasync', 'data-ao-skip', 'data-noptimize' ) as $key ) {
				unset( $attributes[ $key ] );
			}
			return $attributes;
		},
		PHP_INT_MAX
	);
}
