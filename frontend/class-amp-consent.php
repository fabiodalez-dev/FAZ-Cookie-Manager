<?php
/**
 * AMP consent integration.
 *
 * Outputs an <amp-consent> component on AMP pages instead of the
 * regular JavaScript-based banner.
 *
 * @package    FazCookie
 * @subpackage FazCookie/Frontend
 */

namespace FazCookie\Frontend;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use FazCookie\Admin\Modules\Banners\Includes\Controller;
use FazCookie\Includes\Geolocation;
use FazCookie\Frontend\Includes\Geo_Runtime;

/**
 * AMP consent integration.
 *
 * When the current page is served as AMP (via the official AMP plugin,
 * AMP for WP, or legacy endpoints), this class:
 *
 * 1. Dequeues all FAZ frontend scripts (JS is not allowed on AMP pages).
 * 2. Outputs the `<amp-consent>` custom-element script in `<head>`.
 * 3. Renders a declarative `<amp-consent>` component in the footer with
 *    accept/reject buttons and an optional post-consent revisit widget.
 */
class AMP_Consent {

	/** @var bool Whether AMP styles have been output (prevents double rendering). */
	private static $styles_output = false;

	/** @var bool Whether AMP boilerplate has been output. */
	private static $boilerplate_output = false;

	/** @var bool Whether AMP consent component has been output. */
	private static $consent_output = false;

	/**
	 * Constructor — hooks into `wp` to detect AMP pages.
	 */
	public function __construct() {
		// The REST bridge is registered on every public/REST bootstrap, not only
		// after AMP page detection: checkConsentHref/onUpdateHref are separate
		// requests and therefore cannot depend on the rendering request's hooks.
		new AMP_Consent_Rest();
		add_action( 'wp', array( $this, 'maybe_init' ) );
	}

	/**
	 * Initialize AMP consent if we are on an AMP page.
	 *
	 * @return void
	 */
	public function maybe_init() {
		if ( ! $this->is_amp_page() ) {
			return;
		}

		// Remove the regular frontend scripts (they will not work on AMP).
		add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_faz_scripts' ), 999 );

		// Signal to Frontend that this is an AMP request — suppresses
		// the regular banner template and inline styles.
		add_filter( 'faz_is_amp_request', '__return_true' );

		// The regular runtime also stops here for builder previews, excluded
		// pages and bots. Keep AMP on the same settings contract while retaining
		// the faz_is_amp_request signal above so classic JS never leaks into AMP.
		if ( $this->is_amp_runtime_disabled() ) {
			return;
		}

		// F013 fix: AMP pages render through their own template stack
		// (separate amphtml endpoint URLs, dedicated cache controls). The
		// regular Frontend::send_geo_cache_headers() listener on
		// send_headers would catch the HTML response, but only when the
		// AMP template loads through WP's normal request path AND the
		// listener fires before AMP-specific output buffers flush. To
		// guarantee the bypass on country-dependent installs, force the
		// nocache stack here as well. Idempotent with Frontend's
		// listener — duplicate headers are harmless; both refer to the
		// same nocache directive.
		if ( $this->is_country_dependent_output() ) {
			if ( ! headers_sent() ) {
				header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
				header( 'Pragma: no-cache' );
				header( 'X-LiteSpeed-Cache-Control: no-cache' );
			}
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			do_action( 'litespeed_control_set_nocache', 'FAZ AMP country-dependent banner' );
		}

		// AMP boilerplate script in head.
		add_action( 'amp_post_template_head', array( $this, 'output_amp_boilerplate' ) );
		add_action( 'wp_head', array( $this, 'output_amp_boilerplate' ) );

		// AMP custom CSS in head (AMP requires <style amp-custom> in <head>).
		add_action( 'amp_post_template_head', array( $this, 'output_amp_styles' ) );
		add_action( 'wp_head', array( $this, 'output_amp_styles' ) );

		// AMP consent component in footer.
		add_action( 'amp_post_template_footer', array( $this, 'output_amp_consent' ) );
		add_action( 'wp_footer', array( $this, 'output_amp_consent' ) );

		// Apply granular blocking attributes only to AMP components FAZ can
		// classify deterministically. Theme/plugin markup that already carries a
		// consent policy is left untouched; unknown components are never claimed
		// as covered by this integration.
		add_action( 'template_redirect', array( $this, 'start_component_blocking_buffer' ), 11 );
	}

	/**
	 * Whether AMP output should bypass page caches for visitor-specific banners.
	 *
	 * @return bool
	 */
	private function is_country_dependent_output() {
		$settings = $this->get_faz_settings();
		if ( $this->is_cache_compatibility_enabled() ) {
			return (bool) apply_filters( 'faz_country_dependent_banner_output', false, $settings );
		}

		if (
			function_exists( 'faz_i18n_is_multilingual' )
			&& ! faz_i18n_is_multilingual()
			&& apply_filters( 'faz_use_country_language_fallback', false )
		) {
			return true;
		}

		if (
			! empty( $settings['geolocation']['geo_targeting'] )
			&& isset( $settings['geolocation']['default_behavior'] )
			&& 'no_banner' === $settings['geolocation']['default_behavior']
		) {
			return true;
		}

		return Controller::get_instance()->has_country_dependent_banners()
			|| ( class_exists( Geo_Runtime::class ) && Geo_Runtime::is_enabled() );
	}

