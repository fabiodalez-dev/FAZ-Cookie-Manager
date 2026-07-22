<?php
/**
 * FAZ Cookie Manager — Guided Setup Wizard
 *
 * Server-rendered multi-step form (spezzone loaded by base.php — no div.wrap).
 * Step navigation, the scan trigger, and the finish call are wired in
 * admin/assets/js/pages/setup.js. Every value echoed here is escaped and every
 * user-facing string is translatable.
 *
 * @package FazCookie\Admin
 */

defined( 'ABSPATH' ) || exit;

// Pre-select the jurisdiction from a previous run (wizard re-entry), else the
// most-protective compliant default (GDPR opt-in). The compliant option is
// pre-checked; no option is labelled "recommended" in a nudging way.
$faz_onboarding_law = '';
if ( class_exists( '\\FazCookie\\Admin\\Modules\\Settings\\Includes\\Onboarding' ) ) {
	$faz_onboarding_helper = new \FazCookie\Admin\Modules\Settings\Includes\Onboarding();
	$faz_onboarding_law    = $faz_onboarding_helper->get_law();
}
if ( '' === $faz_onboarding_law ) {
	$faz_onboarding_law = 'gdpr';
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
?>
<div id="faz-setup">

	<ol class="faz-wizard-progress" aria-label="<?php esc_attr_e( 'Setup steps', 'faz-cookie-manager' ); ?>">
		<li class="faz-wizard-progress-item is-active" data-progress="1">
			<span class="faz-wizard-progress-num">1</span>
			<span class="faz-wizard-progress-label"><?php esc_html_e( 'Applicable law', 'faz-cookie-manager' ); ?></span>
		</li>
		<li class="faz-wizard-progress-item" data-progress="2">
			<span class="faz-wizard-progress-num">2</span>
			<span class="faz-wizard-progress-label"><?php esc_html_e( 'Find cookies', 'faz-cookie-manager' ); ?></span>
		</li>
		<li class="faz-wizard-progress-item" data-progress="3">
			<span class="faz-wizard-progress-num">3</span>
			<span class="faz-wizard-progress-label"><?php esc_html_e( 'Review & finish', 'faz-cookie-manager' ); ?></span>
		</li>
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

			<!-- Step 2 — Find cookies (optional) -->
			<section class="faz-wizard-step" data-step="2" hidden aria-labelledby="faz-setup-step2-title">
				<h2 id="faz-setup-step2-title" class="faz-setup-step-title" tabindex="-1"><?php esc_html_e( 'Find your cookies', 'faz-cookie-manager' ); ?> <span class="faz-setup-optional"><?php esc_html_e( '(optional)', 'faz-cookie-manager' ); ?></span></h2>
				<p class="faz-setup-step-lead"><?php esc_html_e( 'A quick server-side scan looks at a sample of your pages to discover cookies and categorise them automatically. It is optional — you can always run it later, and finishing setup never waits for it.', 'faz-cookie-manager' ); ?></p>

				<div class="faz-setup-scan">
					<button type="button" class="faz-btn faz-btn-secondary" id="faz-setup-scan-btn">
						<span class="dashicons dashicons-search" aria-hidden="true"></span>
						<?php esc_html_e( 'Run a quick scan', 'faz-cookie-manager' ); ?>
					</button>
					<p class="faz-setup-scan-status" id="faz-setup-scan-status" role="status" aria-live="polite"></p>
				</div>

				<p class="faz-setup-scan-note">
					<?php esc_html_e( 'A quick scan may not catch cookies set later by JavaScript.', 'faz-cookie-manager' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=faz-cookie-manager-cookies' ) ); ?>"><?php esc_html_e( 'Do a full browser scan on the Cookies page', 'faz-cookie-manager' ); ?></a>.
				</p>
			</section>

			<!-- Step 3 — Review & finish -->
			<section class="faz-wizard-step" data-step="3" hidden aria-labelledby="faz-setup-step3-title">
				<h2 id="faz-setup-step3-title" class="faz-setup-step-title" tabindex="-1"><?php esc_html_e( 'Review & finish', 'faz-cookie-manager' ); ?></h2>
				<p class="faz-setup-step-lead"><?php esc_html_e( 'Here is what will be applied. These are compliant defaults — the controls match the selected consent model and no categories are pre-ticked.', 'faz-cookie-manager' ); ?></p>

				<ul class="faz-setup-review" id="faz-setup-review"
					data-label-law="<?php esc_attr_e( 'Applicable law', 'faz-cookie-manager' ); ?>"
					data-label-effect="<?php esc_attr_e( 'Consent model', 'faz-cookie-manager' ); ?>"
					data-label-expiry="<?php esc_attr_e( 'Consent expiry', 'faz-cookie-manager' ); ?>"
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
