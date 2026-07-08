<?php
/**
 * Admin view — Geo-routing tab.
 *
 * Spec: specs/001-geo-routing-next/spec.md FR-05
 * Tasks: T085 + T086 + T090 + T091 (P6 Admin UI)
 *
 * Server-renders only the page skeleton + initial nonces. All dynamic
 * data (ruleset coverage, overrides, status) is loaded via REST
 * (faz/v1/geo/*) by the JS module at admin/assets/js/pages/geo-routing.js.
 *
 * @package FazCookie\Admin\Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rest_nonce = wp_create_nonce( 'wp_rest' );
$rest_url   = esc_url( rest_url( 'faz/v1/geo/' ) );

?><div class="wrap faz-admin-page faz-geo-routing-page" id="faz-geo-routing-app"
     data-faz-rest-url="<?php echo esc_url( $rest_url ); ?>"
     data-faz-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>">

	<h1><?php esc_html_e( 'Geo-routing', 'faz-cookie-manager' ); ?></h1>
	<p class="faz-page-intro">
		<?php esc_html_e( 'Inspect, override, and preview the built-in jurisdiction rule-sets per country and US state. Automatic per-visitor application to the live banner is off (see Pipeline status) — configure jurisdiction-specific banners, such as a CCPA/CPRA "Do Not Sell" banner, manually on the Banner page.', 'faz-cookie-manager' ); ?>
	</p>

	<nav class="faz-geo-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Geo-routing sections', 'faz-cookie-manager' ); ?>">
		<button id="faz-geo-tab-status" class="faz-geo-tab active" data-target="status" role="tab" aria-selected="true" aria-controls="faz-geo-panel-status">
			<?php esc_html_e( 'Pipeline status', 'faz-cookie-manager' ); ?>
		</button>
		<button id="faz-geo-tab-coverage" class="faz-geo-tab" data-target="coverage" role="tab" aria-selected="false" aria-controls="faz-geo-panel-coverage">
			<?php esc_html_e( 'Ruleset coverage', 'faz-cookie-manager' ); ?>
		</button>
		<button id="faz-geo-tab-overrides" class="faz-geo-tab" data-target="overrides" role="tab" aria-selected="false" aria-controls="faz-geo-panel-overrides">
			<?php esc_html_e( 'Per-country overrides', 'faz-cookie-manager' ); ?>
		</button>
		<button id="faz-geo-tab-preview" class="faz-geo-tab" data-target="preview" role="tab" aria-selected="false" aria-controls="faz-geo-panel-preview">
			<?php esc_html_e( 'Preview', 'faz-cookie-manager' ); ?>
		</button>
		<button id="faz-geo-tab-ipinfo" class="faz-geo-tab" data-target="ipinfo" role="tab" aria-selected="false" aria-controls="faz-geo-panel-ipinfo">
			<?php esc_html_e( 'ipinfo (VPN detection)', 'faz-cookie-manager' ); ?>
		</button>
		<button id="faz-geo-tab-pipl" class="faz-geo-tab" data-target="pipl" role="tab" aria-selected="false" aria-controls="faz-geo-panel-pipl">
			<?php esc_html_e( 'PIPL cross-border', 'faz-cookie-manager' ); ?>
		</button>
	</nav>

	<!-- Pipeline status panel -->
	<section class="faz-geo-panel" id="faz-geo-panel-status" role="tabpanel" aria-labelledby="faz-geo-tab-status">
		<h2><?php esc_html_e( 'Pipeline status', 'faz-cookie-manager' ); ?></h2>
		<div id="faz-geo-status-content">
			<p class="faz-loading"><?php esc_html_e( 'Loading…', 'faz-cookie-manager' ); ?></p>
		</div>
	</section>

	<!-- Ruleset coverage table -->
	<section class="faz-geo-panel hidden" id="faz-geo-panel-coverage" role="tabpanel" aria-labelledby="faz-geo-tab-coverage">
		<h2><?php esc_html_e( 'Ruleset coverage', 'faz-cookie-manager' ); ?></h2>
		<p>
			<?php esc_html_e( 'Each country (and US state with privacy law) maps to a specific rule-set that defines banner behaviour, default signals, and UI flags. Click any row to inspect the resolved ruleset configuration.', 'faz-cookie-manager' ); ?>
		</p>
		<div id="faz-geo-coverage-content">
			<p class="faz-loading"><?php esc_html_e( 'Loading…', 'faz-cookie-manager' ); ?></p>
		</div>
	</section>

	<!-- Per-country overrides -->
	<section class="faz-geo-panel hidden" id="faz-geo-panel-overrides" role="tabpanel" aria-labelledby="faz-geo-tab-overrides">
		<h2><?php esc_html_e( 'Per-country overrides', 'faz-cookie-manager' ); ?></h2>
		<p>
			<?php esc_html_e( 'Override the auto-detected rule-set for a specific country. Use the dot-notation delta to selectively change individual fields (e.g. signals.cmv2.ad_storage).', 'faz-cookie-manager' ); ?>
		</p>
		<div id="faz-geo-overrides-content">
			<p class="faz-loading"><?php esc_html_e( 'Loading…', 'faz-cookie-manager' ); ?></p>
		</div>
	</section>

	<!-- Preview -->
	<section class="faz-geo-panel hidden" id="faz-geo-panel-preview" role="tabpanel" aria-labelledby="faz-geo-tab-preview">
		<h2><?php esc_html_e( 'Preview routing', 'faz-cookie-manager' ); ?></h2>
		<p>
			<?php esc_html_e( 'Simulate a visitor from any country / US state / VPN status and see the rule-set the plugin would apply.', 'faz-cookie-manager' ); ?>
		</p>
		<form id="faz-geo-preview-form" novalidate>
			<p>
				<label for="faz-geo-preview-country"><?php esc_html_e( 'Country (ISO 3166-1 alpha-2)', 'faz-cookie-manager' ); ?></label><br>
				<input type="text" id="faz-geo-preview-country" name="country" maxlength="2" placeholder="IT" style="text-transform:uppercase;width:6em">
			</p>
			<p>
				<label for="faz-geo-preview-region"><?php esc_html_e( 'Region (ISO 3166-2, optional)', 'faz-cookie-manager' ); ?></label><br>
				<input type="text" id="faz-geo-preview-region" name="region" maxlength="6" placeholder="US-CA" style="text-transform:uppercase;width:8em">
			</p>
			<p>
				<label>
					<input type="checkbox" id="faz-geo-preview-vpn" name="vpn">
					<?php esc_html_e( 'Visitor uses VPN/proxy/Tor', 'faz-cookie-manager' ); ?>
				</label>
			</p>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Resolve', 'faz-cookie-manager' ); ?></button>
			</p>
		</form>
		<div id="faz-geo-preview-result" aria-live="polite"></div>
	</section>

	<!-- ipinfo settings -->
	<section class="faz-geo-panel hidden" id="faz-geo-panel-ipinfo" role="tabpanel" aria-labelledby="faz-geo-tab-ipinfo">
		<h2><?php esc_html_e( 'ipinfo.io — VPN detection', 'faz-cookie-manager' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %s URL to ipinfo.io */
				wp_kses_post( __( 'Detect VPN/proxy/Tor visitors via <a href="%s" target="_blank" rel="noopener noreferrer">ipinfo.io</a>. When a VPN is detected, the plugin falls back to the most-protective rule-set (gdpr-strict) regardless of the visitor\'s apparent country. Opt-in is gated by your acknowledgment of the cross-border data-transfer obligations under GDPR / DPF / SCC.', 'faz-cookie-manager' ) ),
				'https://ipinfo.io'
			);
			?>
		</p>
		<div id="faz-geo-ipinfo-content">
			<p class="faz-loading"><?php esc_html_e( 'Loading…', 'faz-cookie-manager' ); ?></p>
		</div>
	</section>

	<!-- PIPL attestation -->
	<section class="faz-geo-panel hidden" id="faz-geo-panel-pipl" role="tabpanel" aria-labelledby="faz-geo-tab-pipl">
		<h2><?php esc_html_e( 'PIPL — Cross-border data transfer attestation', 'faz-cookie-manager' ); ?></h2>
		<p>
			<?php esc_html_e( 'When the plugin routes visitors from China (PIPL applies), cross-border transfer of personal data requires either a Standard Contract (Art. 38) or a CAC security assessment (Art. 40). Confirm your status here for audit purposes; the plugin does NOT block the PIPL rule-set if unattested, but a warning will be displayed in the admin area.', 'faz-cookie-manager' ); ?>
		</p>
		<div id="faz-geo-pipl-content">
			<p class="faz-loading"><?php esc_html_e( 'Loading…', 'faz-cookie-manager' ); ?></p>
		</div>
	</section>