	/**
	 * Read FAZ settings as an array.
	 *
	 * @return array
	 */
	private function get_faz_settings() {
		$settings = get_option( 'faz_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Whether Cache Compatibility Mode is active.
	 *
	 * @return bool
	 */
	private function is_cache_compatibility_enabled() {
		$settings = $this->get_faz_settings();
		$geo_enabled = class_exists( Geo_Runtime::class ) && Geo_Runtime::is_enabled();
		return ! $geo_enabled && ! empty( $settings['banner_control']['cache_compatibility'] );
	}

	/**
	 * Mirror the classic banner's request/settings suppression on AMP.
	 *
	 * @return bool
	 */
	private function is_amp_runtime_disabled() {
		if ( function_exists( 'faz_disable_banner' ) && faz_disable_banner() ) {
			return true;
		}
		$settings = $this->get_faz_settings();
		if ( empty( $settings['banner_control']['status'] ) ) {
			return true;
		}
		if ( ( ! isset( $settings['banner_control']['hide_from_bots'] ) || ! empty( $settings['banner_control']['hide_from_bots'] ) )
			&& function_exists( 'faz_is_bot' ) && faz_is_bot() ) {
			return true;
		}
		$excluded = isset( $settings['banner_control']['excluded_pages'] ) && is_array( $settings['banner_control']['excluded_pages'] )
			? $settings['banner_control']['excluded_pages'] : array();
		$current_id   = function_exists( 'get_the_ID' ) ? absint( get_the_ID() ) : 0;
		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$current_path = strtok( (string) $request_uri, '?' );
		$current_path = strtok( (string) $current_path, '#' );
		$current_path = '' === (string) $current_path ? '/' : $current_path;
		foreach ( $excluded as $exclusion ) {
			if ( is_numeric( $exclusion ) && absint( $exclusion ) === $current_id ) {
				return true;
			}
			if ( is_string( $exclusion ) && '' !== trim( $exclusion )
				&& function_exists( 'faz_path_matches_pattern' )
				&& faz_path_matches_pattern( trim( $exclusion ), $current_path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if current page is AMP.
	 *
	 * Supports the official AMP plugin, its legacy helper, and the
	 * AMP for WP plugin.
	 *
	 * @return bool
	 */
	private function is_amp_page() {
		// Official AMP plugin (v2+).
		if ( function_exists( 'amp_is_request' ) && amp_is_request() ) {
			return true;
		}
		// Legacy AMP plugin (v1).
		if ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) {
			return true;
		}
		// AMP for WP plugin.
		if ( function_exists( 'ampforwp_is_amp_endpoint' ) && ampforwp_is_amp_endpoint() ) {
			return true;
		}
		return false;
	}

	/**
	 * Dequeue FAZ scripts on AMP pages.
	 *
	 * The main script handle may be `faz-cookie-manager` or `faz-fw`
	 * depending on the alternative-asset-path setting.  We dequeue both
	 * plus every auxiliary handle (GCM, TCF, WCA, Microsoft).
	 *
	 * @return void
	 */
	public function dequeue_faz_scripts() {
		$handles = array(
			'faz-cookie-manager',
			'faz-fw',
			'faz-cookie-manager-gcm',
			'faz-fw-gcm',
			'faz-cookie-manager-tcf-cmp',
			'faz-fw-tcf-cmp',
			'faz-cookie-manager-wca',
			'faz-fw-wca',
			'faz-cookie-manager-microsoft-consent',
			'faz-fw-microsoft-consent',
		);
		foreach ( $handles as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
	}

	/**
	 * Output the AMP consent custom-element script tag in <head>.
	 *
	 * @return void
	 */
	public function output_amp_boilerplate() {
		if ( ! $this->is_amp_page() || self::$boilerplate_output ) {
			return;
		}
		self::$boilerplate_output = true;
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- AMP requires inline script tags.
		echo '<script async custom-element="amp-consent" src="https://cdn.ampproject.org/v0/amp-consent-0.1.js"></script>' . "\n";
	}

	/**
	 * Load and cache the active banner colours for AMP output.
	 *
	 * Returns an associative array of colour values extracted from the
	 * active banner settings, or false if the banner is not available.
	 *
	 * @return array|false
	 */
	private function get_amp_colours() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		// Respect global banner toggle.
		$settings = get_option( 'faz_settings' );
		if ( empty( $settings['banner_control']['status'] ) ) {
			$cached = false;
			return false;
		}

		$banner = $this->get_active_banner();
		if ( false === $banner ) {
			$cached = false;
			return false;
		}

		$banner_settings = $banner->get_settings();
		$config          = isset( $banner_settings['config'] ) ? $banner_settings['config'] : array();
		$notice_cfg      = isset( $config['notice'] ) ? $config['notice'] : array();
		$notice_styles   = isset( $notice_cfg['styles'] ) ? $notice_cfg['styles'] : array();
		$btn_cfg         = isset( $notice_cfg['elements']['buttons']['elements'] ) ? $notice_cfg['elements']['buttons']['elements'] : array();
		$accept_cfg      = isset( $btn_cfg['accept']['styles'] ) ? $btn_cfg['accept']['styles'] : array();
		$reject_cfg      = isset( $btn_cfg['reject']['styles'] ) ? $btn_cfg['reject']['styles'] : array();
		$link_cfg        = isset( $config['accessibilityOverrides']['elements']['manualLinks']['styles'] ) ? $config['accessibilityOverrides']['elements']['manualLinks']['styles'] : array();
		$revisit_cfg     = isset( $config['revisitConsent']['styles'] ) ? $config['revisitConsent']['styles'] : array();

		$accept_bg = ! empty( $accept_cfg['background-color'] ) ? $accept_cfg['background-color'] : '#1863DC';

		$cached = array(
			'bg_color'       => ! empty( $notice_styles['background-color'] ) ? $notice_styles['background-color'] : '#fff',
			'text_color'     => ! empty( $notice_styles['color'] ) ? $notice_styles['color'] : '#555',
			'title_color'    => ! empty( $notice_styles['color'] ) ? $notice_styles['color'] : '#111',
			'accept_bg'      => $accept_bg,
			'accept_color'   => ! empty( $accept_cfg['color'] ) ? $accept_cfg['color'] : '#fff',
			'reject_bg'      => ! empty( $reject_cfg['background-color'] ) ? $reject_cfg['background-color'] : 'transparent',
			'reject_color'   => ! empty( $reject_cfg['color'] ) ? $reject_cfg['color'] : '#333',
			'reject_border'  => ! empty( $reject_cfg['border-color'] ) ? $reject_cfg['border-color'] : '#ccc',
			'link_color'     => ! empty( $link_cfg['color'] ) ? $link_cfg['color'] : '#666',
			'revisit_bg'     => ! empty( $revisit_cfg['background-color'] ) ? $revisit_cfg['background-color'] : $accept_bg,
			'revisit_color'  => ! empty( $revisit_cfg['color'] ) ? $revisit_cfg['color'] : '#fff',
		);

		return $cached;
	}

	/**
	 * Output AMP custom CSS in <head>.
	 *
	 * AMP requires <style amp-custom> to be inside <head>; there can only
	 * be one per page.
	 *
	 * @return void
	 */
	public function output_amp_styles() {
		if ( ! $this->is_amp_page() || self::$styles_output ) {
			return;
		}
		self::$styles_output = true;

		$c = $this->get_amp_colours();
		if ( false === $c ) {
			return;
		}

		?>
		<style amp-custom>
			.faz-amp-banner{position:fixed;bottom:0;left:0;right:0;background:<?php echo esc_attr( $c['bg_color'] ); ?>;box-shadow:0 -2px 10px rgba(0,0,0,.15);z-index:9999;padding:16px 20px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
			.faz-amp-banner-inner{max-width:960px;margin:0 auto}
			.faz-amp-title{font-size:16px;font-weight:700;margin:0 0 8px;color:<?php echo esc_attr( $c['title_color'] ); ?>}
			.faz-amp-desc{font-size:13px;line-height:1.5;color:<?php echo esc_attr( $c['text_color'] ); ?>;margin:0 0 12px}
			.faz-amp-purposes{display:grid;gap:8px;margin:0 0 12px;padding:0;border:0}
			.faz-amp-purpose{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid rgba(127,127,127,.25)}
			.faz-amp-purpose-name{font-size:13px;font-weight:600;color:<?php echo esc_attr( $c['text_color'] ); ?>}
			.faz-amp-purpose-choice{display:flex;align-items:center;gap:7px;font-size:12px;color:<?php echo esc_attr( $c['reject_color'] ); ?>;cursor:pointer}
			.faz-amp-purpose-choice input{width:18px;height:18px;margin:0;accent-color:<?php echo esc_attr( $c['accept_bg'] ); ?>}
			.faz-amp-actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px}
			.faz-amp-btn{padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:500;cursor:pointer}
			.faz-amp-btn-accept{background:<?php echo esc_attr( $c['accept_bg'] ); ?>;color:<?php echo esc_attr( $c['accept_color'] ); ?>}
			.faz-amp-btn-reject{background:<?php echo esc_attr( $c['reject_bg'] ); ?>;color:<?php echo esc_attr( $c['reject_color'] ); ?>;border:1px solid <?php echo esc_attr( $c['reject_border'] ); ?>}
			.faz-amp-purposes-note{font-size:12px;margin:0 0 8px;opacity:.85}
			.faz-amp-btn-save{background:transparent;color:<?php echo esc_attr( $c['reject_color'] ); ?>;border:1px dashed <?php echo esc_attr( $c['reject_color'] ); ?>}
			.faz-amp-link{font-size:12px;color:<?php echo esc_attr( $c['link_color'] ); ?>;text-decoration:underline}
			.faz-amp-revisit{position:fixed;bottom:16px;left:16px;z-index:9998}
			.faz-amp-revisit-btn{width:40px;height:40px;border-radius:50%;border:none;background:<?php echo esc_attr( $c['revisit_bg'] ); ?>;color:<?php echo esc_attr( $c['revisit_color'] ); ?>;font-size:20px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.2);display:flex;align-items:center;justify-content:center}
		</style>
		<?php
	}

	/**
	 * Output the <amp-consent> component.
	 *
	 * @return void
	 */
	public function output_amp_consent() {
		if ( ! $this->is_amp_page() || self::$consent_output ) {
			return;
		}
		self::$consent_output = true;

		// Respect global banner toggle.
		$settings = get_option( 'faz_settings' );
		if ( empty( $settings['banner_control']['status'] ) ) {
			return;
		}

		// Load the same country-aware banner used by the classic frontend.
		$banner = $this->get_active_banner();
		if ( false === $banner ) {
			return;
		}

		$banner_settings = $banner->get_settings();
		$banner_contents = $banner->get_contents();
		$config          = isset( $banner_settings['config'] ) && is_array( $banner_settings['config'] ) ? $banner_settings['config'] : array();
		$notice_buttons  = isset( $config['notice']['elements']['buttons']['elements'] ) && is_array( $config['notice']['elements']['buttons']['elements'] )
			? $config['notice']['elements']['buttons']['elements'] : array();
		$preference_cfg  = isset( $config['preferenceCenter'] ) && is_array( $config['preferenceCenter'] ) ? $config['preferenceCenter'] : array();
		$preference_buttons = isset( $preference_cfg['elements']['buttons']['elements'] ) && is_array( $preference_cfg['elements']['buttons']['elements'] )
			? $preference_cfg['elements']['buttons']['elements'] : array();
		$accept_enabled  = ! isset( $notice_buttons['accept']['status'] ) || ! empty( $notice_buttons['accept']['status'] );
		$reject_enabled  = ! isset( $notice_buttons['reject']['status'] ) || ! empty( $notice_buttons['reject']['status'] );
		$preferences_enabled = ! isset( $preference_cfg['status'] ) || ! empty( $preference_cfg['status'] );
		$save_enabled    = $preferences_enabled && ( ! isset( $preference_buttons['save']['status'] ) || ! empty( $preference_buttons['save']['status'] ) );
		$revisit_enabled = ! isset( $config['revisitConsent']['status'] ) || ! empty( $config['revisitConsent']['status'] );

		// Resolve current language content.
		$lang    = faz_current_language();
		$content = array();
		if ( is_array( $banner_contents ) && ! empty( $banner_contents ) ) {
			$content = isset( $banner_contents[ $lang ] )
				? $banner_contents[ $lang ]
				: ( isset( $banner_contents['en'] )
					? $banner_contents['en']
					: reset( $banner_contents ) );
		}
		if ( ! is_array( $content ) ) {
			$content = array();
		}

		// Extract text from the notice section.
		$notice      = isset( $content['notice']['elements'] ) ? $content['notice']['elements'] : array();
		$title       = isset( $notice['title'] ) ? wp_strip_all_tags( $notice['title'] ) : '';
		$description = isset( $notice['description'] ) ? wp_strip_all_tags( $notice['description'] ) : '';
		$btn         = isset( $notice['buttons']['elements'] ) ? $notice['buttons']['elements'] : array();
		$accept_label    = ! empty( $btn['accept'] ) ? wp_strip_all_tags( $btn['accept'] ) : __( 'Accept All', 'faz-cookie-manager' );
		$reject_label    = ! empty( $btn['reject'] ) ? wp_strip_all_tags( $btn['reject'] ) : __( 'Reject All', 'faz-cookie-manager' );
		$settings_label  = ! empty( $btn['readMore'] ) ? wp_strip_all_tags( $btn['readMore'] ) : __( 'Cookie Policy', 'faz-cookie-manager' );
		$preference      = isset( $content['preferenceCenter']['elements'] ) && is_array( $content['preferenceCenter']['elements'] )
			? $content['preferenceCenter']['elements']
			: array();
		$preference_btn = isset( $preference['buttons']['elements'] ) && is_array( $preference['buttons']['elements'] )
			? $preference['buttons']['elements']
			: array();
		$save_label      = ! empty( $preference_btn['save'] )
			? wp_strip_all_tags( $preference_btn['save'] )
			: __( 'Save preferences', 'faz-cookie-manager' );

		// Cookie policy link.
		$privacy_url = ! empty( $notice['privacyLink'] ) ? $notice['privacyLink'] : '/cookie-policy';

		$purposes = AMP_Consent_Rest::get_purposes();
		// The names the checkboxes will report under. Derived from the same
		// purpose list the REST bridge reads, which is what keeps the two ends
		// from drifting apart.
		$purpose_args = AMP_Consent_Rest::purpose_action_args( $purposes );
		$purpose_ids  = array_values( $purpose_args );
		$endpoints    = AMP_Consent_Rest::endpoint_urls( $banner );
		$accept_actions = array();
		foreach ( $purposes as $purpose ) {
			if ( ! empty( $purpose['requires_separate_optin'] ) && isset( $purpose_args[ $purpose['id'] ] ) ) {
				$accept_actions[] = 'faz-amp-consent.setPurpose(' . $purpose_args[ $purpose['id'] ] . '=false)';
			}
		}
		$accept_actions[] = 'faz-amp-consent.accept(purposeConsentDefault=true)';
		$accept_action = 'tap:' . implode( ',', $accept_actions );

		// Remote mode is required for parity with the standard first-party
		// cookie. The check endpoint compares banner scope, revision and expiry;
		// `expireCache` invalidates AMP localStorage when any of them changed.
		// A rejected timeout is an explicit fail-closed policy for components
		// blocked by this consent instance when the endpoint is unreachable.
		$consent_config = array(
			'consentInstanceId' => $endpoints['instance'],
			'consentRequired'   => 'remote',
			'checkConsentHref'  => $endpoints['check'],
			'onUpdateHref'      => $endpoints['update'],
			'promptUI'          => 'faz-amp-consent-ui',
			'policy'            => array(
				'default' => array(
					'timeout' => array(
						'seconds'        => 5,
						'fallbackAction' => 'reject',
					),
				),
			),
			'captions'          => array(
				'consentPromptCaption' => $title ? $title : __( 'Cookie preferences', 'faz-cookie-manager' ),
				'buttonActionCaption'  => __( 'Choose and save your cookie preferences.', 'faz-cookie-manager' ),
			),
		);
		if ( ! empty( $purpose_ids ) ) {
			$consent_config['purposeConsentRequired'] = $purpose_ids;
		}
		if ( $revisit_enabled ) {
			$consent_config['postPromptUI'] = 'faz-amp-post-consent';
		}

		// amp-consent's public contract expects the instance configuration at
		// the top level (not the legacy multi-consent `consents` wrapper).
		$amp_config = $consent_config;

		/*
		 * No gtagServices key.
		 *
		 * It used to be written here when GCM was enabled, and amp-consent has no
		 * such key in its configuration contract (consentInstanceId,
		 * consentRequired, checkConsentHref, onUpdateHref, promptUI, postPromptUI,
		 * policy, captions, purposeConsentRequired, geoOverride, uiConfig,
		 * exposesTcfApi, clientConfig). The runtime ignored it, so the page
		 * advertised Consent Mode defaults it never applied — decorative
		 * configuration that reads, to anyone auditing the markup, as a working
		 * GCM integration.
		 *
		 * Consent Mode on AMP is configured inside <amp-analytics type="gtag">,
		 * which this bridge does not render. Emitting nothing is the honest state
		 * until that is built.
		 */

		?>
		<amp-consent id="faz-amp-consent" layout="nodisplay">
			<script type="application/json"><?php echo wp_json_encode( $amp_config ); ?></script>

			<div id="faz-amp-consent-ui" class="faz-amp-banner">
				<div class="faz-amp-banner-inner">
					<?php if ( $title ) : ?>
						<h3 class="faz-amp-title"><?php echo esc_html( $title ); ?></h3>
					<?php endif; ?>
					<?php if ( $description ) : ?>
						<p class="faz-amp-desc"><?php echo esc_html( $description ); ?></p>
					<?php endif; ?>
					<?php if ( $preferences_enabled && ! empty( $purposes ) ) : ?>
						<?php
						/*
						 * These boxes always render unticked and cannot yet reflect a
						 * previous choice: amp-consent exposes no per-purpose value plain
						 * markup can bind to, and doing it properly needs amp-bind plus
						 * state this bridge does not render.
						 *
						 * Left unsaid, that is a trap. A visitor returning through the
						 * revisit button sees "nothing allowed" whatever they granted
						 * before, and pressing Save writes exactly that — a withdrawal they
						 * never asked for. Under-reporting feels harmless because it is the
						 * restrictive direction; it is not, because the record then stops
						 * matching what the visitor actually expressed.
						 *
						 * Until amp-bind lands, the boxes say what they are: a fresh choice
						 * that Save applies as shown. Stating it turns a silent
						 * misrepresentation into an explicit instruction.
						 */
						?>
						<div class="faz-amp-purposes" role="group" aria-label="<?php esc_attr_e( 'Optional cookie categories', 'faz-cookie-manager' ); ?>">
							<p class="faz-amp-purposes-note"><?php esc_html_e( 'Tick the categories you want to allow. Saving applies exactly what is shown here, so anything left unticked stays blocked.', 'faz-cookie-manager' ); ?></p>
							<?php foreach ( $purposes as $purpose ) : ?>
								<div class="faz-amp-purpose">
									<span class="faz-amp-purpose-name"><?php echo esc_html( $purpose['name'] ? $purpose['name'] : $purpose['slug'] ); ?></span>
									<label class="faz-amp-purpose-choice">
										<?php
											/*
											 * The purpose id becomes an AMP action-argument NAME, and
											 * those must be plain identifiers. A category slug may
											 * legitimately contain hyphens ("social-media"), which AMP
											 * cannot parse: it drops the whole action, so the checkbox
											 * silently stops recording while the category still appears
											 * in purposeConsentRequired.
											 *
											 * Translating the hyphens here was not enough on its own:
											 * "social-media" and "social_media" are both legal slugs and
											 * both became "social_media", so two boxes reported under one
											 * name and one category silently inherited the other's answer.
											 * purpose_action_args() gives each category a distinct name and
											 * is the same map the server reads the answer back through.
											 */
											$faz_purpose_id  = (string) $purpose['id'];
											$faz_purpose_arg = isset( $purpose_args[ $faz_purpose_id ] )
												? $purpose_args[ $faz_purpose_id ]
												: str_replace( '-', '_', $faz_purpose_id );
											/* translators: %s: cookie category name, for example Analytics. */
											$faz_purpose_label = sprintf( __( 'Allow %s cookies', 'faz-cookie-manager' ), $purpose['name'] ? $purpose['name'] : $purpose['slug'] );
											?>
											<input type="checkbox" on="<?php echo esc_attr( 'change:faz-amp-consent.setPurpose(' . $faz_purpose_arg . '=event.checked)' ); ?>" aria-label="<?php echo esc_attr( $faz_purpose_label ); ?>">
										<span><?php esc_html_e( 'Allow', 'faz-cookie-manager' ); ?></span>
									</label>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
					<div class="faz-amp-actions">
						<?php if ( $accept_enabled ) : ?>
							<button on="<?php echo esc_attr( $accept_action ); ?>" class="faz-amp-btn faz-amp-btn-accept"><?php echo esc_html( $accept_label ); ?></button>
						<?php endif; ?>
						<?php if ( $reject_enabled ) : ?>
							<button on="tap:faz-amp-consent.reject(purposeConsentDefault=false)" class="faz-amp-btn faz-amp-btn-reject"><?php echo esc_html( $reject_label ); ?></button>
						<?php endif; ?>
						<?php if ( $save_enabled && ! empty( $purposes ) ) : ?>
							<button on="tap:faz-amp-consent.accept(purposeConsentDefault=false)" class="faz-amp-btn faz-amp-btn-save"><?php echo esc_html( $save_label ); ?></button>
						<?php endif; ?>
					</div>
					<a href="<?php echo esc_url( $privacy_url ); ?>" class="faz-amp-link"><?php echo esc_html( $settings_label ); ?></a>
				</div>
			</div>

			<?php if ( $revisit_enabled ) : ?>
				<div id="faz-amp-post-consent" class="faz-amp-revisit">
					<button on="tap:faz-amp-consent.prompt" class="faz-amp-revisit-btn" aria-label="<?php esc_attr_e( 'Manage cookie preferences', 'faz-cookie-manager' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
					</button>
				</div>
			<?php endif; ?>
		</amp-consent>
		<?php
	}

	/**
	 * Start the late AMP markup pass that applies purpose blocking.
	 *
	 * @return void
	 */
	public function start_component_blocking_buffer() {
		$settings = get_option( 'faz_settings', array() );
		if ( empty( $settings['banner_control']['status'] ) || false === $this->get_active_banner() ) {
			return;
		}
		ob_start( array( $this, 'apply_component_blocking' ) );
	}

	/**
	 * Add consent-purpose attributes to AMP components FAZ can classify.
	 *
	 * This pass deliberately has a narrow boundary. It covers common AMP
	 * analytics, advertising and embedded-media components and exposes a filter
	 * for site-specific extensions. It does not claim to control opaque markup
	 * emitted after this buffer, requests made server-side, or a third-party AMP
	 * component that ignores amp-consent. Existing publisher policies always win.
	 *
	 * @param string $html Complete AMP response.
	 * @return string
	 */
	public function apply_component_blocking( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		$purposes      = AMP_Consent_Rest::get_purposes();
		$purpose_args  = AMP_Consent_Rest::purpose_action_args( $purposes );
		$purpose_slugs = array_keys( $purpose_args );
		$purpose_ids   = array_values( $purpose_args );
		$map = array(
			'amp-analytics'     => 'analytics',
			'amp-pixel'         => 'analytics',
			'amp-experiment'    => 'analytics',
			'amp-ad'            => 'marketing',
			// amp-embed is the DOCUMENTED ALIAS of amp-ad, and it is how Taboola
			// and Outbrain embeds are conventionally written
			// (<amp-embed type="taboola">). Leaving it out meant advertising
			// rendered before consent on exactly the placements most likely to
			// carry it, while the feature claimed advertising was blocked.
			'amp-embed'         => 'marketing',
			'amp-auto-ads'       => 'marketing',
			'amp-sticky-ad'     => 'marketing',
			// Injects amp-ad into stories, so it inherits the advertising gate.
			'amp-story-auto-ads' => 'marketing',
			'amp-call-tracking' => 'marketing',
			'amp-iframe'        => 'functional',
			'amp-youtube'       => 'functional',
			'amp-vimeo'         => 'functional',
			'amp-twitter'       => 'functional',
			'amp-facebook'      => 'functional',
			'amp-instagram'     => 'functional',
			'amp-pinterest'     => 'functional',
			// Third-party players. Each loads a remote SDK that can set
			// identifiers; several are ad-monetised, but they are gated as
			// functional because a visitor embedding one has asked for the
			// content. A publisher who wants them stricter can re-map any entry
			// through faz_amp_component_purpose_map.
			'amp-brightcove'    => 'functional',
			'amp-dailymotion'   => 'functional',
			'amp-jwplayer'      => 'functional',
			'amp-soundcloud'    => 'functional',
			'amp-tiktok'        => 'functional',
			'amp-reddit'        => 'functional',
			'amp-wistia-player' => 'functional',
			'amp-connatix-player' => 'functional',
			'amp-3q-player'     => 'functional',
			'amp-o2-player'     => 'functional',
			'amp-brid-player'   => 'functional',
			'amp-gfycat'        => 'functional',
			'amp-imgur'         => 'functional',
			'amp-vk'            => 'functional',
			'amp-yotpo'         => 'functional',
		);
		$map = (array) apply_filters( 'faz_amp_component_purpose_map', $map, $purpose_slugs );
		$parse_purposes = static function ( $value ) {
			$values = is_array( $value ) ? $value : explode( ',', (string) $value );
			$clean  = array();
			foreach ( $values as $candidate ) {
				if ( ! is_scalar( $candidate ) ) {
					continue;
				}
				$candidate = sanitize_key( trim( (string) $candidate ) );
				if ( '' !== $candidate && ! in_array( $candidate, $clean, true ) ) {
					$clean[] = $candidate;
				}
			}
			return $clean;
		};

		// Do not interpret serialized markup inside raw-text elements as live
		// components. AMP documents routinely carry application/json config,
		// JSON-LD and amp-state data whose string values may contain `<amp-*>`.
		// Injecting a quoted HTML attribute into those strings corrupts the JSON.
		// Comments, CDATA and the other HTML raw-text containers are equally not
		// part of the executable component tree.
		$raw_blocks = array();
		$raw_prefix = '__FAZ_AMP_RAW_' . hash( 'sha256', $html ) . '_';
		while ( false !== strpos( $html, $raw_prefix ) ) {
			$raw_prefix .= '_';
		}
		$masked_html = preg_replace_callback(
			'#<!--.*?(?:-->|$)|<!\[CDATA\[.*?(?:\]\]>|$)|<(script|style|textarea|title)\b[^>]*>.*?(?:</\1\s*>|$)#is',
			static function ( $match ) use ( &$raw_blocks, $raw_prefix ) {
				$placeholder                = $raw_prefix . count( $raw_blocks ) . '__';
				$raw_blocks[ $placeholder ] = $match[0];
				return $placeholder;
			},
			$html
		);
		if ( null === $masked_html ) {
			return $html;
		}

		$result = preg_replace_callback(
			'#<(amp-[a-z0-9-]+)\b([^>]*)>#i',
			function ( $match ) use ( $map, $purpose_ids, $purpose_slugs, $purpose_args, $parse_purposes ) {
				$tag   = strtolower( $match[1] );
				$attrs = $match[2];
				// The trailing "/" belongs in the terminator class. AMP custom
				// elements are never validly self-closed, but the buffer carries
				// whatever the theme emitted, and on <amp-ad data-block-on-consent/>
				// the attribute is followed by a solidus: without it the publisher's
				// own policy went unseen and a SECOND blocking attribute was injected
				// beside it — a duplicate attribute, which is invalid markup in a
				// place that was previously only sloppy.
				if (
					'amp-consent' === $tag
					|| preg_match( '#\sdata-block-on-consent(?:-purposes)?(?:\s|=|/|$)#i', $attrs )
				) {
					return $match[0];
				}

				// Site-owned/custom AMP components can declare their category at
				// the markup boundary without requiring a PHP filter. Preserve the
				// declaration for diagnostics and translate it into AMP's native
				// blocking attribute. data-faz-purpose is the more specific alias.
				$explicit = null;
				if ( preg_match( '/\sdata-faz-(?:purpose|category)\s*=\s*(["\'])(.*?)\1/i', $attrs, $declared ) ) {
					$explicit = $declared[2];
				}
				if ( null === $explicit && ! isset( $map[ $tag ] ) ) {
					return $match[0];
				}
				$requested = $parse_purposes( null !== $explicit ? $explicit : $map[ $tag ] );
				if ( empty( $requested ) || array( 'necessary' ) === $requested ) {
					return $match[0];
				}
				$requested         = array_values( array_diff( $requested, array( 'necessary' ) ) );
				$valid_slugs       = array_values( array_intersect( $requested, $purpose_slugs ) );
				$valid_purpose_ids = array();
				foreach ( $valid_slugs as $valid_slug ) {
					if ( isset( $purpose_args[ $valid_slug ] ) ) {
						$valid_purpose_ids[] = $purpose_args[ $valid_slug ];
					}
				}
				if ( ! empty( $valid_purpose_ids ) ) {
					// Gate on the categories this site actually offers. A requested
					// purpose the site does not configure is dropped rather than
					// carried into the attribute: no visitor could grant it, so
					// naming it would block the component permanently.
					$attribute = ' data-block-on-consent-purposes="' . esc_attr( implode( ',', $valid_purpose_ids ) ) . '"';
				} elseif ( ! empty( $purpose_ids ) ) {
					// Nothing the visitor can answer maps to this component — the usual
					// case is a default category ("functional") the admin hid or deleted
					// while amp-iframe/amp-youtube still map to it.
					//
					// Gating on every configured purpose is clumsy: a YouTube embed ends
					// up waiting on marketing AND analytics, categories that have nothing
					// to do with it, so a visitor who granted exactly what the embed needs
					// still sees nothing. An earlier revision of this branch replaced it
					// with data-block-on-consent="_till_accepted" for that reason.
					//
					// That is not safe here, and the direction of the trade is what
					// settles it. _till_accepted unblocks on ACCEPTED, and the Save
					// button sends accept(purposeConsentDefault=false) — so a visitor who
					// opens preferences, ticks nothing and saves would load the component
					// on a decision that granted no category at all. Over-blocking is a
					// usability complaint with an obvious remedy; under-blocking is a
					// tracker running without consent. In a consent plugin those are not
					// comparable costs, so the clumsy gate stays.
					//
					// The real remedy is one admin action: configure a category for the
					// component, which is also the only way a visitor can be asked about
					// it in the first place.
					$attribute = ' data-block-on-consent-purposes="' . esc_attr( implode( ',', $purpose_ids ) ) . '"';
				} else {
					// The site offers no optional category at all, so there is no purpose
					// to name. This is the pre-existing fallback and the only gate left.
					$attribute = ' data-block-on-consent="_till_accepted"';
				}
				return '<' . $match[1] . $attribute . $attrs . '>';
			},
			$masked_html
		);

		return null === $result ? $html : strtr( $result, $raw_blocks );
	}

	/**
	 * Return the active banner resolved for the current visitor country.
	 *
	 * Applies the same two geo guards Frontend::load_banner() uses for the
	 * classic JS flow so AMP visitors don't see a banner the standard flow
	 * would have suppressed: (1) global geo-targeting (Settings →
	 * Geolocation, default_behavior=no_banner outside target_regions);
	 * (2) per-banner ruleSet (settings.ruleSet entries restricted to
	 * EU/US/OTHER country sets).
	 *
	 * When Cache Compatibility Mode is effectively active, AMP follows the classic frontend's
	 * cache-safe baseline: no visitor-country lookup, no geo suppression, and
	 * neutral banner selection. Runtime ruleset enforcement disables that mode
	 * because the response must vary by jurisdiction. Otherwise an AMP cache can store a no-banner or
	 * country-specific render and serve it to visitors from another region.
	 *
	 * @return \FazCookie\Admin\Modules\Banners\Includes\Banner|false
	 */
	private function get_active_banner() {
		$cache_compatibility = $this->is_cache_compatibility_enabled();
		$country             = $cache_compatibility ? '' : Geolocation::get_visitor_country();

		// Guard 1 — global geo-targeting from Settings → Geolocation.
		if ( ! $cache_compatibility && $this->is_geo_banner_disabled( $country ) ) {
			return false;
		}

		$banner = Controller::get_instance()->get_active_banner_for_country( $country );
		if ( ! $banner ) {
			return false;
		}

		$ruleset = ( $cache_compatibility || ! class_exists( Geo_Runtime::class ) ) ? null : Geo_Runtime::resolve_for_country( $country );
		if ( null !== $ruleset ) {
			$wanted_law = Geo_Runtime::model_to_law( $ruleset );
			if ( $wanted_law !== $banner->get_law() ) {
				$law_banner = Controller::get_instance()->get_active_banner_for_law( $wanted_law, $country );
				if ( $law_banner ) {
					$banner = $law_banner;
				} elseif ( 'gdpr' === $wanted_law ) {
					// Do not show an opt-out notice for an opt-in jurisdiction.
					return false;
				}
			}
			$banner->set_settings( Geo_Runtime::apply_ui_requirements( $ruleset, $banner->get_settings() ) );
		}

		// Guard 2 — per-banner ruleSet (matches Frontend::is_geo_blocked()).
		if ( ! $cache_compatibility && $this->is_banner_geo_blocked( $banner, $country ) ) {
			return false;
		}
		return $banner;
	}

	/**
	 * Mirror of Frontend::is_geo_banner_disabled() for the AMP code path.
	 *
	 * Returns true when global geo-targeting is on, default_behavior is
	 * "no_banner", and the visitor's country is not in target_regions.
	 *
	 * @param string $country Visitor country code or '' if unknown.
	 * @return bool
	 */
	private function is_geo_banner_disabled( $country ) {
		$settings = get_option( 'faz_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['geolocation']['geo_targeting'] ) ) {
			return false;
		}
		if ( empty( $country ) ) {
			return false; // fail-open when country can't be resolved.
		}
		$default_behavior = isset( $settings['geolocation']['default_behavior'] )
			? $settings['geolocation']['default_behavior']
			: 'show_banner';
		if ( 'no_banner' !== $default_behavior ) {
			return false;
		}
		$target_regions = isset( $settings['geolocation']['target_regions'] )
			? (array) $settings['geolocation']['target_regions']
			: array( 'eu', 'uk' );
		return ! self::country_in_regions( $country, $target_regions );
	}

	/**
	 * Mirror of Frontend::is_geo_blocked() for the AMP code path.
	 *
	 * Iterates every ruleSet entry and returns true when no rule matches
	 * the visitor (the banner would be blocked in the classic flow too).
	 *
	 * @param \FazCookie\Admin\Modules\Banners\Includes\Banner $banner  Banner.
	 * @param string                                           $country Visitor country code.
	 * @return bool
	 */
	private function is_banner_geo_blocked( $banner, $country ) {
		$settings = $banner->get_settings();
		$inner    = isset( $settings['settings'] ) && is_array( $settings['settings'] ) ? $settings['settings'] : array();
		$rules    = isset( $inner['ruleSet'] ) && is_array( $inner['ruleSet'] ) ? $inner['ruleSet'] : array();
		if ( empty( $rules ) ) {
			return false;
		}
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$code = isset( $rule['code'] ) ? strtoupper( (string) $rule['code'] ) : 'ALL';
			if ( 'ALL' === $code ) {
				return false; // ALL matches everyone.
			}
			if ( '' === $country ) {
				continue;
			}
			if ( 'EU' === $code && in_array( $country, Geolocation::$eu_countries, true ) ) {
				return false;
			}
			if ( 'US' === $code && 'US' === $country ) {
				return false;
			}
			if ( 'OTHER' === $code ) {
				$regions = isset( $rule['regions'] ) ? array_map( 'strtoupper', (array) $rule['regions'] ) : array();
				if ( in_array( $country, $regions, true ) ) {
					return false;
				}
			}
		}
		// No rule matched. Fail-open if country was unknown — losing the
		// geo signal must not silently hide a consent surface.
		return ! empty( $country );
	}

	/**
	 * Compact region-set lookup.
	 *
	 * This is no longer a hand-kept mirror of Frontend::is_country_in_regions().
	 * Both now delegate to faz_country_in_regions() (includes/class-utils.php),
	 * which reads the one region table in faz_region_map(). The mirror this
	 * method used to be had already drifted — the 'za' bucket existed only on
	 * the Frontend side — which is why the copy is gone rather than corrected.
	 *
	 * The one deliberate difference that remains is on the Frontend side: it
	 * runs the `faz_is_target_region` filter over the unmatched result. AMP
	 * pages have never exposed that filter and still do not.
	 *
	 * @param string $country_code ISO 3166-1 alpha-2 country code.
	 * @param array  $regions      List of region keys ('eu', 'uk', 'us', ...) or direct country codes.
	 * @return bool
	 */
	private static function country_in_regions( $country_code, $regions ) {
		return faz_country_in_regions( $country_code, $regions );
	}
}
