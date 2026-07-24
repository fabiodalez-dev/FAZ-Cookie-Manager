<?php
/**
 * FAZ Cookie Manager — Guided Setup Wizard
 *
 * Server-rendered multi-step form (spezzone loaded by base.php — no div.wrap).
 * Step navigation, the scan trigger, the recommendation badges, and the finish
 * call are wired in admin/assets/js/pages/setup.js. Every value echoed here is
 * escaped and every user-facing string is translatable.
 *
 * Steps: 1 law · 2 language · 3 banner options · 4 consent signals (GCM /
 * Microsoft) · 5 IAB TCF · 6 geo targeting · 7 cookie scan + payment fixes ·
 * 8 review & finish. Every step beyond the law is optional and defaults to the
 * current stored value, so skipping straight to Finish reproduces the previous
 * 3-step wizard behaviour exactly.
 *
 * @package FazCookie\Admin
 */

defined( 'ABSPATH' ) || exit;

// Pre-select the jurisdiction from a previous run (wizard re-entry), else the
// most-protective compliant default (GDPR opt-in). The compliant option is
// pre-checked; no option is labelled "recommended" in a nudging way.
$faz_onboarding_law = '';
$faz_site_lang      = 'en';
if ( class_exists( '\\FazCookie\\Admin\\Modules\\Settings\\Includes\\Onboarding' ) ) {
	$faz_onboarding_helper = new \FazCookie\Admin\Modules\Settings\Includes\Onboarding();
	$faz_onboarding_law    = $faz_onboarding_helper->get_law();
	$faz_site_lang         = \FazCookie\Admin\Modules\Settings\Includes\Onboarding::site_language();
}
if ( '' === $faz_onboarding_law ) {
	$faz_onboarding_law = 'gdpr';
}

// Current stored settings pre-fill the optional steps (wizard re-entry keeps
// what the admin already configured elsewhere).
$faz_wiz_settings = array();
if ( class_exists( '\\FazCookie\\Admin\\Modules\\Settings\\Includes\\Settings' ) ) {
	$faz_wiz_settings_obj = new \FazCookie\Admin\Modules\Settings\Includes\Settings();
	$faz_wiz_settings     = $faz_wiz_settings_obj->get();
}
$faz_wiz_bc   = isset( $faz_wiz_settings['banner_control'] ) && is_array( $faz_wiz_settings['banner_control'] ) ? $faz_wiz_settings['banner_control'] : array();
$faz_wiz_ms   = isset( $faz_wiz_settings['microsoft'] ) && is_array( $faz_wiz_settings['microsoft'] ) ? $faz_wiz_settings['microsoft'] : array();
$faz_wiz_iab  = isset( $faz_wiz_settings['iab'] ) && is_array( $faz_wiz_settings['iab'] ) ? $faz_wiz_settings['iab'] : array();
$faz_wiz_geo  = isset( $faz_wiz_settings['geolocation'] ) && is_array( $faz_wiz_settings['geolocation'] ) ? $faz_wiz_settings['geolocation'] : array();
$faz_wiz_lang = isset( $faz_wiz_settings['languages']['default'] ) && is_string( $faz_wiz_settings['languages']['default'] ) && '' !== $faz_wiz_settings['languages']['default']
	? $faz_wiz_settings['languages']['default']
	: $faz_site_lang;

$faz_wiz_gcm_settings = get_option( 'faz_gcm_settings', array() );
$faz_wiz_gcm_on       = is_array( $faz_wiz_gcm_settings ) && ! empty( $faz_wiz_gcm_settings['status'] );

