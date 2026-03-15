<?php
/**
 * FAZ Cookie Manager — Cookies Page
 *
 * @package FazCookie\Admin
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="faz-cookies">
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
			<div class="faz-card">
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
						<button class="faz-btn faz-btn-primary faz-btn-sm" id="faz-add-cookie-btn"><?php esc_html_e( 'Add Cookie', 'faz-cookie-manager' ); ?></button>
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
	<div class="faz-card" style="margin-top:16px;">
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
</div>

<!-- Hidden iframe container for browser-based cookie scanning -->
<div id="faz-scan-frame" style="display:none;position:absolute;left:-9999px;"></div>

<script>
document.getElementById('faz-copy-shortcode').addEventListener('click', function() {
	var text = document.getElementById('faz-shortcode-text').textContent;
	if (navigator.clipboard) {
		navigator.clipboard.writeText(text).then(function() {
			FAZ.notify('<?php echo esc_js( __( 'Shortcode copied!', 'faz-cookie-manager' ) ); ?>');
		});
	} else {
		var range = document.createRange();
		range.selectNodeContents(document.getElementById('faz-shortcode-text'));
		var sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange(range);
		document.execCommand('copy');
		FAZ.notify('<?php echo esc_js( __( 'Shortcode copied!', 'faz-cookie-manager' ) ); ?>');
	}
});
</script>
