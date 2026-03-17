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

		// AMP boilerplate script in head.
		add_action( 'amp_post_template_head', array( $this, 'output_amp_boilerplate' ) );
		add_action( 'wp_head', array( $this, 'output_amp_boilerplate' ) );

		// AMP custom CSS in head (AMP requires <style amp-custom> in <head>).
		add_action( 'amp_post_template_head', array( $this, 'output_amp_styles' ) );
		add_action( 'wp_head', array( $this, 'output_amp_styles' ) );

		// AMP consent component in footer.
		add_action( 'amp_post_template_footer', array( $this, 'output_amp_consent' ) );
		add_action( 'wp_footer', array( $this, 'output_amp_consent' ) );
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
			'faz-cookie-manager-tcf-cmp',
			'faz-fw-tcf-cmp',
			'faz-cookie-manager-wca',
			'faz-cookie-manager-microsoft-consent',
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

		$banner = Controller::get_instance()->get_active_banner();
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
			.faz-amp-actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px}
			.faz-amp-btn{padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:500;cursor:pointer}
			.faz-amp-btn-accept{background:<?php echo esc_attr( $c['accept_bg'] ); ?>;color:<?php echo esc_attr( $c['accept_color'] ); ?>}
			.faz-amp-btn-reject{background:<?php echo esc_attr( $c['reject_bg'] ); ?>;color:<?php echo esc_attr( $c['reject_color'] ); ?>;border:1px solid <?php echo esc_attr( $c['reject_border'] ); ?>}
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

		// Load the active banner via the existing controller.
		$banner = Controller::get_instance()->get_active_banner();
		if ( false === $banner ) {
			return;
		}

		$banner_settings = $banner->get_settings();
		$banner_contents = $banner->get_contents();

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
		$accept_label   = ! empty( $btn['accept'] ) ? wp_strip_all_tags( $btn['accept'] ) : __( 'Accept All', 'faz-cookie-manager' );
		$reject_label   = ! empty( $btn['reject'] ) ? wp_strip_all_tags( $btn['reject'] ) : __( 'Reject All', 'faz-cookie-manager' );
		$settings_label = ! empty( $btn['readMore'] ) ? wp_strip_all_tags( $btn['readMore'] ) : __( 'Cookie Policy', 'faz-cookie-manager' );

		// Cookie policy link.
		$privacy_url = ! empty( $notice['privacyLink'] ) ? $notice['privacyLink'] : '/cookie-policy';

		// Consent expiry (days).
		$expiry_days = 365;
		if ( isset( $banner_settings['config']['consentExpiry']['value'] ) ) {
			$expiry_days = absint( $banner_settings['config']['consentExpiry']['value'] );
		}

		// Build consent config.
		$consent_config = array(
			'consentInstanceId' => 'faz-cookie-consent',
			'consentRequired'   => true,
			'promptUI'          => 'faz-amp-consent-ui',
			'postPromptUI'      => 'faz-amp-post-consent',
		);

		// Build outer consents wrapper.
		$amp_config = array( 'consents' => array( 'faz' => $consent_config ) );

		// GCM integration for AMP.
		$gcm_settings = get_option( 'faz_gcm_settings' );
		if ( ! empty( $gcm_settings['status'] ) ) {
			$amp_config['consents']['faz']['gtagServices'] = array(
				'default_consent' => array(
					'analytics_storage' => 'denied',
					'ad_storage'        => 'denied',
					'ad_user_data'      => 'denied',
					'ad_personalization' => 'denied',
				),
			);
		}

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
					<div class="faz-amp-actions">
						<button on="tap:faz-amp-consent.accept" class="faz-amp-btn faz-amp-btn-accept"><?php echo esc_html( $accept_label ); ?></button>
						<button on="tap:faz-amp-consent.reject" class="faz-amp-btn faz-amp-btn-reject"><?php echo esc_html( $reject_label ); ?></button>
					</div>
					<a href="<?php echo esc_url( $privacy_url ); ?>" class="faz-amp-link"><?php echo esc_html( $settings_label ); ?></a>
				</div>
			</div>

			<div id="faz-amp-post-consent" class="faz-amp-revisit">
				<button on="tap:faz-amp-consent.prompt" class="faz-amp-revisit-btn" aria-label="<?php esc_attr_e( 'Manage cookie preferences', 'faz-cookie-manager' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
				</button>
			</div>
		</amp-consent>
		<?php
	}
}