</div>

<style>
.faz-geo-routing-page { max-width: 1100px; }
.faz-geo-tabs { display: flex; gap: 4px; border-bottom: 1px solid #ccd0d4; margin-bottom: 20px; }
.faz-geo-tab { background: transparent; border: 1px solid transparent; border-bottom: none; padding: 8px 14px; cursor: pointer; font-size: 14px; }
.faz-geo-tab.active { background: #fff; border-color: #ccd0d4; border-bottom-color: #fff; margin-bottom: -1px; font-weight: 600; }
.faz-geo-panel { background: #fff; padding: 16px 20px; border: 1px solid #ccd0d4; border-radius: 4px; }
.faz-geo-panel.hidden { display: none; }
.faz-loading { color: #666; font-style: italic; }
.faz-geo-coverage-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
.faz-geo-coverage-table th, .faz-geo-coverage-table td { text-align: left; padding: 6px 10px; border-bottom: 1px solid #eee; }
.faz-geo-coverage-table th { background: #f8f8f8; }
.faz-geo-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.faz-geo-badge--default { background: #e7f3fa; color: #006799; }
.faz-geo-badge--specific { background: #e6f3e6; color: #1d7d28; }
.faz-geo-badge--override { background: #fef0d0; color: #8a6d00; }
.faz-geo-preview-card { margin-top: 12px; padding: 12px; background: #f8f8f8; border-left: 3px solid #0073aa; }
.faz-geo-preview-card pre { white-space: pre-wrap; word-wrap: break-word; font-family: Consolas, Monaco, monospace; font-size: 12px; }
.faz-geo-warning { background: #fef0d0; border-left: 3px solid #f59e0b; padding: 10px 14px; margin: 12px 0; }
.faz-geo-success { background: #e6f3e6; border-left: 3px solid #1d7d28; padding: 10px 14px; margin: 12px 0; }
.faz-geo-error { background: #fde8e8; border-left: 3px solid #c4302b; padding: 10px 14px; margin: 12px 0; }
</style>
