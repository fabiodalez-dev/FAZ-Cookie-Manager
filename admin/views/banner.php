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
		<button class="faz-tab active" data-tab="general"><?php esc_html_e( 'General', 'faz-cookie-manager' ); ?></button>
		<button class="faz-tab" data-tab="content"><?php esc_html_e( 'Content', 'faz-cookie-manager' ); ?></button>
		<button class="faz-tab" data-tab="colours"><?php esc_html_e( 'Colours', 'faz-cookie-manager' ); ?></button>
		<button class="faz-tab" data-tab="buttons"><?php esc_html_e( 'Buttons', 'faz-cookie-manager' ); ?></button>
		<button class="faz-tab" data-tab="preferences"><?php esc_html_e( 'Preference Center', 'faz-cookie-manager' ); ?></button>
		<button class="faz-tab" data-tab="advanced"><?php esc_html_e( 'Advanced', 'faz-cookie-manager' ); ?></button>
	</div>

	<!-- ─── General ─────────────────────────────────────── -->
	<div id="tab-general" class="faz-tab-panel active">
		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Design Presets', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div id="faz-presets-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;">
					<p style="color:var(--faz-text-muted);"><?php esc_html_e( 'Loading presets...', 'faz-cookie-manager' ); ?></p>
				</div>
				<div class="faz-help" style="margin-top:8px;"><?php esc_html_e( 'Click a preset to apply its colours and layout. Your text content will not be changed.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Banner Layout', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">

				<div class="faz-form-group">
					<label><?php esc_html_e( 'Banner Type', 'faz-cookie-manager' ); ?></label>
					<select class="faz-select" id="faz-b-type" style="width:auto;max-width:280px;">
						<option value="box"><?php esc_html_e( 'Box (bottom corner)', 'faz-cookie-manager' ); ?></option>
						<option value="banner"><?php esc_html_e( 'Full-width Banner', 'faz-cookie-manager' ); ?></option>
						<option value="classic"><?php esc_html_e( 'Classic', 'faz-cookie-manager' ); ?></option>
					</select>
				</div>

				<div class="faz-form-group">
					<label><?php esc_html_e( 'Position', 'faz-cookie-manager' ); ?></label>
					<select class="faz-select" id="faz-b-position" style="width:auto;max-width:280px;">
						<option value="bottom-left"><?php esc_html_e( 'Bottom Left', 'faz-cookie-manager' ); ?></option>
						<option value="bottom-right"><?php esc_html_e( 'Bottom Right', 'faz-cookie-manager' ); ?></option>
						<option value="top"><?php esc_html_e( 'Top', 'faz-cookie-manager' ); ?></option>
						<option value="bottom"><?php esc_html_e( 'Bottom', 'faz-cookie-manager' ); ?></option>
					</select>
				</div>

				<div class="faz-form-group">
					<label><?php esc_html_e( 'Theme', 'faz-cookie-manager' ); ?></label>
					<select class="faz-select" id="faz-b-theme" style="width:auto;max-width:280px;">
						<option value="light"><?php esc_html_e( 'Light', 'faz-cookie-manager' ); ?></option>
						<option value="dark"><?php esc_html_e( 'Dark', 'faz-cookie-manager' ); ?></option>
					</select>
				</div>

				<div class="faz-form-group">
					<label><?php esc_html_e( 'Preference Center Type', 'faz-cookie-manager' ); ?></label>
					<select class="faz-select" id="faz-b-pref-type" style="width:auto;max-width:280px;">
						<option value="popup"><?php esc_html_e( 'Popup', 'faz-cookie-manager' ); ?></option>
						<option value="pushdown"><?php esc_html_e( 'Pushdown', 'faz-cookie-manager' ); ?></option>
						<option value="sidebar"><?php esc_html_e( 'Sidebar', 'faz-cookie-manager' ); ?></option>
					</select>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Applicable Regulation', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label><?php esc_html_e( 'Privacy Regulation', 'faz-cookie-manager' ); ?></label>
					<select class="faz-select" id="faz-b-law" style="width:auto;max-width:320px;">
						<option value="gdpr"><?php esc_html_e( 'GDPR (EU General Data Protection Regulation)', 'faz-cookie-manager' ); ?></option>
						<option value="ccpa"><?php esc_html_e( 'CCPA / US State Privacy Laws', 'faz-cookie-manager' ); ?></option>
						<option value="gdpr_ccpa"><?php esc_html_e( 'Both GDPR + US State Laws', 'faz-cookie-manager' ); ?></option>
					</select>
					<div class="faz-help">
						<?php echo wp_kses_post( __( '<strong>GDPR</strong>: Shows consent category toggles. Visitors must opt-in.<br><strong>CCPA / US State Laws</strong>: Shows "Do Not Sell or Share My Personal Data" opt-out link.<br><strong>Both</strong>: Shows both category toggles and opt-out link.', 'faz-cookie-manager' ) ); ?>
					</div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Consent Expiry', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label><?php esc_html_e( 'Days until consent expires', 'faz-cookie-manager' ); ?></label>
					<input type="number" class="faz-input" id="faz-b-expiry" min="1" max="3650" style="width:120px;">
					<div class="faz-help"><?php esc_html_e( 'After this many days, visitors will see the banner again.', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Brand Logo', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-brandlogo-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php esc_html_e( 'Show brand logo in banner', 'faz-cookie-manager' ); ?></span>
					</label>
				</div>
				<div class="faz-form-group" id="faz-b-brandlogo-group">
					<label><?php esc_html_e( 'Logo Image', 'faz-cookie-manager' ); ?></label>
					<div style="display:flex;align-items:center;gap:12px;">
						<img id="faz-b-brandlogo-preview" src="" alt="<?php esc_attr_e( 'Brand Logo Preview', 'faz-cookie-manager' ); ?>"
							style="max-width:120px;max-height:60px;border:1px solid var(--faz-border);border-radius:4px;padding:4px;background:#fff;display:none;">
						<button type="button" class="faz-btn faz-btn-outline faz-btn-sm" id="faz-b-brandlogo-upload"><?php esc_html_e( 'Select Image', 'faz-cookie-manager' ); ?></button>
						<button type="button" class="faz-btn faz-btn-outline faz-btn-sm" id="faz-b-brandlogo-remove" style="display:none;color:var(--faz-danger);"><?php esc_html_e( 'Remove', 'faz-cookie-manager' ); ?></button>
					</div>
					<input type="file" id="faz-b-brandlogo-file" accept="image/*" style="display:none;">
					<input type="hidden" id="faz-b-brandlogo-url" value="">
					<div id="faz-b-brandlogo-upload-status" role="status" aria-live="polite" aria-atomic="true" style="display:none;margin-top:6px;font-size:13px;"></div>
					<div class="faz-help"><?php esc_html_e( 'Select or upload a logo. Uses the media library on WordPress, or file upload on ClassicPress.', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>
	</div>

	<!-- ─── Content ─────────────────────────────────────── -->
	<div id="tab-content" class="faz-tab-panel">
		<div class="faz-card">
			<div class="faz-card-header">
				<h3><?php esc_html_e( 'Banner Text', 'faz-cookie-manager' ); ?></h3>
				<div class="faz-card-header-actions">
					<label style="font-weight:normal;font-size:13px;"><?php esc_html_e( 'Language:', 'faz-cookie-manager' ); ?>
						<select class="faz-select faz-select-sm" id="faz-b-content-lang" style="width:auto;min-width:120px;"></select>
					</label>
				</div>
			</div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label><?php esc_html_e( 'Notice Title', 'faz-cookie-manager' ); ?></label>
					<input type="text" class="faz-input" id="faz-b-notice-title" placeholder="<?php esc_attr_e( 'We value your privacy', 'faz-cookie-manager' ); ?>">
				</div>
				<div class="faz-form-group">
					<label><?php esc_html_e( 'Notice Description', 'faz-cookie-manager' ); ?></label>
					<textarea class="faz-textarea faz-richtext" id="faz-b-notice-desc" rows="7" placeholder="<?php esc_attr_e( 'Describe what the banner is asking consent for. HTML is supported if you need links or formatting.', 'faz-cookie-manager' ); ?>"></textarea>
					<div class="faz-help"><?php esc_html_e( 'Supports plain text or HTML. The preview uses the frontend site styles for the final result.', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Button Labels', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-grid faz-grid-2">
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Accept Button', 'faz-cookie-manager' ); ?></label>
						<input type="text" class="faz-input" id="faz-b-btn-accept-label" placeholder="<?php esc_attr_e( 'Accept All', 'faz-cookie-manager' ); ?>">
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Reject Button', 'faz-cookie-manager' ); ?></label>
						<input type="text" class="faz-input" id="faz-b-btn-reject-label" placeholder="<?php esc_attr_e( 'Reject All', 'faz-cookie-manager' ); ?>">
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Settings Button', 'faz-cookie-manager' ); ?></label>
						<input type="text" class="faz-input" id="faz-b-btn-settings-label" placeholder="<?php esc_attr_e( 'Customize', 'faz-cookie-manager' ); ?>">
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Read More Link', 'faz-cookie-manager' ); ?></label>
						<input type="text" class="faz-input" id="faz-b-btn-readmore-label" placeholder="<?php esc_attr_e( 'Cookie Policy', 'faz-cookie-manager' ); ?>">
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Cookie Policy URL', 'faz-cookie-manager' ); ?></label>
						<input type="text" class="faz-input" id="faz-b-privacy-link" placeholder="/cookie-policy">
						<div class="faz-help"><?php esc_html_e( 'Relative (/cookie-policy) or absolute (https://example.com/privacy). Default: /cookie-policy', 'faz-cookie-manager' ); ?></div>
					</div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Close Button', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label><?php esc_html_e( 'Close Button Text (Accessibility)', 'faz-cookie-manager' ); ?></label>
					<input type="text" class="faz-input" id="faz-b-close-label" placeholder="<?php esc_attr_e( 'Close', 'faz-cookie-manager' ); ?>" style="width:200px;">
					<div class="faz-help"><?php echo wp_kses_post( __( 'Used as <code>aria-label</code> for screen readers. The close button displays only the X icon — this text is read aloud by assistive technology to describe the button\'s action.', 'faz-cookie-manager' ) ); ?></div>
				</div>
			</div>
		</div>
	</div>

	<!-- ─── Colours ────────────────────────────────────── -->
	<div id="tab-colours" class="faz-tab-panel">
		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Notice Banner Colours', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-grid faz-grid-3">
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Background', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-notice-bg">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-notice-bg-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Border', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-notice-border">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-notice-border-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Title Text', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-title-color">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-title-color-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Description Text', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-desc-color">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-desc-color-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Link Text', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-link-color" aria-label="<?php esc_attr_e( 'Link text colour picker', 'faz-cookie-manager' ); ?>">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-link-color-hex" aria-label="<?php esc_attr_e( 'Link text colour hex value', 'faz-cookie-manager' ); ?>" style="width:90px;">
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Button Colours', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-grid faz-grid-3">
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Accept — Background', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-accept-bg">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-accept-bg-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Accept — Text', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-accept-text">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-accept-text-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Accept — Border', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-accept-border">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-accept-border-hex" style="width:90px;">
						</div>
					</div>

					<div class="faz-form-group">
						<label><?php esc_html_e( 'Reject — Background', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-reject-bg">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-reject-bg-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Reject — Text', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-reject-text">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-reject-text-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Reject — Border', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-reject-border">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-reject-border-hex" style="width:90px;">
						</div>
					</div>

					<div class="faz-form-group">
						<label><?php esc_html_e( 'Settings — Background', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-settings-bg">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-settings-bg-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Settings — Text', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-settings-text">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-settings-text-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Settings — Border', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-settings-border">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-settings-border-hex" style="width:90px;">
						</div>
					</div>

					<div class="faz-form-group" id="faz-donotsell-color-row" style="display:none;">
						<label><?php esc_html_e( 'Do Not Sell — Text', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-donotsell-text" aria-label="<?php esc_attr_e( 'Do Not Sell text colour picker', 'faz-cookie-manager' ); ?>">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-donotsell-text-hex" aria-label="<?php esc_attr_e( 'Do Not Sell text colour hex value', 'faz-cookie-manager' ); ?>" style="width:90px;">
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="faz-card" id="faz-catprev-colors-card" style="display:none;">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Category Preview Colours', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-grid faz-grid-3">
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Label Text', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-catprev-label">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-catprev-label-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Toggle — Active', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-catprev-toggle-active">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-catprev-toggle-active-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Toggle — Inactive', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-catprev-toggle-inactive">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-catprev-toggle-inactive-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Save Button — Text', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-catprev-save-text">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-catprev-save-text-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Save Button — Background', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-catprev-save-bg">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-catprev-save-bg-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Save Button — Border', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-catprev-save-border">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-catprev-save-border-hex" style="width:90px;">
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Revisit Widget', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-grid faz-grid-3">
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Background', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-revisit-bg">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-revisit-bg-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Icon', 'faz-cookie-manager' ); ?></label>
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
			<div class="faz-card-header"><h3><?php esc_html_e( 'Button Visibility', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-accept-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php esc_html_e( 'Show Accept Button', 'faz-cookie-manager' ); ?></span>
					</label>
				</div>
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-reject-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php esc_html_e( 'Show Reject Button', 'faz-cookie-manager' ); ?></span>
					</label>
				</div>
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-settings-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php esc_html_e( 'Show Settings Button', 'faz-cookie-manager' ); ?></span>
					</label>
				</div>
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-readmore-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php esc_html_e( 'Show Read More / Cookie Policy Link', 'faz-cookie-manager' ); ?></span>
					</label>
				</div>
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-close-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php esc_html_e( 'Show Close Button', 'faz-cookie-manager' ); ?></span>
					</label>
				</div>
			</div>
		</div>
	</div>

	<!-- ─── Preference Center ──────────────────────────── -->
	<div id="tab-preferences" class="faz-tab-panel">
		<div class="faz-card">
			<div class="faz-card-header">
				<h3><?php esc_html_e( 'Preference Center Text', 'faz-cookie-manager' ); ?></h3>
				<div class="faz-card-header-actions">
					<label style="font-weight:normal;font-size:13px;"><?php esc_html_e( 'Language:', 'faz-cookie-manager' ); ?>
						<select class="faz-select faz-select-sm" id="faz-b-pref-lang" style="width:auto;min-width:120px;"></select>
					</label>
				</div>
			</div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label><?php esc_html_e( 'Title', 'faz-cookie-manager' ); ?></label>
					<input type="text" class="faz-input" id="faz-b-pref-title" placeholder="<?php esc_attr_e( 'Customize consent preferences', 'faz-cookie-manager' ); ?>">
				</div>
				<div class="faz-form-group">
					<label><?php esc_html_e( 'Description', 'faz-cookie-manager' ); ?></label>
					<textarea class="faz-textarea faz-richtext" id="faz-b-pref-desc" rows="7" placeholder="<?php esc_attr_e( 'Explain what visitors can change in the preference center. HTML is supported if you need links or formatting.', 'faz-cookie-manager' ); ?>"></textarea>
					<div class="faz-help"><?php esc_html_e( 'Supports plain text or HTML. Keep it short enough to stay readable on mobile.', 'faz-cookie-manager' ); ?></div>
				</div>
				<div class="faz-grid faz-grid-2">
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Accept All Button', 'faz-cookie-manager' ); ?></label>
						<input type="text" class="faz-input" id="faz-b-pref-accept" placeholder="<?php esc_attr_e( 'Accept All', 'faz-cookie-manager' ); ?>">
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Save Preferences Button', 'faz-cookie-manager' ); ?></label>
						<input type="text" class="faz-input" id="faz-b-pref-save" placeholder="<?php esc_attr_e( 'Save My Preferences', 'faz-cookie-manager' ); ?>">
					</div>
					<div class="faz-form-group">
						<label><?php esc_html_e( 'Reject All Button', 'faz-cookie-manager' ); ?></label>
						<input type="text" class="faz-input" id="faz-b-pref-reject" placeholder="<?php esc_attr_e( 'Reject All', 'faz-cookie-manager' ); ?>">
					</div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Audit Table', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-audit-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php esc_html_e( 'Show cookie audit table in preference center', 'faz-cookie-manager' ); ?></span>
					</label>
				</div>
			</div>
		</div>
	</div>

	<!-- ─── Advanced ───────────────────────────────────── -->
	<div id="tab-advanced" class="faz-tab-panel">
		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Revisit Consent', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-revisit-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php esc_html_e( 'Show revisit consent widget', 'faz-cookie-manager' ); ?></span>
					</label>
				</div>
				<div class="faz-form-group">
					<label><?php esc_html_e( 'Widget Position', 'faz-cookie-manager' ); ?></label>
					<select class="faz-select" id="faz-b-revisit-position" style="width:auto;max-width:280px;">
						<option value="bottom-left"><?php esc_html_e( 'Bottom Left', 'faz-cookie-manager' ); ?></option>
						<option value="bottom-right"><?php esc_html_e( 'Bottom Right', 'faz-cookie-manager' ); ?></option>
					</select>
				</div>
				<div class="faz-form-group">
					<label><?php esc_html_e( 'Widget Label', 'faz-cookie-manager' ); ?></label>
					<input type="text" class="faz-input" id="faz-b-revisit-title" placeholder="<?php esc_attr_e( 'Consent Preferences', 'faz-cookie-manager' ); ?>" style="max-width:320px;">
					<div class="faz-help"><?php esc_html_e( 'Used as tooltip and screen reader label (aria-label).', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Behaviours', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-reload-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php esc_html_e( 'Reload page after accepting consent', 'faz-cookie-manager' ); ?></span>
					</label>
				</div>
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-gpc-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php esc_html_e( 'Respect Global Privacy Control (GPC)', 'faz-cookie-manager' ); ?></span>
					</label>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Custom CSS', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label><?php esc_html_e( 'Additional CSS for the banner', 'faz-cookie-manager' ); ?></label>
					<textarea class="faz-textarea faz-textarea-code" id="faz-b-custom-css" rows="6" placeholder=".faz-consent-container { /* your styles */ }"></textarea>
					<div class="faz-help"><?php esc_html_e( 'CSS applied only to the cookie banner.', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>
	</div>

	<!-- Bottom spacer: room for the fixed preview + save bar -->
	<div id="faz-b-spacer" style="height:240px;"></div>

	<!-- ─── Fixed Bottom: Preview + Save Bar ────── -->
	<div id="faz-b-fixed-bottom">
		<div id="faz-b-preview-panel">
			<div id="faz-b-preview-host">
				<iframe
					id="faz-b-preview-frame"
					title="<?php esc_attr_e( 'Frontend banner preview', 'faz-cookie-manager' ); ?>"
					loading="eager"
					referrerpolicy="same-origin"
					sandbox="allow-same-origin allow-scripts"
				></iframe>
				<div id="faz-b-preview-message" role="status" aria-live="polite" aria-atomic="true">
					<?php esc_html_e( 'Loading real site preview...', 'faz-cookie-manager' ); ?>
				</div>
			</div>
		</div>
		<div class="faz-save-bar">
			<button class="faz-btn faz-btn-primary" id="faz-b-save"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> <?php esc_html_e( 'Save Banner Settings', 'faz-cookie-manager' ); ?></button>
			<button class="faz-btn faz-btn-outline" id="faz-b-toggle-preview" type="button"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> <?php esc_html_e( 'Hide Preview', 'faz-cookie-manager' ); ?></button>
			<button class="faz-btn faz-btn-outline" id="faz-b-refresh-preview" type="button"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> <?php esc_html_e( 'Refresh Preview', 'faz-cookie-manager' ); ?></button>
			<span class="faz-save-status" id="faz-b-status"></span>
		</div>
	</div>
</div>