// Language catalogue for the language step (Label => code), split into the
// languages whose banner translation actually ships with the plugin and the
// rest, which fall back to English until translated on the Languages page.
// The bundled list comes from the real files on disk (not the faz_translated
// constant, which has historically drifted from what is shipped).
$faz_wiz_languages = array( 'English' => 'en' );
if ( class_exists( '\\FazCookie\\Admin\\Modules\\Languages\\Includes\\Controller' ) ) {
	$faz_wiz_languages = \FazCookie\Admin\Modules\Languages\Includes\Controller::get_instance()->get_languages();
}
$faz_wiz_bundled = array();
foreach ( (array) glob( FAZ_PLUGIN_BASEPATH . 'admin/modules/banners/includes/contents/*.json' ) as $faz_wiz_lang_file ) {
	$faz_wiz_code = basename( $faz_wiz_lang_file, '.json' );
	if ( 'default' !== $faz_wiz_code ) {
		$faz_wiz_bundled[ $faz_wiz_code ] = true;
	}
}
$faz_wiz_langs_bundled = array();
$faz_wiz_langs_other   = array();
foreach ( $faz_wiz_languages as $faz_wiz_label => $faz_wiz_code ) {
	if ( isset( $faz_wiz_bundled[ $faz_wiz_code ] ) ) {
		$faz_wiz_langs_bundled[ $faz_wiz_label ] = $faz_wiz_code;
	} else {
		$faz_wiz_langs_other[ $faz_wiz_label ] = $faz_wiz_code;
	}
}

/**
 * The three jurisdiction choices, each with plain-language help text and the
 * concrete effect on the banner. Kept as data so the markup loop stays escaped.
 */
$faz_setup_laws = array(
	'gdpr' => array(
		'title'  => __( 'GDPR / ePrivacy (EU, EEA, UK)', 'faz-cookie-manager' ),
		'desc'   => __( 'For visitors in the European Union, EEA, or United Kingdom. Non-essential cookies stay blocked until the visitor actively opts in — nothing is pre-ticked, and Accept and Reject carry equal weight.', 'faz-cookie-manager' ),
		'effect' => __( 'Opt-in model: no cookie is set before consent.', 'faz-cookie-manager' ),
		'expiry' => __( 'Consent is remembered for up to 180 days.', 'faz-cookie-manager' ),
		'buttons' => __( 'Equal-weight Accept and Reject buttons are shown, with no pre-ticked categories.', 'faz-cookie-manager' ),
	),
	'ccpa' => array(
		'title'  => __( 'CCPA / US State laws', 'faz-cookie-manager' ),
		'desc'   => __( 'For visitors covered by California (CCPA/CPRA) and similar US state privacy laws. These follow an opt-out model: a first-party "Do Not Sell or Share My Personal Information" link lets visitors withdraw at any time.', 'faz-cookie-manager' ),
		'effect' => __( 'Opt-out model: a "Do Not Sell or Share" link is added.', 'faz-cookie-manager' ),
		'expiry' => __( 'Consent is remembered for up to 365 days.', 'faz-cookie-manager' ),
		'buttons' => __( 'The notice shows the "Do Not Sell or Share" control instead of GDPR Accept and Reject controls.', 'faz-cookie-manager' ),
	),
	'both' => array(
		'title'  => __( 'Both (mixed EU + US audience)', 'faz-cookie-manager' ),
		'desc'   => __( 'For a site with both EU and US visitors. The more protective opt-in model governs the banner, so EU visitors are never downgraded — while the US "Do Not Sell or Share" opt-out link is still shown.', 'faz-cookie-manager' ),
		'effect' => __( 'Opt-in model plus the US "Do Not Sell or Share" link.', 'faz-cookie-manager' ),
		'expiry' => __( 'Consent is remembered for up to 180 days.', 'faz-cookie-manager' ),
		'buttons' => __( 'Equal-weight Accept and Reject buttons are shown, together with the US opt-out control.', 'faz-cookie-manager' ),
	),
);

/**
 * The banner-control switches offered on step 3. `data-recommend` marks the row
 * that receives the cache-plugin detection badge (filled by setup.js).
 */
$faz_setup_bc_rows = array(
	'per_service_consent' => array(
		'label' => __( 'Per-service consent toggles', 'faz-cookie-manager' ),
		'help'  => __( 'Let visitors accept or reject each detected service (YouTube, Google Analytics, …) individually inside the preference center, in addition to whole categories.', 'faz-cookie-manager' ),
	),
	'gtm_datalayer'       => array(
		'label' => __( 'Google Tag Manager dataLayer events', 'faz-cookie-manager' ),
		'help'  => __( 'Push consent choices to the GTM dataLayer so tags can react to consent without custom code.', 'faz-cookie-manager' ),
	),
	'hide_from_bots'      => array(
		'label' => __( 'Hide the banner from bots and crawlers', 'faz-cookie-manager' ),
		'help'  => __( 'Search engines and performance tools see the page without the banner. Recommended: keeps audits clean and has no effect on real visitors.', 'faz-cookie-manager' ),
	),
	'cache_compatibility' => array(
		'label' => __( 'Cache Compatibility Mode', 'faz-cookie-manager' ),
		'help'  => __( 'Keeps every rendered page identical for all visitors so full-page caches (WP Rocket, LiteSpeed, …) can never serve one visitor\'s consent state to another. Turn on if you use a page-cache plugin or a caching CDN.', 'faz-cookie-manager' ),
	),
	'adblock_resilience'  => array(
		'label' => __( 'Ad-blocker banner resilience', 'faz-cookie-manager' ),
		'help'  => __( 'Re-asserts the legally required consent banner if an ad-block cosmetic filter hides it. It never blocks content and never forces interaction.', 'faz-cookie-manager' ),
	),
);

