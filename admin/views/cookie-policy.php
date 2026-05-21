<?php
/**
 * FAZ Cookie Manager — Cookie Policy generator (Spec 002).
 *
 * Loaded by views/base.php as a snippet — no <div class="wrap"> wrapper,
 * no <h1>: the base template owns the chrome, top-nav, page header, and
 * description. This file contributes only the page content (faz-card
 * blocks) so the page integrates with the rest of the admin UI.
 *
 * @package FazCookie\Admin
 */

defined( 'ABSPATH' ) || exit;

$rest_nonce = wp_create_nonce( 'wp_rest' );
$rest_url   = esc_url( rest_url( 'faz/v1/cookie-policy/' ) );
?>
<div id="faz-cookie-policy-app"
     data-faz-rest-url="<?php echo esc_url( $rest_url ); ?>"
     data-faz-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>">

	<form id="faz-cookie-policy-form" novalidate>

		<!-- 1. Company details -->
		<div class="faz-card">
			<div class="faz-card-header">
				<h3><?php esc_html_e( 'Company details', 'faz-cookie-manager' ); ?></h3>
			</div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label for="cp-company-name"><?php esc_html_e( 'Legal entity name', 'faz-cookie-manager' ); ?></label>
					<input type="text" id="cp-company-name" name="company.name" class="faz-input" required>
				</div>
				<div class="faz-form-group">
					<label for="cp-company-address"><?php esc_html_e( 'Postal address', 'faz-cookie-manager' ); ?></label>
					<textarea id="cp-company-address" name="company.address" class="faz-input" rows="2"></textarea>
				</div>
				<div class="faz-form-group">
					<label for="cp-company-email"><?php esc_html_e( 'Privacy contact email', 'faz-cookie-manager' ); ?></label>
					<input type="email" id="cp-company-email" name="company.email" class="faz-input" required>
				</div>
				<div class="faz-form-group">
					<label for="cp-company-registry"><?php esc_html_e( 'Registry / VAT / CNPJ (optional)', 'faz-cookie-manager' ); ?></label>
					<input type="text" id="cp-company-registry" name="company.registry" class="faz-input">
				</div>
			</div>
		</div>

		<!-- 2. Privacy officer -->
		<div class="faz-card">
			<div class="faz-card-header">
				<h3><?php esc_html_e( 'Privacy officer / DPO', 'faz-cookie-manager' ); ?></h3>
			</div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label for="cp-dpo-name"><?php esc_html_e( 'DPO / Encarregado / Privacy Officer name', 'faz-cookie-manager' ); ?></label>
					<input type="text" id="cp-dpo-name" name="dpo.name" class="faz-input">
				</div>
				<div class="faz-form-group">
					<label for="cp-dpo-email"><?php esc_html_e( 'DPO email', 'faz-cookie-manager' ); ?></label>
					<input type="email" id="cp-dpo-email" name="dpo.email" class="faz-input">
					<div class="faz-help"><?php esc_html_e( 'Mandatory for LGPD (Art. 41) and recommended for GDPR.', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>

		<!-- 3. Jurisdiction -->
		<div class="faz-card">
			<div class="faz-card-header">
				<h3><?php esc_html_e( 'Jurisdiction', 'faz-cookie-manager' ); ?></h3>
			</div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label for="cp-jurisdiction"><?php esc_html_e( 'Primary jurisdiction', 'faz-cookie-manager' ); ?></label>
					<select id="cp-jurisdiction" name="jurisdiction" class="faz-select">
						<option value="gdpr-strict"><?php esc_html_e( 'GDPR (EU / EEA / UK)', 'faz-cookie-manager' ); ?></option>
						<option value="ccpa-california"><?php esc_html_e( 'CCPA / CPRA (California, USA)', 'faz-cookie-manager' ); ?></option>
						<option value="lgpd-brazil"><?php esc_html_e( 'LGPD (Brazil)', 'faz-cookie-manager' ); ?></option>
					</select>
					<div class="faz-help"><?php esc_html_e( 'Override per shortcode call with [faz_cookie_policy_v2 jurisdiction="..."].', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>

		<!-- 4. Third-party services — ADVANCED. Collapsed by default because
		     for most sites the cookie list auto-populated from the scanner
		     (section 5) is sufficient. The third-party-services declaration
		     is mostly useful for services that transmit data WITHOUT setting
		     cookies (server-side GTM, Meta CAPI, iframe pixels, etc.) where
		     the scanner has nothing to detect.
		     `open` attribute deliberately omitted to keep the section
		     collapsed on first load. -->
		<div class="faz-card">
			<details>
				<summary class="faz-card-header" style="cursor:pointer; list-style:revert;">
					<h3 style="display:inline-block; margin:0;">
						<?php esc_html_e( 'Third-party services', 'faz-cookie-manager' ); ?>
						<span style="font-weight:normal; font-size:12px; color:#666; margin-left:6px;">
							<?php esc_html_e( '— advanced, optional', 'faz-cookie-manager' ); ?>
						</span>
					</h3>
				</summary>
				<div class="faz-card-body">
					<div class="faz-help" style="margin-bottom:.75rem;">
						<?php echo wp_kses_post( __( '<strong>You probably don\'t need this.</strong> If you\'ve run the cookie scanner (section 5 below), the policy already names every service from your site\'s actual cookies. This section is only useful for services that exchange data with third parties <em>without setting cookies</em> — for example: server-side Google Tag Manager, Meta CAPI server events, pixel iframes that block the visitor cookie store, embedded analytics that opt out of cookies. Leave it empty unless you know one of those applies.', 'faz-cookie-manager' ) ); ?>
					</div>
					<div id="cp-services-list" class="faz-form-group"></div>
				</div>
			</details>
		</div>

		<!-- 5. Cookies link (read-only — list pulled at render time) -->
		<div class="faz-card">
			<div class="faz-card-header">
				<h3><?php esc_html_e( 'Cookies on this site', 'faz-cookie-manager' ); ?></h3>
			</div>
			<div class="faz-card-body">
				<p class="faz-help">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: URL of the Cookies admin page */
							__( 'The cookie list is auto-populated from the <a href="%s">Cookies admin page</a>. Add or scan cookies there; the generator reads the latest snapshot at render time.', 'faz-cookie-manager' ),
							esc_url( admin_url( 'admin.php?page=faz-cookie-manager-cookies' ) )
						)
					);
					?>
				</p>
			</div>
		</div>

		<!-- 6. Retention + Privacy Policy URL -->
		<div class="faz-card">
			<div class="faz-card-header">
				<h3><?php esc_html_e( 'Retention &amp; links', 'faz-cookie-manager' ); ?></h3>
			</div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label for="cp-retention-months"><?php esc_html_e( 'Default retention period (months)', 'faz-cookie-manager' ); ?></label>
					<input type="number" id="cp-retention-months" name="retention_months" class="faz-input" min="1" max="120" step="1" value="12" style="max-width:8em;">
				</div>
				<div class="faz-form-group">
					<label for="cp-privacy-policy-url"><?php esc_html_e( 'Separate Privacy Policy URL (optional)', 'faz-cookie-manager' ); ?></label>
					<input type="url" id="cp-privacy-policy-url" name="privacy_policy_url" class="faz-input" placeholder="<?php echo esc_attr__( 'https://example.com/privacy', 'faz-cookie-manager' ); ?>">
				</div>
			</div>
		</div>

		<!-- 7. Default language -->
		<div class="faz-card">
			<div class="faz-card-header">
				<h3><?php esc_html_e( 'Default language', 'faz-cookie-manager' ); ?></h3>
			</div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label for="cp-default-lang"><?php esc_html_e( 'Force a specific language (otherwise follows visitor locale)', 'faz-cookie-manager' ); ?></label>
					<select id="cp-default-lang" name="default_lang" class="faz-select">
						<option value=""><?php esc_html_e( 'Follow visitor locale (recommended)', 'faz-cookie-manager' ); ?></option>
						<option value="en"><?php esc_html_e( 'English', 'faz-cookie-manager' ); ?></option>
						<option value="it"><?php esc_html_e( 'Italian', 'faz-cookie-manager' ); ?></option>
						<option value="fr"><?php esc_html_e( 'French', 'faz-cookie-manager' ); ?></option>
						<option value="de"><?php esc_html_e( 'German', 'faz-cookie-manager' ); ?></option>
						<option value="es"><?php esc_html_e( 'Spanish', 'faz-cookie-manager' ); ?></option>
						<option value="pt-BR"><?php esc_html_e( 'Portuguese (Brazil)', 'faz-cookie-manager' ); ?></option>
					</select>
				</div>
			</div>
		</div>

		<!-- Actions -->
		<div class="faz-card">
			<div class="faz-card-body" style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
				<button type="submit" class="faz-btn faz-btn-primary"><?php esc_html_e( 'Save settings', 'faz-cookie-manager' ); ?></button>
				<button type="button" id="cp-preview-btn" class="faz-btn faz-btn-secondary"><?php esc_html_e( 'Preview', 'faz-cookie-manager' ); ?></button>
				<span id="cp-save-status" class="faz-help" aria-live="polite"></span>
			</div>
		</div>

	</form>

	<!-- Embed instructions -->
	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Embed the policy', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<p><?php esc_html_e( 'Paste this shortcode on any page or post:', 'faz-cookie-manager' ); ?></p>
			<p><code>[faz_cookie_policy_v2]</code></p>
			<p class="faz-help"><?php esc_html_e( 'With explicit language or jurisdiction:', 'faz-cookie-manager' ); ?></p>
			<p>
				<code>[faz_cookie_policy_v2 lang="it"]</code><br>
				<code>[faz_cookie_policy_v2 jurisdiction="ccpa-california"]</code><br>
				<code>[faz_cookie_policy_v2 lang="pt-BR" jurisdiction="lgpd-brazil"]</code>
			</p>
			<p class="faz-help" style="margin-top:14px;">
				<?php echo wp_kses_post( __( '<strong>Note:</strong> the long-standing <code>[faz_cookie_policy]</code> shortcode (with <code>site_name</code> / <code>contact</code> / <code>show_table</code> attributes) is still supported for backward compatibility. The new <code>[faz_cookie_policy_v2]</code> renders the jurisdiction-aware template from the form above.', 'faz-cookie-manager' ) ); ?>
			</p>
			<hr style="margin:14px 0;">
			<p class="faz-help"><strong><?php esc_html_e( 'Editor compatibility:', 'faz-cookie-manager' ); ?></strong></p>
			<ul style="margin-left:1.2em; list-style:disc;">
				<li><?php esc_html_e( 'Classic editor: paste the shortcode anywhere in the content area.', 'faz-cookie-manager' ); ?></li>
				<li><?php echo wp_kses_post( __( 'Gutenberg / block editor: add a <strong>Shortcode block</strong>, paste the code inside.', 'faz-cookie-manager' ) ); ?></li>
				<li><?php echo wp_kses_post( __( 'Page builders (Bricks, Elementor, Beaver, Divi, WPBakery): use the builder\'s <strong>Shortcode</strong> widget/element and paste the code. Some builders (Bricks in particular) skip <code>the_content()</code> entirely on pages without a builder template, so the bare shortcode in the WP editor will not render — use the shortcode element of your builder instead.', 'faz-cookie-manager' ) ); ?></li>
				<li><?php echo wp_kses_post( __( 'Theme template files: <code>echo do_shortcode( \'[faz_cookie_policy_v2]\' );</code>', 'faz-cookie-manager' ) ); ?></li>
				<li><?php echo wp_kses_post( __( 'WordPress block templates (FSE themes like Twenty Twenty-Four/Five): add a Shortcode block to your <em>Single Page</em> template, or use the Shortcode block on the individual page.', 'faz-cookie-manager' ) ); ?></li>
			</ul>
		</div>
	</div>

	<!-- Preview modal -->
	<div id="cp-preview-modal" class="faz-cp-modal" hidden>
		<div class="faz-cp-modal-inner">
			<button type="button" class="faz-cp-modal-close" aria-label="<?php esc_attr_e( 'Close preview', 'faz-cookie-manager' ); ?>">&times;</button>
			<div id="cp-preview-content"></div>
		</div>
	</div>

</div>

<style>
.faz-cp-modal { position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:99999; display:flex; align-items:center; justify-content:center; padding:24px; }
.faz-cp-modal[hidden] { display:none; }
.faz-cp-modal-inner { background:#fff; border-radius:6px; max-width:900px; width:100%; max-height:88vh; overflow:auto; position:relative; padding:24px; }
.faz-cp-modal-close { position:absolute; top:8px; right:14px; background:none; border:none; font-size:28px; cursor:pointer; line-height:1; padding:4px 10px; }
#cp-services-list label { display:inline-flex; align-items:center; margin-right:14px; margin-bottom:6px; gap:6px; }
.faz-cookie-policy article.faz-cookie-policy h1 { margin-top: 0; }
.faz-cookie-policy-disclaimer { margin-top: 32px; padding:14px 16px; background:#fff5d6; border-left:3px solid #d4a017; font-size:13px; }
</style>
