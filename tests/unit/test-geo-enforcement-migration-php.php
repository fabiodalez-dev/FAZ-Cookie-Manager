<?php
/**
 * Standalone unit tests for Activator::preserve_geo_enforcement_on_upgrade().
 *
 * Geo_Runtime::is_enabled() used to read `return true` — jurisdiction ruleset
 * enforcement was unconditional, so it was live on every install including the
 * (default) majority that never ticked Settings → Geolocation → Geo-Targeting.
 * The UI gate makes that toggle govern enforcement as well, which is the right
 * fix for two wordpress.org cache reports, but on its own it silently REMOVES
 * enforcement from every one of those installs on upgrade: with no ruleset
 * resolved, Frontend::apply_blocked_categories() falls through to the banner's
 * own applicableLaw, and a banner stored as `ccpa` — which is also how the
 * combined "GDPR + US" banner is stored — blocks nothing before consent, for
 * every visitor, EEA included.
 *
 * This suite pins the migration that keeps that from happening, and in
 * particular the two things that make it safe rather than merely effective:
 *
 *   - it must not fire on a FRESH install. New sites now receive enforcement
 *     from Settings::get_defaults(); the upgrade migration must still leave an
 *     explicitly stored fresh-install value untouched;
 *   - promoting geo_targeting is display-neutral ONLY while default_behavior is
 *     not `no_banner`, because is_geo_banner_disabled() hides the banner when
 *     all three of geo_targeting / outside target_regions / no_banner hold. A
 *     dormant `no_banner` (dormant because the method returns false at its first
 *     check while geo_targeting is off) must therefore be normalised in the SAME
 *     write, or the upgrade starts hiding the banner outside eu/uk on a site
 *     that previously showed it to everyone.
 *
 * The last case drives the real run_pending_migrations() batch, so removing the
 * migration from that list turns this suite red.
 *
 * Run from project root:
 *   php tests/unit/test-geo-enforcement-migration-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {
	/**
	 * Permissive stand-in for everything the migration batch reaches for that is
	 * not the subject of this suite. Mirrors test-migration-cache-bust-php.php.
	 */
	class Faz_Geo_Mig_Stub {
		public function __call( $name, $args ) { return array(); }
		public static function __callStatic( $name, $args ) { return array(); }
		public static function get_instance() { return new static(); }
	}
}

