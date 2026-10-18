<?php
/** Suggested policy copy derived from the features the site actually enables. */
namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Privacy_Policy_Text {
	public static function environment( $settings ) {
		$ipinfo = new \FazCookie\Admin\Modules\Geo_Routing\Includes\Ipinfo_Client();
		return array(
			// The ROUTE the administrator configured, not the state of its last
			// verification: published policy text must not change by itself when
			// a probe goes stale or a cron run is missed.
			'withdrawal_footer' => Withdrawal_Path::PATH_FOOTER_LINK === Withdrawal_Path::configured_path( $settings ),
			'multisite' => function_exists( 'is_multisite' ) && is_multisite(),
			'has_mmdb' => Geolocation::has_database(),
			'ipinfo_active' => $ipinfo->is_optin_active() && '' !== $ipinfo->get_api_key(),
		);
	}

	/**
	 * How the visitor withdraws, for policy copy.
	 *
	 * @param bool $footer Whether the configured withdrawal route is the footer
	 *                     link (Withdrawal_Path::configured_path()). Deliberately
	 *                     not whether that link was verified recently: the text
	 *                     must describe the site's design and stay stable.
	 * @return string
	 */
	public static function withdrawal_sentence( $footer ) {
		return $footer
			? __( 'You can change or withdraw your cookie choices using the cookie settings link in the site footer.', 'faz-cookie-manager' )
			: __( 'You can change or withdraw your cookie choices using the floating cookie settings button.', 'faz-cookie-manager' );
	}

	/** An ordered fact map also lets integrations inspect individual disclosures. */
	public static function facts( array $settings, array $env ) {
		$facts = array(
			'purpose' => __( 'This site uses FAZ Cookie Manager to collect and manage cookie choices.', 'faz-cookie-manager' ),
			'consent_cookie' => __( 'The fazcookie-consent cookie stores your choices, their scope and policy revision, and a persistent consent ID for the configured cookie lifetime; the ID is kept when you withdraw consent.', 'faz-cookie-manager' ),
			'consent_id' => __( 'Your consent ID is shown in cookie preferences and can be supplied with a privacy request to locate your consent history; export or erasure of that history requires independent ownership verification and is subject to applicable retention requirements.', 'faz-cookie-manager' ),
			'dnsmpi' => __( 'If you use the Do Not Sell or Share control, fazcookie-dnsmpi records that opt-out.', 'faz-cookie-manager' ),
		);
		if ( ! empty( $env['multisite'] ) ) {
			$facts['multisite'] = __( 'On WordPress multisite, these consent cookie names include a network and site suffix so each site keeps a separate choice.', 'faz-cookie-manager' );
		}
		if ( ! isset( $settings['consent_logs']['status'] ) || ! empty( $settings['consent_logs']['status'] ) ) {
			$facts['logs'] = __( 'Consent records are stored on this site with the consent ID, decision, category and service choices, date, page URL with non-routing query parameters removed, banner and policy revision, and applicable region and privacy signals.', 'faz-cookie-manager' );
			$facts['log_signals'] = __( 'When available, consent records also contain the TCF string and a GPP string supplied by an external CMP; FAZ does not generate GPP signals.', 'faz-cookie-manager' );
			$facts['hashes'] = __( 'The IP address and browser user agent in consent records are stored as stable salted SHA-256 hashes, rather than their raw values; these are pseudonymous identifiers.', 'faz-cookie-manager' );
			/* translators: %d: retention in months. */
			$facts['log_retention'] = sprintf( __( 'Consent records are retained for %d months.', 'faz-cookie-manager' ), max( 1, (int) ( $settings['consent_logs']['retention'] ?? 12 ) ) );
		}
		if ( ! empty( $settings['iab']['enabled'] ) ) {
			$facts['tcf'] = __( 'With IAB TCF enabled, euconsent-v2 stores the consent string and fazVendorConsent stores vendor choices.', 'faz-cookie-manager' );
			$facts['tcf_li'] = __( 'The fazVendorLI cookie stores your objections to legitimate-interest processing.', 'faz-cookie-manager' );
		}
		if ( ! empty( $settings['geolocation']['geo_targeting'] ) ) {
			$facts['geo'] = __( 'IP-based geolocation selects the applicable cookie rules and banner, using available server or trusted proxy country information.', 'faz-cookie-manager' );
			if ( ! empty( $env['has_mmdb'] ) ) {
				$facts['maxmind'] = __( 'Geolocation can also use the MaxMind database stored locally on this server, without sending the visitor IP to MaxMind.', 'faz-cookie-manager' );
			}
			if ( ! empty( $env['ipinfo_active'] ) ) {
				$facts['ipinfo'] = __( 'The enabled ipinfo.io VPN/proxy lookup sends the visitor IP address in cleartext to ipinfo.io and caches the classification under a hashed IP key.', 'faz-cookie-manager' );
			}
		}
		if ( ! empty( $settings['pageview_tracking'] ) ) {
			$facts['pageviews'] = __( 'Pageview measurement stores aggregate counters by page URL, page title and event type, without a visitor identifier.', 'faz-cookie-manager' );
			$months = (int) ( $settings['pageviews']['retention'] ?? 6 );
			$facts['pageview_retention'] = $months > 0
				/* translators: %d: retention in months. */
				? sprintf( __( 'Pageview counters are retained for %d months.', 'faz-cookie-manager' ), $months )
				: __( 'Pageview counters have no automatic retention limit.', 'faz-cookie-manager' );
		}
		/* translators: %d: retention in months. */
		$facts['dsar'] = sprintf( __( 'If you submit a privacy request through the site form, we store your name, email, request type, message, status and any consent ID you supply in a private request record for %d months; erasure removes identifying details unless applicable retention requirements require otherwise.', 'faz-cookie-manager' ), max( 1, (int) ( $settings['dsar']['retention'] ?? 24 ) ) );
		$facts['withdrawal'] = self::withdrawal_sentence( ! empty( $env['withdrawal_footer'] ) );
		return $facts;
	}

	/**
	 * The suggested policy content for the stored settings, as the admin_init
	 * hook hands it to wp_add_privacy_policy_content().
	 *
	 * The value comes straight from get_option(), whose default applies only
	 * when the option is missing: a stored value that is not an array (a bad
	 * import, a damaged row) reached build( array $settings ) as a TypeError
	 * on every wp-admin request. It is read as the defaults instead.
	 *
	 * @param mixed $settings Stored faz_settings value.
	 * @return string
	 */
	public static function suggested_content( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		return self::build( $settings, self::environment( $settings ) );
	}

	public static function build( array $settings, array $env ) {
		$html = '<h2>' . esc_html__( 'Cookie Consent (FAZ Cookie Manager)', 'faz-cookie-manager' ) . '</h2>';
		foreach ( self::facts( $settings, $env ) as $sentence ) {
			$html .= '<p>' . esc_html( $sentence ) . '</p>';
		}
		return $html;
	}
}