// Geo-targeting regions — must stay in sync with Onboarding::REGIONS and the
// Settings → Geolocation list.
$faz_setup_geo_regions = array(
	'eu' => __( 'EU / EEA', 'faz-cookie-manager' ),
	'uk' => __( 'United Kingdom', 'faz-cookie-manager' ),
	'us' => __( 'United States', 'faz-cookie-manager' ),
	'ca' => __( 'Canada', 'faz-cookie-manager' ),
	'br' => __( 'Brazil', 'faz-cookie-manager' ),
	'au' => __( 'Australia', 'faz-cookie-manager' ),
	'jp' => __( 'Japan', 'faz-cookie-manager' ),
	'ch' => __( 'Switzerland', 'faz-cookie-manager' ),
);
$faz_wiz_geo_selected  = isset( $faz_wiz_geo['target_regions'] ) && is_array( $faz_wiz_geo['target_regions'] ) ? $faz_wiz_geo['target_regions'] : array( 'eu', 'uk' );

$faz_setup_step_titles = array(
	1 => __( 'Applicable law', 'faz-cookie-manager' ),
	2 => __( 'Language', 'faz-cookie-manager' ),
	3 => __( 'Banner options', 'faz-cookie-manager' ),
	4 => __( 'Consent Mode', 'faz-cookie-manager' ),
	5 => __( 'IAB TCF', 'faz-cookie-manager' ),
	6 => __( 'Geo targeting', 'faz-cookie-manager' ),
	7 => __( 'Find cookies', 'faz-cookie-manager' ),
	8 => __( 'Review & finish', 'faz-cookie-manager' ),
);
?>
<div id="faz-setup">

	<ol class="faz-wizard-progress" aria-label="<?php esc_attr_e( 'Setup steps', 'faz-cookie-manager' ); ?>">
		<?php foreach ( $faz_setup_step_titles as $faz_step_num => $faz_step_title ) : ?>
			<li class="faz-wizard-progress-item<?php echo ( 1 === $faz_step_num ) ? ' is-active' : ''; ?>" data-progress="<?php echo esc_attr( $faz_step_num ); ?>">
				<span class="faz-wizard-progress-num"><?php echo esc_html( $faz_step_num ); ?></span>
				<span class="faz-wizard-progress-label"><?php echo esc_html( $faz_step_title ); ?></span>
			</li>
		<?php endforeach; ?>
	</ol>

	<div class="faz-card faz-setup-card">
		<div class="faz-card-body">

			<!-- Step 1 — Applicable law -->
			<section class="faz-wizard-step is-active" data-step="1" aria-labelledby="faz-setup-step1-title">
				<h2 id="faz-setup-step1-title" class="faz-setup-step-title" tabindex="-1"><?php esc_html_e( 'Which privacy law applies to your visitors?', 'faz-cookie-manager' ); ?></h2>
				<p class="faz-setup-step-lead"><?php esc_html_e( 'This sets a compliant baseline for your cookie banner. You can change every detail later on the Cookie Banner page.', 'faz-cookie-manager' ); ?></p>

				<fieldset class="faz-setup-law-group">
					<legend class="screen-reader-text"><?php esc_html_e( 'Applicable privacy law', 'faz-cookie-manager' ); ?></legend>
					<?php foreach ( $faz_setup_laws as $faz_law_key => $faz_law ) : ?>
						<label class="faz-setup-law-card<?php echo ( $faz_onboarding_law === $faz_law_key ) ? ' is-selected' : ''; ?>">
							<input type="radio" name="faz-setup-law" value="<?php echo esc_attr( $faz_law_key ); ?>" data-expiry="<?php echo esc_attr( $faz_law['expiry'] ); ?>" data-buttons="<?php echo esc_attr( $faz_law['buttons'] ); ?>"<?php checked( $faz_onboarding_law, $faz_law_key ); ?>>
							<span class="faz-setup-law-body">
								<span class="faz-setup-law-title"><?php echo esc_html( $faz_law['title'] ); ?></span>
								<span class="faz-setup-law-desc"><?php echo esc_html( $faz_law['desc'] ); ?></span>
								<span class="faz-setup-law-effect"><?php echo esc_html( $faz_law['effect'] ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>
				</fieldset>
			</section>

			<!-- Step 2 — Banner language -->
			<section class="faz-wizard-step" data-step="2" hidden aria-labelledby="faz-setup-step2-title">
				<h2 id="faz-setup-step2-title" class="faz-setup-step-title" tabindex="-1"><?php esc_html_e( 'Banner language', 'faz-cookie-manager' ); ?></h2>
				<p class="faz-setup-step-lead"><?php esc_html_e( 'The default language your cookie banner is shown in. It is pre-selected from your site language.', 'faz-cookie-manager' ); ?></p>

				<div class="faz-setup-field">
					<label for="faz-setup-lang"><?php esc_html_e( 'Default banner language', 'faz-cookie-manager' ); ?></label>
					<select id="faz-setup-lang" class="faz-select faz-setup-select">
						<optgroup label="<?php esc_attr_e( 'Banner translation included', 'faz-cookie-manager' ); ?>">
							<?php foreach ( $faz_wiz_langs_bundled as $faz_lang_label => $faz_lang_code ) : ?>
								<option value="<?php echo esc_attr( $faz_lang_code ); ?>"<?php selected( $faz_wiz_lang, $faz_lang_code ); ?>><?php echo esc_html( $faz_lang_label ); ?></option>
							<?php endforeach; ?>
						</optgroup>
						<optgroup label="<?php esc_attr_e( 'Shown in English until you translate it', 'faz-cookie-manager' ); ?>">
							<?php foreach ( $faz_wiz_langs_other as $faz_lang_label => $faz_lang_code ) : ?>
								<option value="<?php echo esc_attr( $faz_lang_code ); ?>" data-fallback="1"<?php selected( $faz_wiz_lang, $faz_lang_code ); ?>><?php echo esc_html( $faz_lang_label ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					</select>
					<p class="faz-setup-inline-note" id="faz-setup-lang-fallback-note" hidden><?php esc_html_e( 'No banner translation ships for this language yet: visitors will see the banner in English until you translate its texts on the Languages page.', 'faz-cookie-manager' ); ?></p>
				</div>

				<p class="faz-setup-scan-note">
					<?php esc_html_e( 'Additional languages, automatic visitor-language detection and per-language banner texts are managed on the Languages page afterwards.', 'faz-cookie-manager' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=faz-cookie-manager-languages' ) ); ?>"><?php esc_html_e( 'Open the Languages page', 'faz-cookie-manager' ); ?></a>.
				</p>
			</section>

			<!-- Step 3 — Banner options -->
			<section class="faz-wizard-step" data-step="3" hidden aria-labelledby="faz-setup-step3-title">
				<h2 id="faz-setup-step3-title" class="faz-setup-step-title" tabindex="-1"><?php esc_html_e( 'Banner options', 'faz-cookie-manager' ); ?> <span class="faz-setup-optional"><?php esc_html_e( '(optional)', 'faz-cookie-manager' ); ?></span></h2>
				<p class="faz-setup-step-lead"><?php esc_html_e( 'The most useful banner switches. Everything here can be changed later under Settings → Banner Control.', 'faz-cookie-manager' ); ?></p>

				<div class="faz-setup-toggle-list">
					<?php foreach ( $faz_setup_bc_rows as $faz_bc_key => $faz_bc_row ) : ?>
						<label class="faz-setup-toggle-row">
							<input type="checkbox" id="faz-setup-bc-<?php echo esc_attr( $faz_bc_key ); ?>" data-bc-key="<?php echo esc_attr( $faz_bc_key ); ?>"<?php checked( ! empty( $faz_wiz_bc[ $faz_bc_key ] ) ); ?>>
							<span class="faz-setup-toggle-body">
								<span class="faz-setup-toggle-label"><?php echo esc_html( $faz_bc_row['label'] ); ?>
									<?php if ( 'cache_compatibility' === $faz_bc_key ) : ?>
										<span class="faz-setup-badge" id="faz-setup-cache-badge" hidden></span>
									<?php endif; ?>
								</span>
								<span class="faz-setup-toggle-help"><?php echo esc_html( $faz_bc_row['help'] ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
				<!-- Interaction disclosure (#158): with Cache Compatibility Mode on, every
				     per-visitor render variation is disabled so cached pages stay identical
				     for everyone — including the bot-skip promised above. Shown by setup.js
				     whenever cache_compatibility is ticked together with hide_from_bots. -->
				<p class="faz-setup-inline-note" id="faz-setup-cache-note" hidden><?php esc_html_e( 'With Cache Compatibility Mode on, the bot-skip is bypassed: cached pages must stay identical for every visitor, so bots receive the banner too.', 'faz-cookie-manager' ); ?></p>
			</section>

			<!-- Step 4 — Consent signals (Google Consent Mode + Microsoft) -->
			<section class="faz-wizard-step" data-step="4" hidden aria-labelledby="faz-setup-step4-title">
				<h2 id="faz-setup-step4-title" class="faz-setup-step-title" tabindex="-1"><?php esc_html_e( 'Consent Mode signals', 'faz-cookie-manager' ); ?> <span class="faz-setup-optional"><?php esc_html_e( '(optional)', 'faz-cookie-manager' ); ?></span></h2>
				<p class="faz-setup-step-lead"><?php esc_html_e( 'Consent Mode tells Google and Microsoft tags what the visitor allowed, before any of their cookies are set. Turn it on if you use Google Analytics, Google Ads, Tag Manager, Bing UET or Clarity.', 'faz-cookie-manager' ); ?></p>

				<div class="faz-setup-toggle-list">
					<label class="faz-setup-toggle-row">
						<input type="checkbox" id="faz-setup-gcm"<?php checked( $faz_wiz_gcm_on ); ?>>
						<span class="faz-setup-toggle-body">
							<span class="faz-setup-toggle-label"><?php esc_html_e( 'Google Consent Mode v2', 'faz-cookie-manager' ); ?>
								<span class="faz-setup-badge" id="faz-setup-google-badge" hidden></span>
							</span>
							<span class="faz-setup-toggle-help"><?php esc_html_e( 'Sends the standard consent signals (ad_storage, analytics_storage, …). Every signal starts as denied and is upgraded only after the visitor consents.', 'faz-cookie-manager' ); ?></span>
						</span>
					</label>
					<label class="faz-setup-toggle-row">
						<input type="checkbox" id="faz-setup-ms-uet"<?php checked( ! empty( $faz_wiz_ms['uet_consent_mode'] ) ); ?>>
						<span class="faz-setup-toggle-body">
							<span class="faz-setup-toggle-label"><?php esc_html_e( 'Microsoft UET consent mode', 'faz-cookie-manager' ); ?></span>
							<span class="faz-setup-toggle-help"><?php esc_html_e( 'Sends consent signals to the Bing / Microsoft Advertising UET tag.', 'faz-cookie-manager' ); ?></span>
						</span>
					</label>
					<label class="faz-setup-toggle-row">
						<input type="checkbox" id="faz-setup-ms-clarity"<?php checked( ! empty( $faz_wiz_ms['clarity_consent'] ) ); ?>>
						<span class="faz-setup-toggle-body">
							<span class="faz-setup-toggle-label"><?php esc_html_e( 'Microsoft Clarity consent', 'faz-cookie-manager' ); ?></span>
							<span class="faz-setup-toggle-help"><?php esc_html_e( 'Gates Microsoft Clarity session recording behind the visitor\'s consent.', 'faz-cookie-manager' ); ?></span>
						</span>
					</label>
				</div>

				<p class="faz-setup-scan-note"><?php esc_html_e( 'Fine-tuning (region-specific defaults, URL passthrough, advanced mode) lives on the Google Consent Mode page.', 'faz-cookie-manager' ); ?></p>
			</section>

			<!-- Step 5 — IAB TCF -->
			<section class="faz-wizard-step" data-step="5" hidden aria-labelledby="faz-setup-step5-title">
				<h2 id="faz-setup-step5-title" class="faz-setup-step-title" tabindex="-1"><?php esc_html_e( 'IAB TCF framework', 'faz-cookie-manager' ); ?> <span class="faz-setup-optional"><?php esc_html_e( '(optional)', 'faz-cookie-manager' ); ?></span></h2>
				<p class="faz-setup-step-lead"><?php esc_html_e( 'Only for publishers who serve ads through vendors that require the IAB Transparency & Consent Framework (e.g. AdSense / Ad Manager in the EEA). It requires a CMP ID registered with IAB Europe — without one, leave this off.', 'faz-cookie-manager' ); ?></p>

				<div class="faz-setup-toggle-list">
					<label class="faz-setup-toggle-row">
						<input type="checkbox" id="faz-setup-tcf"<?php checked( ! empty( $faz_wiz_iab['enabled'] ) ); ?>>
						<span class="faz-setup-toggle-body">
							<span class="faz-setup-toggle-label"><?php esc_html_e( 'Enable IAB TCF v2.3 support', 'faz-cookie-manager' ); ?></span>
							<span class="faz-setup-toggle-help"><?php esc_html_e( 'Adds the TC string and the __tcfapi interface the ad vendors read.', 'faz-cookie-manager' ); ?></span>
						</span>
					</label>
				</div>

				<div class="faz-setup-field-grid" id="faz-setup-tcf-fields">
					<div class="faz-setup-field">
						<label for="faz-setup-tcf-cmpid"><?php esc_html_e( 'CMP ID', 'faz-cookie-manager' ); ?></label>
						<input type="number" min="2" max="4095" step="1" id="faz-setup-tcf-cmpid" class="faz-input faz-setup-input" value="<?php echo esc_attr( ! empty( $faz_wiz_iab['cmp_id'] ) ? absint( $faz_wiz_iab['cmp_id'] ) : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. 300', 'faz-cookie-manager' ); ?>">
					</div>
					<div class="faz-setup-field">
						<label for="faz-setup-tcf-cc"><?php esc_html_e( 'Publisher country (2 letters)', 'faz-cookie-manager' ); ?></label>
						<input type="text" maxlength="2" pattern="[A-Za-z]{2}" id="faz-setup-tcf-cc" class="faz-input faz-setup-input" value="<?php echo esc_attr( isset( $faz_wiz_iab['publisher_cc'] ) ? $faz_wiz_iab['publisher_cc'] : '' ); ?>" placeholder="IT">
					</div>
				</div>
				<p class="faz-setup-inline-error" id="faz-setup-tcf-error" role="alert" hidden><?php esc_html_e( 'Enter your registered CMP ID (between 2 and 4095) to enable IAB TCF, or turn the toggle off.', 'faz-cookie-manager' ); ?></p>
				<p class="faz-setup-inline-error" id="faz-setup-tcf-cc-error" role="alert" hidden><?php esc_html_e( 'The publisher country must be a 2-letter code (e.g. IT, DE), or leave it empty.', 'faz-cookie-manager' ); ?></p>
			</section>

			<!-- Step 6 — Geo targeting -->
			<section class="faz-wizard-step" data-step="6" hidden aria-labelledby="faz-setup-step6-title">
				<h2 id="faz-setup-step6-title" class="faz-setup-step-title" tabindex="-1"><?php esc_html_e( 'Geo targeting', 'faz-cookie-manager' ); ?> <span class="faz-setup-optional"><?php esc_html_e( '(optional)', 'faz-cookie-manager' ); ?></span></h2>
				<p class="faz-setup-step-lead"><?php esc_html_e( 'Show the cookie banner only to visitors from the regions where a consent law applies. The safest choice — showing it to everyone — is the default.', 'faz-cookie-manager' ); ?></p>

				<div class="faz-setup-toggle-list">
					<label class="faz-setup-toggle-row">
						<input type="checkbox" id="faz-setup-geo"<?php checked( ! empty( $faz_wiz_geo['geo_targeting'] ) ); ?>>
						<span class="faz-setup-toggle-body">
							<span class="faz-setup-toggle-label"><?php esc_html_e( 'Limit the banner to selected regions', 'faz-cookie-manager' ); ?></span>
							<span class="faz-setup-toggle-help"><?php esc_html_e( 'Requires a way to know the visitor\'s country: a MaxMind GeoLite2 database (Settings → GeoIP Database), or the Cloudflare country header (only if your developer enables the faz_trust_cf_ipcountry_header filter). Without one, every visitor is treated as in-region and sees the banner.', 'faz-cookie-manager' ); ?></span>
						</span>
					</label>
				</div>

				<div id="faz-setup-geo-fields">
					<div class="faz-setup-field">
						<span class="faz-setup-field-label"><?php esc_html_e( 'Regions that see the banner', 'faz-cookie-manager' ); ?></span>
						<div class="faz-setup-region-grid">
							<?php foreach ( $faz_setup_geo_regions as $faz_region_code => $faz_region_label ) : ?>
								<label class="faz-setup-region-chip">
									<input type="checkbox" name="faz-setup-geo-region" value="<?php echo esc_attr( $faz_region_code ); ?>"<?php checked( in_array( $faz_region_code, $faz_wiz_geo_selected, true ) ); ?>>
									<?php echo esc_html( $faz_region_label ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="faz-setup-field">
						<label for="faz-setup-geo-behavior"><?php esc_html_e( 'Visitors from other regions', 'faz-cookie-manager' ); ?></label>
						<select id="faz-setup-geo-behavior" class="faz-select faz-setup-select">
							<option value="show_banner"<?php selected( ! isset( $faz_wiz_geo['default_behavior'] ) || 'no_banner' !== $faz_wiz_geo['default_behavior'] ); ?>><?php esc_html_e( 'Still show the banner (safest)', 'faz-cookie-manager' ); ?></option>
							<option value="no_banner"<?php selected( isset( $faz_wiz_geo['default_behavior'] ) && 'no_banner' === $faz_wiz_geo['default_behavior'] ); ?>><?php esc_html_e( 'Hide the banner and allow cookies', 'faz-cookie-manager' ); ?></option>
						</select>
					</div>
				</div>
			</section>

			<!-- Step 7 — Find cookies (optional) + payment compatibility -->
			<section class="faz-wizard-step" data-step="7" hidden aria-labelledby="faz-setup-step7-title">
				<h2 id="faz-setup-step7-title" class="faz-setup-step-title" tabindex="-1"><?php esc_html_e( 'Find your cookies', 'faz-cookie-manager' ); ?> <span class="faz-setup-optional"><?php esc_html_e( '(optional)', 'faz-cookie-manager' ); ?></span></h2>
				<p class="faz-setup-step-lead"><?php esc_html_e( 'A quick server-side scan looks at a sample of your pages to discover cookies and categorise them automatically. It is optional — you can always run it later, and finishing setup never waits for it.', 'faz-cookie-manager' ); ?></p>

				<div class="faz-setup-scan">
					<button type="button" class="faz-btn faz-btn-secondary" id="faz-setup-scan-btn">
						<span class="dashicons dashicons-search" aria-hidden="true"></span>
						<?php esc_html_e( 'Run a quick scan', 'faz-cookie-manager' ); ?>
					</button>
					<div class="faz-setup-scan-progress" id="faz-setup-scan-progress" role="progressbar" aria-label="<?php esc_attr_e( 'Scan progress', 'faz-cookie-manager' ); ?>" hidden>
						<div class="faz-setup-scan-bar"></div>
					</div>
					<p class="faz-setup-scan-status" id="faz-setup-scan-status" role="status" aria-live="polite"></p>
				</div>

				<p class="faz-setup-scan-note">
					<?php esc_html_e( 'A quick scan may not catch cookies set later by JavaScript.', 'faz-cookie-manager' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=faz-cookie-manager-cookies' ) ); ?>"><?php esc_html_e( 'Do a full browser scan on the Cookies page', 'faz-cookie-manager' ); ?></a>.
				</p>

				<!-- Payment compatibility recommendations (revealed by setup.js when
				     a gateway is detected via active plugins or scanned cookies). -->
				<div id="faz-setup-payments" class="faz-setup-payments" hidden>
					<h3 class="faz-setup-subtitle"><?php esc_html_e( 'Payment services detected', 'faz-cookie-manager' ); ?></h3>
					<p class="faz-setup-step-lead"><?php esc_html_e( 'These payment providers were detected on your site. Ticking one always allows its scripts and cookies — payment SDKs are strictly necessary for checkout, but enable this only where payments genuinely happen outside a WooCommerce checkout.', 'faz-cookie-manager' ); ?></p>
					<p class="faz-setup-scan-note" id="faz-setup-payments-wc-note" hidden><?php esc_html_e( 'WooCommerce checkout and cart pages already allow payment scripts automatically — no opt-in needed for those pages.', 'faz-cookie-manager' ); ?></p>
					<div class="faz-setup-toggle-list" id="faz-setup-payments-list"></div>
				</div>
			</section>

			<!-- Step 8 — Review & finish -->
			<section class="faz-wizard-step" data-step="8" hidden aria-labelledby="faz-setup-step8-title">
				<h2 id="faz-setup-step8-title" class="faz-setup-step-title" tabindex="-1"><?php esc_html_e( 'Review & finish', 'faz-cookie-manager' ); ?></h2>
				<p class="faz-setup-step-lead"><?php esc_html_e( 'Here is what will be applied. These are compliant defaults — the controls match the selected consent model and no categories are pre-ticked.', 'faz-cookie-manager' ); ?></p>

				<ul class="faz-setup-review" id="faz-setup-review"
					data-label-law="<?php esc_attr_e( 'Applicable law', 'faz-cookie-manager' ); ?>"
					data-label-effect="<?php esc_attr_e( 'Consent model', 'faz-cookie-manager' ); ?>"
					data-label-expiry="<?php esc_attr_e( 'Consent expiry', 'faz-cookie-manager' ); ?>"
					data-label-language="<?php esc_attr_e( 'Banner language', 'faz-cookie-manager' ); ?>"
					data-label-options="<?php esc_attr_e( 'Enabled options', 'faz-cookie-manager' ); ?>"
					data-label-disabled="<?php esc_attr_e( 'Being turned off', 'faz-cookie-manager' ); ?>"
					data-label-geo="<?php esc_attr_e( 'Geo targeting', 'faz-cookie-manager' ); ?>"
					data-label-payments="<?php esc_attr_e( 'Payment compatibility', 'faz-cookie-manager' ); ?>"
					data-geo-others-shown="<?php esc_attr_e( 'other regions: banner still shown', 'faz-cookie-manager' ); ?>"
					data-geo-others-hidden="<?php esc_attr_e( 'other regions: banner hidden, cookies allowed', 'faz-cookie-manager' ); ?>"
					data-geo-default-regions="<?php esc_attr_e( 'EU / EEA, United Kingdom (default)', 'faz-cookie-manager' ); ?>"
					data-warn-cache-geo="<?php esc_attr_e( 'Note: Cache Compatibility Mode is also on, so server-side geo gating is bypassed — the same cached page is served to every region.', 'faz-cookie-manager' ); ?>"
					data-warn-cache-tcf="<?php esc_attr_e( 'Note: Cache Compatibility Mode with IAB TCF serves the same cached page to every region (e.g. an EU gdprApplies=true page to a US visitor) — consider keeping one of the two off, or vary the cache by country at the CDN.', 'faz-cookie-manager' ); ?>"
					data-logging="<?php esc_attr_e( 'Consent logging stays on for accountability.', 'faz-cookie-manager' ); ?>"></ul>

				<p class="faz-setup-review-note"><?php esc_html_e( 'Consent logging is kept on for accountability, and your cookie banner will be shown to visitors. You can adjust anything afterwards on the Cookie Banner and Settings pages.', 'faz-cookie-manager' ); ?></p>
			</section>

			<!-- Wizard navigation -->
			<div class="faz-setup-nav">
				<button type="button" class="faz-btn faz-btn-secondary" id="faz-setup-back" hidden><?php esc_html_e( 'Back', 'faz-cookie-manager' ); ?></button>
				<div class="faz-setup-nav-spacer"></div>
				<button type="button" class="faz-btn faz-btn-primary" id="faz-setup-next"><?php esc_html_e( 'Next', 'faz-cookie-manager' ); ?></button>
				<button type="button" class="faz-btn faz-btn-primary" id="faz-setup-finish" hidden><?php esc_html_e( 'Finish setup', 'faz-cookie-manager' ); ?></button>
			</div>

		</div>
	</div>
</div>
