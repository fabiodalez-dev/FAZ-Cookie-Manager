<?php
/**
 * FAZ Cookie Manager — Cookies Page
 *
 * @package FazCookie\Admin
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="faz-cookies">
	<?php /* Blocked-script watchdog: cookies.js sets window.fazCookiesBooted the
		instant it runs. An ad blocker or browser privacy shield can block that file
		by name (it contains "cookie"), leaving this page silently inert (categories,
		add-cookie and scan buttons do nothing). This server-rendered notice, hidden
		by default, is revealed by the watchdog inline script registered via
		wp_add_inline_script() in class-admin.php (handle faz-page-cookies) when the
		page-load lifecycle completes and the boot flag is still unset. The notice
		carries an aria-live region so the reveal is announced to screen readers when
		the inner status text is populated. */ ?>
	<div id="faz-cookies-script-blocked" class="notice notice-error inline" style="display:none;">
		<p id="faz-cookies-script-blocked-msg" aria-live="assertive" aria-atomic="true"></p>
		<template id="faz-cookies-script-blocked-text"><?php esc_html_e( 'The Cookies page editor did not load, so its buttons will not work. A browser privacy shield or ad blocker is most likely blocking its script (the filename contains the word “cookie”, which some block lists match). Allow scripts for /wp-admin in this browser — or pause the shield for this page — then reload.', 'faz-cookie-manager' ); ?></template>
	</div>

	<?php
	// The Sale/Sharing flags only drive the CCPA/CPRA Do-Not-Sell opt-out. On a
	// pure-GDPR site (no active banner with a Do-Not-Sell surface) they have no
	// visitor-facing effect, so the column is hidden to keep the editor focused.
	// Stored flags are preserved either way: cookies.js only PUTs the flags for
	// rows that actually render the toggles.
	$faz_show_ccpa_col = \FazCookie\Admin\Modules\Banners\Includes\Controller::get_instance()->has_do_not_sell_surface();
	?>
	<!-- Cookie Categories Editor -->
	<div class="faz-card" style="margin-bottom:16px;">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Cookie Categories', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div class="faz-help" style="margin-bottom:12px;">
				<?php if ( $faz_show_ccpa_col ) : ?>
					<?php esc_html_e( 'Edit the display name and description for each cookie category, and flag whether it involves the sale or sharing of personal data. Names and descriptions are shown to visitors in the cookie preference center; the sale/sharing flags drive the CCPA/CPRA "Do Not Sell or Share" opt-out.', 'faz-cookie-manager' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Edit the display name and description for each cookie category. Names and descriptions are shown to visitors in the cookie preference center.', 'faz-cookie-manager' ); ?>
				<?php endif; ?>
			</div>
			<div class="faz-table-wrap">
				<table class="faz-table" id="faz-category-edit-table" data-show-ccpa="<?php echo esc_attr( $faz_show_ccpa_col ? '1' : '0' ); ?>">
					<thead>
						<tr>
							<th style="width:120px;"><?php esc_html_e( 'Slug', 'faz-cookie-manager' ); ?></th>
							<th style="width:200px;"><?php esc_html_e( 'Display Name', 'faz-cookie-manager' ); ?></th>
							<th><?php esc_html_e( 'Description', 'faz-cookie-manager' ); ?></th>
							<?php if ( $faz_show_ccpa_col ) : ?>
								<th style="width:160px;" title="<?php esc_attr_e( 'CPRA distinguishes a sale (for valuable consideration) from sharing (for cross-context behavioural advertising). A category flagged for either is covered by the visitor Do Not Sell or Share opt-out.', 'faz-cookie-manager' ); ?>"><?php esc_html_e( 'Sale / Sharing (CCPA)', 'faz-cookie-manager' ); ?></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody id="faz-category-edit-rows">
						<tr><td colspan="<?php echo esc_attr( $faz_show_ccpa_col ? '4' : '3' ); ?>" style="color:var(--faz-text-muted);"><?php esc_html_e( 'Loading...', 'faz-cookie-manager' ); ?></td></tr>
					</tbody>
				</table>
			</div>
			<div style="margin-top:12px;">
				<button class="faz-btn faz-btn-primary faz-btn-sm" id="faz-save-categories" type="button"><?php esc_html_e( 'Save Categories', 'faz-cookie-manager' ); ?></button>
			</div>
		</div>
	</div>

	<div class="faz-grid faz-grid-sidebar">
		<div class="faz-card" id="faz-cat-sidebar">
			<div class="faz-card-header">
				<h3><?php esc_html_e( 'Categories', 'faz-cookie-manager' ); ?></h3>
			</div>
			<div class="faz-card-body">
				<ul class="faz-sidebar-nav" id="faz-cat-list">
					<li><button class="active" data-cat="all"><?php esc_html_e( 'All Cookies', 'faz-cookie-manager' ); ?> <span class="faz-count">--</span></button></li>
				</ul>
			</div>
		</div>
		<div>
			<div class="faz-card faz-card-overflow-visible">
				<div class="faz-card-header">
					<h3 id="faz-cookies-title"><?php esc_html_e( 'All Cookies', 'faz-cookie-manager' ); ?></h3>
					<div class="faz-page-header-actions">
						<div class="faz-dropdown" id="faz-scan-dropdown">
							<button class="faz-btn faz-btn-outline faz-btn-sm" id="faz-scan-btn"><?php esc_html_e( 'Scan Site', 'faz-cookie-manager' ); ?> &#9662;</button>
							<div class="faz-dropdown-menu">
								<button class="faz-dropdown-item" data-depth="10"><?php esc_html_e( 'Quick scan (10 pages)', 'faz-cookie-manager' ); ?></button>
								<button class="faz-dropdown-item" data-depth="100"><?php esc_html_e( 'Standard scan (100 pages)', 'faz-cookie-manager' ); ?></button>
								<button class="faz-dropdown-item" data-depth="1000"><?php esc_html_e( 'Deep scan (1000 pages)', 'faz-cookie-manager' ); ?></button>
								<button class="faz-dropdown-item" data-depth="0"><?php esc_html_e( 'Full scan (all pages)', 'faz-cookie-manager' ); ?></button>
							</div>
						</div>
						<div class="faz-dropdown" id="faz-auto-cat-dropdown">
							<button class="faz-btn faz-btn-outline faz-btn-sm" id="faz-auto-cat-btn"><?php esc_html_e( 'Auto-categorize', 'faz-cookie-manager' ); ?> &#9662;</button>
							<div class="faz-dropdown-menu">
								<button class="faz-dropdown-item" data-scope="uncategorized"><?php esc_html_e( 'Uncategorized only', 'faz-cookie-manager' ); ?></button>
								<button class="faz-dropdown-item" data-scope="all"><?php esc_html_e( 'All cookies', 'faz-cookie-manager' ); ?></button>
							</div>
						</div>
						<div class="faz-dropdown" id="faz-add-service-dropdown">
							<button class="faz-btn faz-btn-outline faz-btn-sm" id="faz-add-service-btn"><?php esc_html_e( 'Add Service', 'faz-cookie-manager' ); ?> &#9662;</button>
							<div class="faz-dropdown-menu" style="min-width:300px;padding:12px;">
								<label for="faz-service-select" style="display:block;font-size:12px;line-height:1.4;margin-bottom:8px;"><?php esc_html_e( 'Register a known third-party service so its cookies are declared on every page — useful when the scanner cannot reach an embed (caching, lazy-load).', 'faz-cookie-manager' ); ?></label>
								<select id="faz-service-select" style="width:100%;margin-bottom:8px;">
									<option value=""><?php esc_html_e( 'Loading services…', 'faz-cookie-manager' ); ?></option>
								</select>
								<button class="faz-btn faz-btn-primary faz-btn-sm" id="faz-register-service-btn" type="button" style="width:100%;"><?php esc_html_e( 'Register service', 'faz-cookie-manager' ); ?></button>
							</div>
						</div>
						<button class="faz-btn faz-btn-primary faz-btn-sm" id="faz-add-cookie-btn"><?php esc_html_e( 'Add Cookie', 'faz-cookie-manager' ); ?></button>
						<span id="faz-debug-log-actions" style="display:none;">
							<button class="faz-btn faz-btn-outline faz-btn-sm" id="faz-download-debug-log" type="button" title="<?php esc_attr_e( 'Download scanner debug log', 'faz-cookie-manager' ); ?>"><?php esc_html_e( 'Debug Log', 'faz-cookie-manager' ); ?></button>
							<button class="faz-btn faz-btn-outline faz-btn-sm" id="faz-clear-debug-log" type="button" style="color:var(--faz-danger);" title="<?php esc_attr_e( 'Clear all scanner debug logs', 'faz-cookie-manager' ); ?>"><?php esc_html_e( 'Clear Logs', 'faz-cookie-manager' ); ?></button>
						</span>
					</div>
				</div>
			<div class="faz-card-body">
						<div id="faz-bulk-bar" style="display:none" class="faz-bulk-bar">
							<span class="faz-bulk-count">0 <?php esc_html_e( 'selected', 'faz-cookie-manager' ); ?></span>
							<button type="button" class="faz-btn faz-btn-sm" id="faz-bulk-delete-btn" style="color:var(--faz-danger)"><?php esc_html_e( 'Delete Selected', 'faz-cookie-manager' ); ?></button>
						</div>
						<div id="faz-stale-bar" style="display:none" class="faz-stale-bar" role="status" aria-live="polite" aria-atomic="true"></div>
						<div class="faz-table-wrap">
						<table class="faz-table" id="faz-cookies-table">
							<thead>
								<tr>
									<th style="width:40px"><input type="checkbox" id="faz-select-all-cookies" aria-label="<?php esc_attr_e( 'Select all cookies', 'faz-cookie-manager' ); ?>"></th>
									<th><?php esc_html_e( 'Name', 'faz-cookie-manager' ); ?></th>
									<th><?php esc_html_e( 'Domain', 'faz-cookie-manager' ); ?></th>
									<th><?php esc_html_e( 'Duration', 'faz-cookie-manager' ); ?></th>
									<th><?php esc_html_e( 'Description', 'faz-cookie-manager' ); ?></th>
									<th style="text-align:right"><?php esc_html_e( 'Actions', 'faz-cookie-manager' ); ?></th>
								</tr>
							</thead>
							<tbody id="faz-cookies-tbody">
								<tr><td colspan="6" class="faz-empty"><p><?php esc_html_e( 'Loading...', 'faz-cookie-manager' ); ?></p></td></tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Cookie Definitions (Open Cookie Database) -->
	<div class="faz-card" id="faz-cookie-definitions-card" style="margin-top:16px;">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Cookie Definitions', 'faz-cookie-manager' ); ?></h3>
			<div class="faz-page-header-actions">
				<button class="faz-btn faz-btn-outline faz-btn-sm" id="faz-update-defs-btn" type="button"><?php esc_html_e( 'Update Definitions', 'faz-cookie-manager' ); ?></button>
			</div>
		</div>
		<div class="faz-card-body">
			<p><?php echo wp_kses_post( __( 'Cookie definitions are sourced from the <a href="https://github.com/fabiodalez-dev/Open-Cookie-Database" target="_blank" rel="noopener">Open Cookie Database</a> (Apache-2.0 license). These definitions power the auto-categorize feature.', 'faz-cookie-manager' ) ); ?></p>
			<div id="faz-defs-status" style="margin-top:8px;font-size:13px;color:var(--faz-text-muted);"><?php esc_html_e( 'Loading status...', 'faz-cookie-manager' ); ?></div>
		</div>
	</div>

	<!-- Content Blocker Templates -->
	<div class="faz-card" style="margin-top:16px;">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Content Blocker Templates', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<p><?php esc_html_e( 'Pre-configured blocking rules for popular services. Click to add a template to your custom rules.', 'faz-cookie-manager' ); ?></p>
			<div id="faz-blocker-templates" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-top:12px;">
				<p style="color:var(--faz-text-muted);"><?php esc_html_e( 'Loading templates...', 'faz-cookie-manager' ); ?></p>
			</div>
		</div>
	</div>

	<!-- Script Blocking — Custom Rules -->
	<div class="faz-card" style="margin-top:16px;">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Script Blocking — Custom Rules', 'faz-cookie-manager' ); ?></h3>
			<div class="faz-page-header-actions">
				<button class="faz-btn faz-btn-primary faz-btn-sm" id="faz-save-rules-btn" type="button"><?php esc_html_e( 'Save Rules', 'faz-cookie-manager' ); ?></button>
			</div>
		</div>
		<div class="faz-card-body">
			<p style="margin:0 0 12px;color:var(--faz-text-secondary);">
				<?php esc_html_e( 'Add custom URL patterns to block. Each pattern is matched against script/iframe src, inline code, and enqueued handle names. The plugin already blocks 143+ known services automatically.', 'faz-cookie-manager' ); ?>
			</p>
			<table class="faz-table" id="faz-custom-rules-table" style="width:100%;margin-bottom:12px;">
				<thead>
					<tr>
						<th style="width:55%;"><?php esc_html_e( 'URL Pattern', 'faz-cookie-manager' ); ?></th>
						<th style="width:30%;"><?php esc_html_e( 'Category', 'faz-cookie-manager' ); ?></th>
						<th style="width:15%;text-align:center;"><?php esc_html_e( 'Actions', 'faz-cookie-manager' ); ?></th>
					</tr>
				</thead>
				<tbody id="faz-custom-rules-body">
					<!-- rows injected by JS -->
				</tbody>
			</table>
			<button class="faz-btn faz-btn-secondary" id="faz-add-rule" type="button"><?php
				/* translators: Button to add a new custom script blocking rule */
				esc_html_e( '+ Add Rule', 'faz-cookie-manager' );
			?></button>
		</div>
	</div>

	<!-- Shortcode Info -->
	<div class="faz-card" style="margin-top:16px;">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Cookie Table Shortcode', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<p><?php esc_html_e( 'Use the following shortcode to display a table of all cookies on any page or post (e.g. your Cookie Policy page):', 'faz-cookie-manager' ); ?></p>
			<div style="display:flex;align-items:center;gap:8px;margin:12px 0;">
				<code id="faz-shortcode-text" style="font-size:14px;padding:8px 12px;background:var(--faz-bg);border:1px solid var(--faz-border);border-radius:var(--faz-radius);user-select:all;">[faz_cookie_table]</code>
				<button class="faz-btn faz-btn-outline faz-btn-sm" id="faz-copy-shortcode" type="button"><?php esc_html_e( 'Copy', 'faz-cookie-manager' ); ?></button>
			</div>
			<details style="margin-top:8px;">
				<summary style="cursor:pointer;font-weight:500;font-size:13px;"><?php esc_html_e( 'Advanced options', 'faz-cookie-manager' ); ?></summary>
				<div style="margin-top:8px;font-size:13px;line-height:1.6;">
					<p><?php esc_html_e( 'You can customize the shortcode with these attributes:', 'faz-cookie-manager' ); ?></p>
					<table class="faz-table" style="font-size:13px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Attribute', 'faz-cookie-manager' ); ?></th>
								<th><?php esc_html_e( 'Default', 'faz-cookie-manager' ); ?></th>
								<th><?php esc_html_e( 'Description', 'faz-cookie-manager' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><code>columns</code></td>
								<td><code>name,domain,duration,description</code></td>
								<td><?php echo wp_kses_post( __( 'Comma-separated list of columns. Available: <code>name</code>, <code>domain</code>, <code>duration</code>, <code>description</code>, <code>category</code>', 'faz-cookie-manager' ) ); ?></td>
							</tr>
							<tr>
								<td><code>category</code></td>
								<td><em><?php esc_html_e( '(all)', 'faz-cookie-manager' ); ?></em></td>
								<td><?php echo wp_kses_post( __( 'Filter by category slug (e.g. <code>analytics</code>) or ID', 'faz-cookie-manager' ) ); ?></td>
							</tr>
							<tr>
								<td><code>heading</code></td>
								<td><em><?php esc_html_e( '(none)', 'faz-cookie-manager' ); ?></em></td>
								<td><?php esc_html_e( 'Optional heading text above the table', 'faz-cookie-manager' ); ?></td>
							</tr>
						</tbody>
					</table>
					<p style="margin-top:8px;"><?php echo wp_kses_post( __( '<strong>Example:</strong> <code>[faz_cookie_table columns="name,duration,description" category="analytics"]</code>', 'faz-cookie-manager' ) ); ?></p>
					<p style="margin-top:4px;"><?php echo wp_kses_post( __( 'The legacy shortcode <code>[cookie_audit]</code> is also supported for backward compatibility.', 'faz-cookie-manager' ) ); ?></p>
				</div>
			</details>
		</div>
	</div>

	<!-- Cookie Policy Shortcode -->
	<div class="faz-card" style="margin-top:16px;">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Cookie Policy Shortcode', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<p><?php esc_html_e( 'Use the following shortcode to display a complete cookie policy page:', 'faz-cookie-manager' ); ?></p>
			<div style="display:flex;align-items:center;gap:8px;margin:12px 0;">
				<code id="faz-policy-shortcode" style="font-size:14px;padding:8px 12px;background:var(--faz-bg);border:1px solid var(--faz-border);border-radius:var(--faz-radius);user-select:all;">[faz_cookie_policy]</code>
				<button class="faz-btn faz-btn-outline faz-btn-sm" id="faz-copy-policy-shortcode" type="button"><?php esc_html_e( 'Copy', 'faz-cookie-manager' ); ?></button>
			</div>
			<div class="faz-help"><?php echo wp_kses_post( __( 'Generates a complete cookie policy with sections: What Are Cookies, How We Use Cookies, Cookies We Use (table), How to Manage Cookies, and contact information. Customise with attributes: <code>site_name</code>, <code>contact</code>, <code>show_table</code>.', 'faz-cookie-manager' ) ); ?></div>
		</div>
	</div>
</div>

<!-- Hidden iframe container for browser-based cookie scanning -->
<div id="faz-scan-frame" style="display:none;position:absolute;left:-9999px;"></div>

<?php
/*
 * Page-specific behaviour (shortcode-copy buttons + scanner debug log
 * actions) lives in admin/assets/js/pages/cookies.js — automatically
 * enqueued by class-admin.php::enqueue_scripts() when the current view
 * is "cookies". Localized strings: fazConfig.i18n.cookies.*.
 */
?>
