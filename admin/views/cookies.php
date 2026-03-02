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
				<h3>Categories</h3>
			</div>
			<div class="faz-card-body">
				<ul class="faz-sidebar-nav" id="faz-cat-list">
					<li><button class="active" data-cat="all">All Cookies <span class="faz-count">--</span></button></li>
				</ul>
			</div>
		</div>
		<div>
			<div class="faz-card">
				<div class="faz-card-header">
					<h3 id="faz-cookies-title">All Cookies</h3>
					<div class="faz-page-header-actions">
						<div class="faz-dropdown" id="faz-scan-dropdown">
							<button class="faz-btn faz-btn-outline faz-btn-sm" id="faz-scan-btn">Scan Site &#9662;</button>
							<div class="faz-dropdown-menu">
								<button class="faz-dropdown-item" data-depth="10">Quick scan (10 pages)</button>
								<button class="faz-dropdown-item" data-depth="100">Standard scan (100 pages)</button>
								<button class="faz-dropdown-item" data-depth="1000">Deep scan (1000 pages)</button>
								<button class="faz-dropdown-item" data-depth="0">Full scan (all pages)</button>
							</div>
						</div>
						<div class="faz-dropdown" id="faz-auto-cat-dropdown">
							<button class="faz-btn faz-btn-outline faz-btn-sm" id="faz-auto-cat-btn">Auto-categorize &#9662;</button>
							<div class="faz-dropdown-menu">
								<button class="faz-dropdown-item" data-scope="uncategorized">Uncategorized only</button>
								<button class="faz-dropdown-item" data-scope="all">All cookies</button>
							</div>
						</div>
						<button class="faz-btn faz-btn-primary faz-btn-sm" id="faz-add-cookie-btn">Add Cookie</button>
					</div>
				</div>
				<div class="faz-card-body">
					<div id="faz-bulk-bar" style="display:none" class="faz-bulk-bar">
						<span class="faz-bulk-count">0 selected</span>
						<button type="button" class="faz-btn faz-btn-sm" id="faz-bulk-delete-btn" style="color:var(--faz-danger)">Delete Selected</button>
					</div>
					<div class="faz-table-wrap">
						<table class="faz-table" id="faz-cookies-table">
							<thead>
								<tr>
									<th style="width:40px"><input type="checkbox" id="faz-select-all-cookies" aria-label="<?php esc_attr_e( 'Select all cookies', 'faz-cookie-manager' ); ?>"></th>
									<th>Name</th>
									<th>Domain</th>
									<th>Duration</th>
									<th>Description</th>
									<th style="text-align:right">Actions</th>
								</tr>
							</thead>
							<tbody id="faz-cookies-tbody">
								<tr><td colspan="6" class="faz-empty"><p>Loading...</p></td></tr>
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
			<h3>Cookie Definitions</h3>
			<div class="faz-page-header-actions">
				<button class="faz-btn faz-btn-outline faz-btn-sm" id="faz-update-defs-btn" type="button">Update Definitions</button>
			</div>
		</div>
		<div class="faz-card-body">
			<p>Cookie definitions are sourced from the <a href="https://github.com/fabiodalez-dev/Open-Cookie-Database" target="_blank" rel="noopener">Open Cookie Database</a> (Apache-2.0 license). These definitions power the auto-categorize feature.</p>
			<div id="faz-defs-status" style="margin-top:8px;font-size:13px;color:var(--faz-text-muted);">Loading status...</div>
		</div>
	</div>

	<!-- Shortcode Info -->
	<div class="faz-card" style="margin-top:16px;">
		<div class="faz-card-header">
			<h3>Cookie Table Shortcode</h3>
		</div>
		<div class="faz-card-body">
			<p>Use the following shortcode to display a table of all cookies on any page or post (e.g. your Cookie Policy page):</p>
			<div style="display:flex;align-items:center;gap:8px;margin:12px 0;">
				<code id="faz-shortcode-text" style="font-size:14px;padding:8px 12px;background:var(--faz-bg);border:1px solid var(--faz-border);border-radius:var(--faz-radius);user-select:all;">[faz_cookie_table]</code>
				<button class="faz-btn faz-btn-outline faz-btn-sm" id="faz-copy-shortcode" type="button">Copy</button>
			</div>
			<details style="margin-top:8px;">
				<summary style="cursor:pointer;font-weight:500;font-size:13px;">Advanced options</summary>
				<div style="margin-top:8px;font-size:13px;line-height:1.6;">
					<p>You can customize the shortcode with these attributes:</p>
					<table class="faz-table" style="font-size:13px;">
						<thead>
							<tr><th>Attribute</th><th>Default</th><th>Description</th></tr>
						</thead>
						<tbody>
							<tr>
								<td><code>columns</code></td>
								<td><code>name,domain,duration,description</code></td>
								<td>Comma-separated list of columns. Available: <code>name</code>, <code>domain</code>, <code>duration</code>, <code>description</code>, <code>category</code></td>
							</tr>
							<tr>
								<td><code>category</code></td>
								<td><em>(all)</em></td>
								<td>Filter by category slug (e.g. <code>analytics</code>) or ID</td>
							</tr>
							<tr>
								<td><code>heading</code></td>
								<td><em>(none)</em></td>
								<td>Optional heading text above the table</td>
							</tr>
						</tbody>
					</table>
					<p style="margin-top:8px;"><strong>Example:</strong> <code>[faz_cookie_table columns="name,duration,description" category="analytics"]</code></p>
					<p style="margin-top:4px;">The legacy shortcode <code>[cookie_audit]</code> is also supported for backward compatibility.</p>
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
