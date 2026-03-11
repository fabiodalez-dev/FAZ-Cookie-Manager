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
				<h3><?php echo esc_html__( 'Categories', 'faz-cookie-manager' ); ?></h3>
			</div>
			<div class="faz-card-body">
				<ul class="faz-sidebar-nav" id="faz-cat-list">
					<li><button class="active" data-cat="all"><?php echo esc_html__( 'All Cookies', 'faz-cookie-manager' ); ?> <span class="faz-count">--</span></button></li>
				</ul>
			</div>
		</div>
		<div>
			<div class="faz-card">
				<div class="faz-card-header">
					<h3 id="faz-cookies-title"><?php echo esc_html__( 'All Cookies', 'faz-cookie-manager' ); ?></h3>
					<div class="faz-page-header-actions">
						<div class="faz-dropdown" id="faz-scan-dropdown">
							<button class="faz-btn faz-btn-outline faz-btn-sm" id="faz-scan-btn"><?php echo esc_html__( 'Scan Site', 'faz-cookie-manager' ); ?> &#9662;</button>
							<div class="faz-dropdown-menu">
								<button class="faz-dropdown-item" data-depth="10"><?php echo esc_html__( 'Quick scan (10 pages)', 'faz-cookie-manager' ); ?></button>
								<button class="faz-dropdown-item" data-depth="100"><?php echo esc_html__( 'Standard scan (100 pages)', 'faz-cookie-manager' ); ?></button>
								<button class="faz-dropdown-item" data-depth="1000"><?php echo esc_html__( 'Deep scan (1000 pages)', 'faz-cookie-manager' ); ?></button>
								<button class="faz-dropdown-item" data-depth="0"><?php echo esc_html__( 'Full scan (all pages)', 'faz-cookie-manager' ); ?></button>
							</div>
						</div>
						<div class="faz-dropdown" id="faz-auto-cat-dropdown">
							<button class="faz-btn faz-btn-outline faz-btn-sm" id="faz-auto-cat-btn"><?php echo esc_html__( 'Auto-categorize', 'faz-cookie-manager' ); ?> &#9662;</button>
							<div class="faz-dropdown-menu">
								<button class="faz-dropdown-item" data-scope="uncategorized"><?php echo esc_html__( 'Uncategorized only', 'faz-cookie-manager' ); ?></button>
								<button class="faz-dropdown-item" data-scope="all"><?php echo esc_html__( 'All cookies', 'faz-cookie-manager' ); ?></button>
							</div>
						</div>
						<button class="faz-btn faz-btn-primary faz-btn-sm" id="faz-add-cookie-btn"><?php echo esc_html__( 'Add Cookie', 'faz-cookie-manager' ); ?></button>
					</div>
				</div>
			<div class="faz-card-body">
						<div id="faz-bulk-bar" style="display:none" class="faz-bulk-bar">
							<span class="faz-bulk-count">0 <?php echo esc_html__( 'selected', 'faz-cookie-manager' ); ?></span>
							<button type="button" class="faz-btn faz-btn-sm" id="faz-bulk-delete-btn" style="color:var(--faz-danger)"><?php echo esc_html__( 'Delete Selected', 'faz-cookie-manager' ); ?></button>
						</div>
						<div id="faz-stale-bar" style="display:none" class="faz-stale-bar" role="status" aria-live="polite" aria-atomic="true"></div>
						<div class="faz-table-wrap">
						<table class="faz-table" id="faz-cookies-table">
							<thead>
								<tr>
									<th style="width:40px"><input type="checkbox" id="faz-select-all-cookies" aria-label="<?php esc_attr_e( 'Select all cookies', 'faz-cookie-manager' ); ?>"></th>
									<th><?php echo esc_html__( 'Name', 'faz-cookie-manager' ); ?></th>
									<th><?php echo esc_html__( 'Domain', 'faz-cookie-manager' ); ?></th>
									<th><?php echo esc_html__( 'Duration', 'faz-cookie-manager' ); ?></th>
									<th><?php echo esc_html__( 'Description', 'faz-cookie-manager' ); ?></th>
									<th style="text-align:right"><?php echo esc_html__( 'Actions', 'faz-cookie-manager' ); ?></th>
								</tr>
							</thead>
							<tbody id="faz-cookies-tbody">
								<tr><td colspan="6" class="faz-empty"><p><?php echo esc_html__( 'Loading...', 'faz-cookie-manager' ); ?></p></td></tr>
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
			<h3><?php echo esc_html__( 'Cookie Definitions', 'faz-cookie-manager' ); ?></h3>
			<div class="faz-page-header-actions">
				<button class="faz-btn faz-btn-outline faz-btn-sm" id="faz-update-defs-btn" type="button"><?php echo esc_html__( 'Update Definitions', 'faz-cookie-manager' ); ?></button>
			</div>
		</div>
		<div class="faz-card-body">
			<p><?php
				printf(
					/* translators: %s: URL to Open Cookie Database repository */
					esc_html__( 'Cookie definitions are sourced from the %s (Apache-2.0 license). These definitions power the auto-categorize feature and provide cookie descriptions for the audit table.', 'faz-cookie-manager' ),
					'<a href="https://github.com/fabiodalez-dev/Open-Cookie-Database" target="_blank" rel="noopener">' . esc_html__( 'Open Cookie Database', 'faz-cookie-manager' ) . '</a>'
				);
			?></p>
			<div id="faz-defs-status" style="margin-top:8px;font-size:13px;color:var(--faz-text-muted);"><?php echo esc_html__( 'Loading status...', 'faz-cookie-manager' ); ?></div>
		</div>
	</div>

	<!-- Script Blocking — Custom Rules -->
	<div class="faz-card" style="margin-top:16px;">
		<div class="faz-card-header">
			<h3><?php echo esc_html__( 'Script Blocking — Custom Rules', 'faz-cookie-manager' ); ?></h3>
			<div class="faz-page-header-actions">
				<button class="faz-btn faz-btn-primary faz-btn-sm" id="faz-save-rules-btn" type="button"><?php echo esc_html__( 'Save Rules', 'faz-cookie-manager' ); ?></button>
			</div>
		</div>
		<div class="faz-card-body">
			<div class="faz-help" style="margin:0 0 12px;">
				<?php echo esc_html__( 'Add custom URL patterns to block scripts or iframes until the visitor consents to the specified category. Each pattern is matched against script/iframe src, inline code, and enqueued handle names. The plugin already blocks 147+ known services automatically.', 'faz-cookie-manager' ); ?>
			</div>
			<table class="faz-table" id="faz-custom-rules-table" style="width:100%;margin-bottom:12px;">
				<thead>
					<tr>
						<th style="width:55%;"><?php echo esc_html__( 'URL Pattern', 'faz-cookie-manager' ); ?></th>
						<th style="width:30%;"><?php echo esc_html__( 'Category', 'faz-cookie-manager' ); ?></th>
						<th style="width:15%;text-align:center;"><?php echo esc_html__( 'Actions', 'faz-cookie-manager' ); ?></th>
					</tr>
				</thead>
				<tbody id="faz-custom-rules-body">
					<!-- rows injected by JS -->
				</tbody>
			</table>
			<button class="faz-btn faz-btn-secondary" id="faz-add-rule" type="button"><?php echo esc_html__( '+ Add Rule', 'faz-cookie-manager' ); ?></button>
		</div>
	</div>

	<!-- Shortcode Info -->
	<div class="faz-card" style="margin-top:16px;">
		<div class="faz-card-header">
			<h3><?php echo esc_html__( 'Cookie Table Shortcode', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<p><?php echo esc_html__( 'Use the following shortcode to display a table of all cookies on any page or post (e.g. your Cookie Policy page):', 'faz-cookie-manager' ); ?></p>
			<div style="display:flex;align-items:center;gap:8px;margin:12px 0;">
				<code id="faz-shortcode-text" style="font-size:14px;padding:8px 12px;background:var(--faz-bg);border:1px solid var(--faz-border);border-radius:var(--faz-radius);user-select:all;">[faz_cookie_table]</code>
				<button class="faz-btn faz-btn-outline faz-btn-sm" id="faz-copy-shortcode" type="button"><?php echo esc_html__( 'Copy', 'faz-cookie-manager' ); ?></button>
			</div>
			<details style="margin-top:8px;">
				<summary style="cursor:pointer;font-weight:500;font-size:13px;"><?php echo esc_html__( 'Advanced options', 'faz-cookie-manager' ); ?></summary>
				<div style="margin-top:8px;font-size:13px;line-height:1.6;">
					<p><?php echo esc_html__( 'You can customize the shortcode with these attributes:', 'faz-cookie-manager' ); ?></p>
					<table class="faz-table" style="font-size:13px;">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Attribute', 'faz-cookie-manager' ); ?></th>
								<th><?php echo esc_html__( 'Default', 'faz-cookie-manager' ); ?></th>
								<th><?php echo esc_html__( 'Description', 'faz-cookie-manager' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><code>columns</code></td>
								<td><code>name,domain,duration,description</code></td>
								<td><?php echo esc_html__( 'Comma-separated list of columns. Available: name, domain, duration, description, category', 'faz-cookie-manager' ); ?></td>
							</tr>
							<tr>
								<td><code>category</code></td>
								<td><em><?php echo esc_html__( '(all)', 'faz-cookie-manager' ); ?></em></td>
								<td><?php echo esc_html__( 'Filter by category slug (e.g. analytics) or ID', 'faz-cookie-manager' ); ?></td>
							</tr>
							<tr>
								<td><code>heading</code></td>
								<td><em><?php echo esc_html__( '(none)', 'faz-cookie-manager' ); ?></em></td>
								<td><?php echo esc_html__( 'Optional heading text above the table', 'faz-cookie-manager' ); ?></td>
							</tr>
						</tbody>
					</table>
					<p style="margin-top:8px;"><strong><?php echo esc_html__( 'Example:', 'faz-cookie-manager' ); ?></strong> <code>[faz_cookie_table columns="name,duration,description" category="analytics"]</code></p>
					<p style="margin-top:4px;"><?php echo esc_html__( 'The legacy shortcode [cookie_audit] is also supported for backward compatibility.', 'faz-cookie-manager' ); ?></p>
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
			FAZ.notify('Shortcode copied!');
		});
	} else {
		var range = document.createRange();
		range.selectNodeContents(document.getElementById('faz-shortcode-text'));
		var sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange(range);
		document.execCommand('copy');
		FAZ.notify('Shortcode copied!');
	}
});
</script>