namespace {

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'FAZ_VERSION', '9.9.9' );

	$GLOBALS['faz_geo_options'] = array();
	$GLOBALS['faz_geo_actions'] = array();

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['faz_geo_options'] )
			? $GLOBALS['faz_geo_options'][ $name ]
			: $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['faz_geo_options'][ $name ] = $value;
		return true;
	}
	function add_option( $name, $value, $deprecated = '', $autoload = null ) {
		if ( array_key_exists( $name, $GLOBALS['faz_geo_options'] ) ) {
			return false;
		}
		$GLOBALS['faz_geo_options'][ $name ] = $value;
		return true;
	}
	function delete_option( $name ) {
		unset( $GLOBALS['faz_geo_options'][ $name ] );
		return true;
	}
	function do_action( $tag ) {
		$GLOBALS['faz_geo_actions'][] = $tag;
	}
	function apply_filters( $tag, $value ) { return $value; }
	function wp_cache_delete( $key, $group = '' ) { return true; }
	function wp_set_option_autoload_values( $values ) { return array(); }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function esc_sql( $value ) { return $value; }
	function esc_url_raw( $value ) { return (string) $value; }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function sanitize_text_field( $value ) { return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : ''; }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function sanitize_title( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function absint( $value ) { return abs( (int) $value ); }
	function wp_kses_post( $value ) { return (string) $value; }
	function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
	function esc_html( $value ) { return (string) $value; }
	function esc_attr( $value ) { return (string) $value; }
	function esc_url( $value ) { return (string) $value; }
	function __( $text, $domain = null ) { return $text; }
	function _x( $text, $context, $domain = null ) { return $text; }
	function home_url( $path = '' ) { return 'https://example.test' . $path; }
	function get_bloginfo( $show = '' ) { return 'Test'; }
	function get_locale() { return 'en_US'; }
	function faz_clear_banner_template_cache() { return true; }

	/*
	 * Anything else the batch names gets a permissive stand-in on demand. Same
	 * rationale (and the same safety argument) as test-migration-cache-bust-php.php:
	 * the class name comes from PHP's own autoload callback, the template is a
	 * fixed string, and this file is a CLI runner that never ships.
	 */
	spl_autoload_register(
		function ( $class ) {
			$pos       = strrpos( $class, '\\' );
			if ( false === $pos ) {
				return;
			}
			$namespace = substr( $class, 0, $pos );
			$short     = substr( $class, $pos + 1 );
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- see the note above: fixed template, autoloader-supplied name, test-only file.
			eval( "namespace {$namespace}; class {$short} extends \\Faz_Geo_Mig_Stub {}" );
		}
	);

	class Faz_Geo_Wpdb {
		public $prefix     = 'wp_';
		public $options    = 'wp_options';
		public $last_error = '';
		public function __call( $name, $args ) { return array(); }
		public function get_var( $q = null ) { return null; }
		public function get_row( $q = null, $o = null ) { return null; }
		public function get_col( $q = null ) { return array(); }
		public function get_results( $q = null, $o = null ) { return array(); }
		public function query( $q ) { return 0; }
		public function prepare( $q ) { return $q; }
		public function update( $t, $d, $w, $f = null, $wf = null ) { return 1; }
		public function delete( $t, $w, $f = null ) { return 1; }
		public function insert( $t, $d, $f = null ) { return 1; }
		public function esc_like( $v ) { return $v; }
		public function get_charset_collate() { return ''; }
	}
	$GLOBALS['wpdb'] = new Faz_Geo_Wpdb();

	// The REAL settings class, not a stand-in. The migration is required to write
	// through Settings::update() rather than update_option( 'faz_settings' ), so
	// the write is sanitised against the shipped defaults and fires
	// faz_after_update_settings (the hook that purges the page caches and the
	// banner template). A hand-written double would prove neither.
	require_once dirname( __DIR__, 2 ) . '/includes/class-formatting.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-store.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/settings/includes/class-settings.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-activator.php';

	use FazCookie\Admin\Modules\Settings\Includes\Settings;
	use FazCookie\Includes\Activator;

	$run    = 0;
	$failed = 0;
	function geo_check( $condition, $label ) {
		global $run, $failed;
		++$run;
		if ( $condition ) {
			echo 'PASS ' . $run . ': ' . $label . "\n";
			return;
		}
		++$failed;
		echo 'FAIL ' . $run . ': ' . $label . "\n";
	}

	/**
	 * Reset the fake option table and the settings request cache.
	 *
	 * @param mixed       $settings  Value for the faz_settings option; the
	 *                               sentinel 'ABSENT' leaves the row missing.
	 * @param string|null $previous  Value for faz_previous_version; null leaves
	 *                               the row missing.
	 * @return void
	 */
	function geo_seed( $settings, $previous ) {
		$GLOBALS['faz_geo_options'] = array();
		$GLOBALS['faz_geo_actions'] = array();
		if ( 'ABSENT' !== $settings ) {
			$GLOBALS['faz_geo_options']['faz_settings'] = $settings;
		}
		if ( null !== $previous ) {
			$GLOBALS['faz_geo_options']['faz_previous_version'] = $previous;
		}
		Settings::clear_cache();
	}

	/**
	 * A minimal but realistic stored settings array.
	 *
	 * @param bool   $geo_targeting    Stored geolocation.geo_targeting.
	 * @param string $default_behavior Stored geolocation.default_behavior.
	 * @return array
	 */
	function geo_settings( $geo_targeting, $default_behavior ) {
		return array(
			'geolocation' => array(
				'geo_targeting'    => $geo_targeting,
				'target_regions'   => array( 'eu', 'uk' ),
				'default_behavior' => $default_behavior,
			),
		);
	}

	/**
	 * @param string $key Leaf inside the stored geolocation group.
	 * @return mixed
	 */
	function geo_stored( $key ) {
		$settings = get_option( 'faz_settings', array() );
		return isset( $settings['geolocation'][ $key ] ) ? $settings['geolocation'][ $key ] : null;
	}

	echo "\n== Geo enforcement preservation migration ==\n\n";

	/* ── 0. The discriminator itself ──────────────────────────────────────── */

	// Everything below rests on being able to tell a fresh install from an
	// upgrade, and `faz_version` cannot do it: check_version() calls install()
	// on `init` and bumps it to FAZ_VERSION before admin_init runs the
	// migrations, so by the time a migration reads it, it always says "current".
	// capture_previous_version() records the pre-upgrade value at the TOP of
	// install(), which is the only point at which it is still readable.
	$capture = new ReflectionMethod( Activator::class, 'capture_previous_version' );
	$capture->setAccessible( true );

	$GLOBALS['faz_geo_options'] = array();
	$capture->invoke( null );
	geo_check(
		Activator::FRESH_INSTALL_MARKER === get_option( 'faz_previous_version' ),
		'no faz_version to preserve → the fresh-install marker is recorded, not an empty string or 0.0.0'
	);

	$GLOBALS['faz_geo_options'] = array( 'faz_version' => '1.27.1' );
	$capture->invoke( null );
	geo_check( '1.27.1' === get_option( 'faz_previous_version' ), 'an existing faz_version is recorded verbatim as the version upgraded FROM' );

	// Re-entrancy: install() bumps faz_version on its LAST line, so a fatal
	// midway re-enters with the same pre-upgrade value and must reach the same
	// answer rather than recording the half-finished state.
	$capture->invoke( null );
	geo_check( '1.27.1' === get_option( 'faz_previous_version' ), 'capturing twice before faz_version is bumped is idempotent' );

	// And the call has to sit ahead of the bump in install(), or it records the
	// version it was meant to preserve the predecessor of.
	$activator_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-activator.php' );
	$capture_pos   = strpos( $activator_src, 'self::capture_previous_version();' );
	$bump_pos      = strpos( $activator_src, "update_option( 'faz_version', FAZ_VERSION );" );
	geo_check(
		false !== $capture_pos && false !== $bump_pos && $capture_pos < $bump_pos,
		'install() captures the previous version BEFORE it bumps faz_version'
	);

	/* ── 1. Fresh install → the migration does nothing ─────────────────────── */

	// install() writes the marker before any admin_init can fire, so a fresh
	// install of a gated build is always identifiable. Settings::get_defaults()
	// now seeds enforcement on; the migration's job remains limited to upgrades
	// and must not overwrite a value already stored on the fresh path.
	geo_seed( geo_settings( false, 'no_banner' ), Activator::FRESH_INSTALL_MARKER );
	$before = $GLOBALS['faz_geo_options'];
	Activator::preserve_geo_enforcement_on_upgrade();
	geo_check( $before === $GLOBALS['faz_geo_options'], 'fresh install: no option is written at all' );
	geo_check( false === geo_stored( 'geo_targeting' ), 'fresh install: an explicitly stored geo_targeting value is left untouched' );
	geo_check( empty( $GLOBALS['faz_geo_actions'] ), 'fresh install: no settings-update hook fires' );

	/* ── 2. Upgrade, geo off, banner shown to everyone → promoted only ─────── */

	geo_seed( geo_settings( false, 'show_banner' ), '1.27.1' );
	Activator::preserve_geo_enforcement_on_upgrade();
	geo_check( true === geo_stored( 'geo_targeting' ), 'upgrade: geo_targeting is promoted to true, so enforcement survives' );
	geo_check( 'show_banner' === geo_stored( 'default_behavior' ), 'upgrade: default_behavior is untouched when it was already show_banner' );
	geo_check(
		in_array( 'faz_after_update_settings', $GLOBALS['faz_geo_actions'], true ),
		'upgrade: the write goes through Settings::update(), which is what fires faz_after_update_settings'
	);
	geo_check( '1' === get_option( 'faz_geo_enforcement_preserved' ), 'upgrade: the completion flag is recorded' );
	geo_check( '1' === get_option( 'faz_geo_enforcement_notice' ), 'upgrade: the one-time admin notice is armed' );

	/* ── 3. Upgrade with a DORMANT no_banner → promoted AND normalised ─────── */

	// This is the case where promoting the flag alone would be a visible
	// regression: is_geo_banner_disabled() would start returning true for every
	// visitor outside eu/uk on a site that has always shown the banner to all.
	geo_seed( geo_settings( false, 'no_banner' ), '1.27.1' );
	Activator::preserve_geo_enforcement_on_upgrade();
	geo_check( true === geo_stored( 'geo_targeting' ), 'dormant no_banner: geo_targeting is promoted' );
	geo_check(
		'show_banner' === geo_stored( 'default_behavior' ),
		'dormant no_banner: normalised to show_banner in the same write, so nobody starts losing the banner'
	);

	/* ── 4. Upgrade with geo_targeting already on → untouched ──────────────── */

	// Here default_behavior is LIVE, not dormant: the admin configured a site
	// that deliberately hides the banner outside its target regions. Rewriting it
	// would be a real display change, which is the opposite of the intent.
	geo_seed( geo_settings( true, 'no_banner' ), '1.27.1' );
	$before = $GLOBALS['faz_geo_options'];
	Activator::preserve_geo_enforcement_on_upgrade();
	geo_check( $before === $GLOBALS['faz_geo_options'], 'already enabled: nothing is written' );
	geo_check( 'no_banner' === geo_stored( 'default_behavior' ), 'already enabled: a LIVE no_banner is preserved' );
	geo_check( empty( $GLOBALS['faz_geo_actions'] ), 'already enabled: no settings-update hook fires' );

	/* ── 5. Upgrade from a build that already had the gate → untouched ─────── */

	geo_seed( geo_settings( false, 'show_banner' ), '1.28.0' );
	$before = $GLOBALS['faz_geo_options'];
	Activator::preserve_geo_enforcement_on_upgrade();
	geo_check(
		$before === $GLOBALS['faz_geo_options'],
		'upgrade from a gated version: nothing is written — a false there is a real decision about enforcement'
	);

	/* ── 6. faz_previous_version absent → treated as an upgrade ────────────── */

	// The option ships WITH the gate, so an install that has never run a gated
	// build cannot have it. Absence therefore means "predates the gate", and it
	// is the case that carries the real-world upgrade population.
	geo_seed( geo_settings( false, 'show_banner' ), null );
	Activator::preserve_geo_enforcement_on_upgrade();
	geo_check( true === geo_stored( 'geo_targeting' ), 'absent faz_previous_version is read as a pre-gate upgrade, not as a fresh install' );

	/* ── 7. Malformed / missing faz_settings → no fatal, no write ──────────── */

	geo_seed( 'ABSENT', '1.27.1' );
	$before = $GLOBALS['faz_geo_options'];
	Activator::preserve_geo_enforcement_on_upgrade();
	geo_check( $before === $GLOBALS['faz_geo_options'], 'missing faz_settings: nothing is written — the migration does not materialise a settings row' );
	geo_check( false === get_option( 'faz_settings' ), 'missing faz_settings: the option is still absent afterwards' );

	geo_seed( 'not-an-array', '1.27.1' );
	$before = $GLOBALS['faz_geo_options'];
	Activator::preserve_geo_enforcement_on_upgrade();
	geo_check( $before === $GLOBALS['faz_geo_options'], 'corrupted faz_settings (a string): nothing is written' );

	geo_seed( array( 'geolocation' => 'not-an-array' ), '1.27.1' );
	Activator::preserve_geo_enforcement_on_upgrade();
	geo_check( true === geo_stored( 'geo_targeting' ), 'corrupted geolocation sub-array: repaired and promoted, no fatal' );

	geo_seed( array( 'consent_logs' => array( 'status' => true ) ), '1.27.1' );
	Activator::preserve_geo_enforcement_on_upgrade();
	geo_check( true === geo_stored( 'geo_targeting' ), 'faz_settings with no geolocation group: the group is created and promoted' );

	/* ── 8. Idempotency ───────────────────────────────────────────────────── */

	geo_seed( geo_settings( false, 'no_banner' ), '1.27.1' );
	Activator::preserve_geo_enforcement_on_upgrade();
	$after_first                = $GLOBALS['faz_geo_options'];
	$GLOBALS['faz_geo_actions'] = array();
	Settings::clear_cache();
	Activator::preserve_geo_enforcement_on_upgrade();
	geo_check( $after_first === $GLOBALS['faz_geo_options'], 'running twice changes nothing the second time' );
	geo_check( empty( $GLOBALS['faz_geo_actions'] ), 'the second run does not re-fire the settings-update hook' );

	// And the completion flag, not just "geo_targeting is already true", is what
	// stops it: an admin who reads the notice and deliberately turns the toggle
	// back off must not be overridden by the next MIGRATIONS_VERSION bump.
	$GLOBALS['faz_geo_options']['faz_settings'] = geo_settings( false, 'show_banner' );
	$GLOBALS['faz_geo_actions']                 = array();
	Settings::clear_cache();
	Activator::preserve_geo_enforcement_on_upgrade();
	geo_check(
		false === geo_stored( 'geo_targeting' ),
		'a deliberate later switch-off is not re-promoted — the completion flag, not the current value, is the guard'
	);

	/* ── 9. Wired into the real migration batch ───────────────────────────── */

	// Drives run_pending_migrations() for real, so this assertion is what goes
	// red if the migration is dropped from the list (or the batch stops reaching
	// it). The migrations warn on stub shapes they were never meant to see;
	// those warnings are an artefact of the harness.
	geo_seed( geo_settings( false, 'no_banner' ), '1.27.1' );
	$previous_level = error_reporting( E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED );
	Activator::run_pending_migrations();
	error_reporting( $previous_level );

	geo_check(
		Activator::MIGRATIONS_VERSION === get_option( 'faz_migrations_version' ),
		'PRECONDITION: the full migration batch ran to completion under the stub harness'
	);
	geo_check(
		true === geo_stored( 'geo_targeting' ),
		'run_pending_migrations() promotes geo_targeting — the migration is in the batch list'
	);
	geo_check(
		'show_banner' === geo_stored( 'default_behavior' ),
		'run_pending_migrations() also normalises the dormant no_banner'
	);
	geo_check(
		'1' === get_option( 'faz_geo_enforcement_notice' ),
		'run_pending_migrations() arms the one-time admin notice'
	);

	// The batch flag must not be able to stand in for the migration's own flag:
	// a bump of MIGRATIONS_VERSION re-runs the list, and the guard against
	// re-promotion has to survive that.
	geo_check(
		'1' === get_option( 'faz_geo_enforcement_preserved' ),
		'the migration records its own completion flag, independent of faz_migrations_version'
	);

	echo "\n" . $run . ' checks, ' . $failed . " failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
