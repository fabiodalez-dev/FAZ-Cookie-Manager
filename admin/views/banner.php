<?php
/**
 * FAZ Cookie Manager — Cookie Banner (Customize) Page
 *
 * @package FazCookie\Admin
 */

defined( 'ABSPATH' ) || exit;
?>

<div id="faz-banner">

	<div class="faz-tabs" id="faz-banner-tabs">
		<button class="faz-tab active" data-tab="general">General</button>
		<button class="faz-tab" data-tab="content">Content</button>
		<button class="faz-tab" data-tab="colours">Colours</button>
		<button class="faz-tab" data-tab="buttons">Buttons</button>
		<button class="faz-tab" data-tab="preferences">Preference Center</button>
		<button class="faz-tab" data-tab="advanced">Advanced</button>
	</div>

	<!-- ─── General ─────────────────────────────────────── -->
	<div id="tab-general" class="faz-tab-panel active">
		<div class="faz-card">
			<div class="faz-card-header"><h3>Banner Layout</h3></div>
			<div class="faz-card-body">

				<div class="faz-form-group">
					<label>Banner Type</label>
					<select class="faz-select" id="faz-b-type" style="width:auto;max-width:280px;">
						<option value="box">Box (bottom corner)</option>
						<option value="banner">Full-width Banner</option>
						<option value="classic">Classic</option>
					</select>
				</div>

				<div class="faz-form-group">
					<label>Position</label>
					<select class="faz-select" id="faz-b-position" style="width:auto;max-width:280px;">
						<option value="bottom-left">Bottom Left</option>
						<option value="bottom-right">Bottom Right</option>
						<option value="top">Top</option>
						<option value="bottom">Bottom</option>
					</select>
				</div>

				<div class="faz-form-group">
					<label>Theme</label>
					<select class="faz-select" id="faz-b-theme" style="width:auto;max-width:280px;">
						<option value="light">Light</option>
						<option value="dark">Dark</option>
					</select>
				</div>

				<div class="faz-form-group">
					<label>Preference Center Type</label>
					<select class="faz-select" id="faz-b-pref-type" style="width:auto;max-width:280px;">
						<option value="popup">Popup</option>
						<option value="pushdown">Pushdown</option>
						<option value="sidebar">Sidebar</option>
					</select>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3>Applicable Regulation</h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label>Privacy Regulation</label>
					<select class="faz-select" id="faz-b-law" style="width:auto;max-width:320px;">
						<option value="gdpr">GDPR (EU General Data Protection Regulation)</option>
						<option value="ccpa">CCPA / US State Privacy Laws</option>
						<option value="gdpr_ccpa">Both GDPR + US State Laws</option>
					</select>
					<div class="faz-help">
						<strong>GDPR</strong>: Shows consent category toggles. Visitors must opt-in.<br>
						<strong>CCPA / US State Laws</strong>: Shows "Do Not Sell or Share My Personal Data" opt-out link.<br>
						<strong>Both</strong>: Shows both category toggles and opt-out link.
					</div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3>Consent Expiry</h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label>Days until consent expires</label>
					<input type="number" class="faz-input" id="faz-b-expiry" min="1" max="3650" style="width:120px;">
					<div class="faz-help">After this many days, visitors will see the banner again.</div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3>Brand Logo</h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-brandlogo-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span>Show brand logo in banner</span>
					</label>
				</div>
				<div class="faz-form-group" id="faz-b-brandlogo-group">
					<label>Logo Image</label>
					<div style="display:flex;align-items:center;gap:12px;">
						<img id="faz-b-brandlogo-preview" src="" alt="Brand Logo Preview"
							style="max-width:120px;max-height:60px;border:1px solid var(--faz-border);border-radius:4px;padding:4px;background:#fff;display:none;">
						<button type="button" class="faz-btn faz-btn-outline faz-btn-sm" id="faz-b-brandlogo-upload">Select Image</button>
						<button type="button" class="faz-btn faz-btn-outline faz-btn-sm" id="faz-b-brandlogo-remove" style="display:none;color:var(--faz-danger);">Remove</button>
					</div>
					<input type="hidden" id="faz-b-brandlogo-url" value="">
					<div class="faz-help">Select or upload a logo from the WordPress Media Library.</div>
				</div>
			</div>
		</div>
	</div>

	<!-- ─── Content ─────────────────────────────────────── -->
	<div id="tab-content" class="faz-tab-panel">
		<div class="faz-card">
			<div class="faz-card-header">
				<h3>Banner Text</h3>
				<div class="faz-card-header-actions">
					<label style="font-weight:normal;font-size:13px;">Language:
						<select class="faz-select faz-select-sm" id="faz-b-content-lang" style="width:auto;min-width:120px;"></select>
					</label>
				</div>
			</div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label>Notice Title</label>
					<input type="text" class="faz-input" id="faz-b-notice-title" placeholder="We value your privacy">
				</div>
				<div class="faz-form-group">
					<label>Notice Description</label>
					<?php
					wp_editor(
						'',
						'faz-b-notice-desc',
						array(
							'textarea_rows' => 6,
							'media_buttons' => false,
							'quicktags'     => true,
							'teeny'         => false,
							'tinymce'       => array(
								'toolbar1' => 'bold,italic,underline,link,unlink,bullist,numlist,blockquote,hr,undo,redo',
								'toolbar2' => '',
							),
						)
					);
					?>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3>Button Labels</h3></div>
			<div class="faz-card-body">
				<div class="faz-grid faz-grid-2">
					<div class="faz-form-group">
						<label>Accept Button</label>
						<input type="text" class="faz-input" id="faz-b-btn-accept-label" placeholder="Accept All">
					</div>
					<div class="faz-form-group">
						<label>Reject Button</label>
						<input type="text" class="faz-input" id="faz-b-btn-reject-label" placeholder="Reject All">
					</div>
					<div class="faz-form-group">
						<label>Settings Button</label>
						<input type="text" class="faz-input" id="faz-b-btn-settings-label" placeholder="Customize">
					</div>
					<div class="faz-form-group">
						<label>Read More Link</label>
						<input type="text" class="faz-input" id="faz-b-btn-readmore-label" placeholder="Cookie Policy">
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Cookie Policy URL', 'faz-cookie-manager' ); ?></label>
						<input type="text" class="faz-input" id="faz-b-privacy-link" placeholder="/cookie-policy">
						<div class="faz-help"><?php echo esc_html__( 'Relative (/cookie-policy) or absolute (https://example.com/privacy). Default: /cookie-policy', 'faz-cookie-manager' ); ?></div>
					</div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3>Close Button</h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label>Close Button Text (Accessibility)</label>
					<input type="text" class="faz-input" id="faz-b-close-label" placeholder="Close" style="width:200px;">
					<div class="faz-help">Used as <code>aria-label</code> for screen readers. The close button displays only the X icon — this text is read aloud by assistive technology to describe the button's action.</div>
				</div>
			</div>
		</div>
	</div>

	<!-- ─── Colours ────────────────────────────────────── -->
	<div id="tab-colours" class="faz-tab-panel">
		<div class="faz-card">
			<div class="faz-card-header"><h3>Notice Banner Colours</h3></div>
			<div class="faz-card-body">
				<div class="faz-grid faz-grid-3">
					<div class="faz-form-group">
						<label>Background</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-notice-bg">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-notice-bg-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label>Border</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-notice-border">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-notice-border-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label>Title Text</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-title-color">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-title-color-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label>Description Text</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-desc-color">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-desc-color-hex" style="width:90px;">
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3>Button Colours</h3></div>
			<div class="faz-card-body">
				<div class="faz-grid faz-grid-3">
					<div class="faz-form-group">
						<label>Accept — Background</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-accept-bg">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-accept-bg-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label>Accept — Text</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-accept-text">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-accept-text-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label>Accept — Border</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-accept-border">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-accept-border-hex" style="width:90px;">
						</div>
					</div>

					<div class="faz-form-group">
						<label>Reject — Background</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-reject-bg">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-reject-bg-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label>Reject — Text</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-reject-text">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-reject-text-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label>Reject — Border</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-reject-border">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-reject-border-hex" style="width:90px;">
						</div>
					</div>

					<div class="faz-form-group">
						<label>Settings — Background</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-settings-bg">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-settings-bg-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label>Settings — Text</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-settings-text">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-settings-text-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label>Settings — Border</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-settings-border">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-settings-border-hex" style="width:90px;">
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="faz-card" id="faz-catprev-colors-card" style="display:none;">
			<div class="faz-card-header"><h3>Category Preview Colours</h3></div>
			<div class="faz-card-body">
				<div class="faz-grid faz-grid-3">
					<div class="faz-form-group">
						<label>Label Text</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-catprev-label">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-catprev-label-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label>Toggle — Active</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-catprev-toggle-active">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-catprev-toggle-active-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label>Toggle — Inactive</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-catprev-toggle-inactive">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-catprev-toggle-inactive-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label>Save Button — Text</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-catprev-save-text">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-catprev-save-text-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label>Save Button — Background</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-catprev-save-bg">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-catprev-save-bg-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label>Save Button — Border</label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-catprev-save-border">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-catprev-save-border-hex" style="width:90px;">
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php echo esc_html__( 'Revisit Widget', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-grid faz-grid-3">
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Background', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-revisit-bg">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-revisit-bg-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Icon', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-revisit-icon">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-revisit-icon-hex" style="width:90px;">
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- ─── Buttons ─────────────────────────────────────── -->
	<div id="tab-buttons" class="faz-tab-panel">
		<div class="faz-card">
			<div class="faz-card-header"><h3>Button Visibility</h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-accept-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span>Show Accept Button</span>
					</label>
				</div>
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-reject-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span>Show Reject Button</span>
					</label>
				</div>
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-settings-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span>Show Settings Button</span>
					</label>
				</div>
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-readmore-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span>Show Read More / Cookie Policy Link</span>
					</label>
				</div>
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-close-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span>Show Close Button</span>
					</label>
				</div>
			</div>
		</div>
	</div>

	<!-- ─── Preference Center ──────────────────────────── -->
	<div id="tab-preferences" class="faz-tab-panel">
		<div class="faz-card">
			<div class="faz-card-header">
				<h3>Preference Center Text</h3>
				<div class="faz-card-header-actions">
					<label style="font-weight:normal;font-size:13px;">Language:
						<select class="faz-select faz-select-sm" id="faz-b-pref-lang" style="width:auto;min-width:120px;"></select>
					</label>
				</div>
			</div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label>Title</label>
					<input type="text" class="faz-input" id="faz-b-pref-title" placeholder="Customize consent preferences">
				</div>
				<div class="faz-form-group">
					<label>Description</label>
					<?php
					wp_editor(
						'',
						'faz-b-pref-desc',
						array(
							'textarea_rows' => 6,
							'media_buttons' => false,
							'quicktags'     => true,
							'teeny'         => false,
							'tinymce'       => array(
								'toolbar1' => 'bold,italic,underline,link,unlink,bullist,numlist,blockquote,hr,undo,redo',
								'toolbar2' => '',
							),
						)
					);
					?>
				</div>
				<div class="faz-grid faz-grid-2">
					<div class="faz-form-group">
						<label>Accept All Button</label>
						<input type="text" class="faz-input" id="faz-b-pref-accept" placeholder="Accept All">
					</div>
					<div class="faz-form-group">
						<label>Save Preferences Button</label>
						<input type="text" class="faz-input" id="faz-b-pref-save" placeholder="Save My Preferences">
					</div>
					<div class="faz-form-group">
						<label>Reject All Button</label>
						<input type="text" class="faz-input" id="faz-b-pref-reject" placeholder="Reject All">
					</div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3>Audit Table</h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-audit-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span>Show cookie audit table in preference center</span>
					</label>
				</div>
			</div>
		</div>
	</div>

	<!-- ─── Advanced ───────────────────────────────────── -->
	<div id="tab-advanced" class="faz-tab-panel">
		<div class="faz-card">
			<div class="faz-card-header"><h3>Revisit Consent</h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-revisit-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span>Show revisit consent widget</span>
					</label>
				</div>
				<div class="faz-form-group">
					<label>Widget Position</label>
					<select class="faz-select" id="faz-b-revisit-position" style="width:auto;max-width:280px;">
						<option value="bottom-left">Bottom Left</option>
						<option value="bottom-right">Bottom Right</option>
					</select>
				</div>
				<div class="faz-form-group">
					<label><?php echo esc_html__( 'Widget Label', 'faz-cookie-manager' ); ?></label>
					<input type="text" class="faz-input" id="faz-b-revisit-title" placeholder="Consent Preferences" style="max-width:320px;">
					<div class="faz-help"><?php echo esc_html__( 'Used as tooltip and screen reader label (aria-label).', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3>Behaviours</h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-reload-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span>Reload page after accepting consent</span>
					</label>
				</div>
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-gpc-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span>Respect Global Privacy Control (GPC)</span>
					</label>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3>Custom CSS</h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label>Additional CSS for the banner</label>
					<textarea class="faz-textarea faz-textarea-code" id="faz-b-custom-css" rows="6" placeholder=".faz-consent-container { /* your styles */ }"></textarea>
					<div class="faz-help">CSS applied only to the cookie banner.</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Bottom spacer: room for the fixed preview + save bar -->
	<div id="faz-b-spacer" style="height:240px;"></div>

	<!-- ─── Fixed Bottom: Preview + Save Bar ────── -->
	<div id="faz-b-fixed-bottom">
		<div id="faz-b-preview-panel">
			<div id="faz-b-preview-host"></div>
		</div>
		<div class="faz-save-bar">
			<button class="faz-btn faz-btn-primary" id="faz-b-save"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Banner Settings</button>
			<button class="faz-btn faz-btn-outline" id="faz-b-toggle-preview" type="button"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Hide Preview</button>
			<button class="faz-btn faz-btn-outline" id="faz-b-refresh-preview" type="button"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> Refresh Preview</button>
			<span class="faz-save-status" id="faz-b-status"></span>
		</div>
	</div>
	<div id="faz-b-preview-styles"></div>
</div>
