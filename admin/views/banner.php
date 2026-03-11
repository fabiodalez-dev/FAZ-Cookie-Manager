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
		<button class="faz-tab active" data-tab="general"><?php echo esc_html__( 'General', 'faz-cookie-manager' ); ?></button>
		<button class="faz-tab" data-tab="content"><?php echo esc_html__( 'Content', 'faz-cookie-manager' ); ?></button>
		<button class="faz-tab" data-tab="colours"><?php echo esc_html__( 'Colours', 'faz-cookie-manager' ); ?></button>
		<button class="faz-tab" data-tab="buttons"><?php echo esc_html__( 'Buttons', 'faz-cookie-manager' ); ?></button>
		<button class="faz-tab" data-tab="preferences"><?php echo esc_html__( 'Preference Center', 'faz-cookie-manager' ); ?></button>
		<button class="faz-tab" data-tab="advanced"><?php echo esc_html__( 'Advanced', 'faz-cookie-manager' ); ?></button>
	</div>

	<!-- ─── General ─────────────────────────────────────── -->
	<div id="tab-general" class="faz-tab-panel active">
		<div class="faz-card">
			<div class="faz-card-header"><h3><?php echo esc_html__( 'Banner Layout', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">

				<div class="faz-form-group">
					<label><?php echo esc_html__( 'Banner Type', 'faz-cookie-manager' ); ?></label>
					<select class="faz-select" id="faz-b-type" style="width:auto;max-width:280px;">
						<option value="box"><?php echo esc_html__( 'Box (bottom corner)', 'faz-cookie-manager' ); ?></option>
						<option value="banner"><?php echo esc_html__( 'Full-width Banner', 'faz-cookie-manager' ); ?></option>
						<option value="classic"><?php echo esc_html__( 'Classic', 'faz-cookie-manager' ); ?></option>
					</select>
					<div class="faz-help"><?php echo esc_html__( 'Box: compact notice in a corner. Full-width: spans the full width of the viewport. Classic: full-width with inline category toggles and pushdown preference center.', 'faz-cookie-manager' ); ?></div>
				</div>

				<div class="faz-form-group">
					<label><?php echo esc_html__( 'Position', 'faz-cookie-manager' ); ?></label>
					<select class="faz-select" id="faz-b-position" style="width:auto;max-width:280px;">
						<option value="bottom-left"><?php echo esc_html__( 'Bottom Left', 'faz-cookie-manager' ); ?></option>
						<option value="bottom-right"><?php echo esc_html__( 'Bottom Right', 'faz-cookie-manager' ); ?></option>
						<option value="top"><?php echo esc_html__( 'Top', 'faz-cookie-manager' ); ?></option>
						<option value="bottom"><?php echo esc_html__( 'Bottom', 'faz-cookie-manager' ); ?></option>
					</select>
				</div>

				<div class="faz-form-group">
					<label><?php echo esc_html__( 'Theme', 'faz-cookie-manager' ); ?></label>
					<select class="faz-select" id="faz-b-theme" style="width:auto;max-width:280px;">
						<option value="light"><?php echo esc_html__( 'Light', 'faz-cookie-manager' ); ?></option>
						<option value="dark"><?php echo esc_html__( 'Dark', 'faz-cookie-manager' ); ?></option>
					</select>
					<div class="faz-help"><?php echo esc_html__( 'Sets the default colour scheme. You can further customise individual colours in the Colours tab.', 'faz-cookie-manager' ); ?></div>
				</div>

				<div class="faz-form-group">
					<label><?php echo esc_html__( 'Preference Center Type', 'faz-cookie-manager' ); ?></label>
					<select class="faz-select" id="faz-b-pref-type" style="width:auto;max-width:280px;">
						<option value="popup"><?php echo esc_html__( 'Popup', 'faz-cookie-manager' ); ?></option>
						<option value="pushdown"><?php echo esc_html__( 'Pushdown', 'faz-cookie-manager' ); ?></option>
						<option value="sidebar"><?php echo esc_html__( 'Sidebar', 'faz-cookie-manager' ); ?></option>
					</select>
					<div class="faz-help"><?php echo esc_html__( 'How the detailed category preferences are displayed. Popup: modal overlay. Pushdown: expands below the banner. Sidebar: slides in from the side.', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php echo esc_html__( 'Applicable Regulation', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label><?php echo esc_html__( 'Privacy Regulation', 'faz-cookie-manager' ); ?></label>
					<select class="faz-select" id="faz-b-law" style="width:auto;max-width:320px;">
						<option value="gdpr"><?php echo esc_html__( 'GDPR (EU General Data Protection Regulation)', 'faz-cookie-manager' ); ?></option>
						<option value="ccpa"><?php echo esc_html__( 'CCPA / US State Privacy Laws', 'faz-cookie-manager' ); ?></option>
						<option value="gdpr_ccpa"><?php echo esc_html__( 'Both GDPR + US State Laws', 'faz-cookie-manager' ); ?></option>
					</select>
					<div class="faz-help">
						<strong><?php echo esc_html__( 'GDPR', 'faz-cookie-manager' ); ?></strong>: <?php echo esc_html__( 'Shows consent category toggles. Visitors must opt-in before cookies are set.', 'faz-cookie-manager' ); ?><br>
						<strong><?php echo esc_html__( 'CCPA / US State Laws', 'faz-cookie-manager' ); ?></strong>: <?php echo esc_html__( 'Shows a "Do Not Sell or Share My Personal Data" opt-out link.', 'faz-cookie-manager' ); ?><br>
						<strong><?php echo esc_html__( 'Both', 'faz-cookie-manager' ); ?></strong>: <?php echo esc_html__( 'Shows both category toggles and opt-out link. Best for sites with visitors worldwide.', 'faz-cookie-manager' ); ?>
					</div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php echo esc_html__( 'Consent Expiry', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label><?php echo esc_html__( 'Days until consent expires', 'faz-cookie-manager' ); ?></label>
					<input type="number" class="faz-input" id="faz-b-expiry" min="1" max="3650" style="width:120px;">
					<div class="faz-help"><?php echo esc_html__( 'After this many days, visitors will see the banner again. The Italian Garante Privacy recommends a maximum of 180 days (6 months).', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php echo esc_html__( 'Brand Logo', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-brandlogo-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php echo esc_html__( 'Show brand logo in banner', 'faz-cookie-manager' ); ?></span>
					</label>
				</div>
				<div class="faz-form-group" id="faz-b-brandlogo-group">
					<label><?php echo esc_html__( 'Logo Image', 'faz-cookie-manager' ); ?></label>
					<div style="display:flex;align-items:center;gap:12px;">
						<img id="faz-b-brandlogo-preview" src="" alt="<?php echo esc_attr__( 'Brand Logo Preview', 'faz-cookie-manager' ); ?>"
							style="max-width:120px;max-height:60px;border:1px solid var(--faz-border);border-radius:4px;padding:4px;background:#fff;display:none;">
						<button type="button" class="faz-btn faz-btn-outline faz-btn-sm" id="faz-b-brandlogo-upload"><?php echo esc_html__( 'Select Image', 'faz-cookie-manager' ); ?></button>
						<button type="button" class="faz-btn faz-btn-outline faz-btn-sm" id="faz-b-brandlogo-remove" style="display:none;color:var(--faz-danger);"><?php echo esc_html__( 'Remove', 'faz-cookie-manager' ); ?></button>
					</div>
					<input type="file" id="faz-b-brandlogo-file" accept="image/*" style="display:none;">
					<input type="hidden" id="faz-b-brandlogo-url" value="">
					<div id="faz-b-brandlogo-upload-status" role="status" aria-live="polite" aria-atomic="true" style="display:none;margin-top:6px;font-size:13px;"></div>
					<div class="faz-help"><?php echo esc_html__( 'Select or upload a logo. Uses the media library on WordPress, or file upload on ClassicPress. Recommended maximum size: 120 x 60 px.', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>
	</div>

	<!-- ─── Content ─────────────────────────────────────── -->
	<div id="tab-content" class="faz-tab-panel">
		<div class="faz-card">
			<div class="faz-card-header">
				<h3><?php echo esc_html__( 'Banner Text', 'faz-cookie-manager' ); ?></h3>
				<div class="faz-card-header-actions">
					<label style="font-weight:normal;font-size:13px;"><?php echo esc_html__( 'Language:', 'faz-cookie-manager' ); ?>
						<select class="faz-select faz-select-sm" id="faz-b-content-lang" style="width:auto;min-width:120px;"></select>
					</label>
				</div>
			</div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label><?php echo esc_html__( 'Notice Title', 'faz-cookie-manager' ); ?></label>
					<input type="text" class="faz-input" id="faz-b-notice-title" placeholder="<?php echo esc_attr__( 'We value your privacy', 'faz-cookie-manager' ); ?>">
				</div>
				<div class="faz-form-group">
					<label><?php echo esc_html__( 'Notice Description', 'faz-cookie-manager' ); ?></label>
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
			<div class="faz-card-header"><h3><?php echo esc_html__( 'Button Labels', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-help" style="margin:0 0 12px;"><?php echo esc_html__( 'Customise the text on each banner button. These labels are language-specific — switch language above to translate for each language.', 'faz-cookie-manager' ); ?></div>
				<div class="faz-grid faz-grid-2">
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Accept Button', 'faz-cookie-manager' ); ?></label>
						<input type="text" class="faz-input" id="faz-b-btn-accept-label" placeholder="<?php echo esc_attr__( 'Accept All', 'faz-cookie-manager' ); ?>">
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Reject Button', 'faz-cookie-manager' ); ?></label>
						<input type="text" class="faz-input" id="faz-b-btn-reject-label" placeholder="<?php echo esc_attr__( 'Reject All', 'faz-cookie-manager' ); ?>">
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Settings Button', 'faz-cookie-manager' ); ?></label>
						<input type="text" class="faz-input" id="faz-b-btn-settings-label" placeholder="<?php echo esc_attr__( 'Customize', 'faz-cookie-manager' ); ?>">
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Read More Link', 'faz-cookie-manager' ); ?></label>
						<input type="text" class="faz-input" id="faz-b-btn-readmore-label" placeholder="<?php echo esc_attr__( 'Cookie Policy', 'faz-cookie-manager' ); ?>">
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
			<div class="faz-card-header"><h3><?php echo esc_html__( 'Close Button', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label><?php echo esc_html__( 'Close Button Text (Accessibility)', 'faz-cookie-manager' ); ?></label>
					<input type="text" class="faz-input" id="faz-b-close-label" placeholder="<?php echo esc_attr__( 'Close', 'faz-cookie-manager' ); ?>" style="width:200px;">
					<div class="faz-help"><?php echo esc_html__( 'Used as aria-label for screen readers. The close button displays only the X icon — this text is read aloud by assistive technology to describe the button\'s action.', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>
	</div>

	<!-- ─── Colours ────────────────────────────────────── -->
	<div id="tab-colours" class="faz-tab-panel">
		<div class="faz-card">
			<div class="faz-card-header"><h3><?php echo esc_html__( 'Notice Banner Colours', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-grid faz-grid-3">
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Background', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-notice-bg">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-notice-bg-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Border', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-notice-border">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-notice-border-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Title Text', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-title-color">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-title-color-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Description Text', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-desc-color">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-desc-color-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Link Text', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-link-color" aria-label="<?php echo esc_attr__( 'Link text colour picker', 'faz-cookie-manager' ); ?>">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-link-color-hex" aria-label="<?php echo esc_attr__( 'Link text colour hex value', 'faz-cookie-manager' ); ?>" style="width:90px;">
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php echo esc_html__( 'Button Colours', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-grid faz-grid-3">
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Accept — Background', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-accept-bg">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-accept-bg-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Accept — Text', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-accept-text">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-accept-text-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Accept — Border', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-accept-border">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-accept-border-hex" style="width:90px;">
						</div>
					</div>

					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Reject — Background', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-reject-bg">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-reject-bg-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Reject — Text', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-reject-text">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-reject-text-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Reject — Border', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-reject-border">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-reject-border-hex" style="width:90px;">
						</div>
					</div>

					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Settings — Background', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-settings-bg">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-settings-bg-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Settings — Text', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-settings-text">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-settings-text-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Settings — Border', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-settings-border">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-settings-border-hex" style="width:90px;">
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="faz-card" id="faz-catprev-colors-card" style="display:none;">
			<div class="faz-card-header"><h3><?php echo esc_html__( 'Category Preview Colours', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-grid faz-grid-3">
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Label Text', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-catprev-label">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-catprev-label-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Toggle — Active', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-catprev-toggle-active">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-catprev-toggle-active-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Toggle — Inactive', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-catprev-toggle-inactive">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-catprev-toggle-inactive-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Save Button — Text', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-catprev-save-text">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-catprev-save-text-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Save Button — Background', 'faz-cookie-manager' ); ?></label>
						<div class="faz-input-color-wrap">
							<input type="color" id="faz-b-catprev-save-bg">
							<input type="text" class="faz-input faz-input-sm" id="faz-b-catprev-save-bg-hex" style="width:90px;">
						</div>
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Save Button — Border', 'faz-cookie-manager' ); ?></label>
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
			<div class="faz-card-header"><h3><?php echo esc_html__( 'Button Visibility', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-help" style="margin:0 0 12px;"><?php echo esc_html__( 'Control which buttons appear on the consent banner. GDPR requires that Reject and Accept have equal visual prominence.', 'faz-cookie-manager' ); ?></div>
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-accept-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php echo esc_html__( 'Show Accept Button', 'faz-cookie-manager' ); ?></span>
					</label>
				</div>
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-reject-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php echo esc_html__( 'Show Reject Button', 'faz-cookie-manager' ); ?></span>
					</label>
				</div>
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-settings-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php echo esc_html__( 'Show Settings Button', 'faz-cookie-manager' ); ?></span>
					</label>
					<div class="faz-help"><?php echo esc_html__( 'Opens the preference center where visitors can accept or reject individual cookie categories.', 'faz-cookie-manager' ); ?></div>
				</div>
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-readmore-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php echo esc_html__( 'Show Read More / Cookie Policy Link', 'faz-cookie-manager' ); ?></span>
					</label>
				</div>
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-close-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php echo esc_html__( 'Show Close Button', 'faz-cookie-manager' ); ?></span>
					</label>
					<div class="faz-help"><?php echo esc_html__( 'Shows an X icon to dismiss the banner. Note: under GDPR, closing without choosing is not valid consent — cookies remain blocked.', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>
	</div>

	<!-- ─── Preference Center ──────────────────────────── -->
	<div id="tab-preferences" class="faz-tab-panel">
		<div class="faz-card">
			<div class="faz-card-header">
				<h3><?php echo esc_html__( 'Preference Center Text', 'faz-cookie-manager' ); ?></h3>
				<div class="faz-card-header-actions">
					<label style="font-weight:normal;font-size:13px;"><?php echo esc_html__( 'Language:', 'faz-cookie-manager' ); ?>
						<select class="faz-select faz-select-sm" id="faz-b-pref-lang" style="width:auto;min-width:120px;"></select>
					</label>
				</div>
			</div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label><?php echo esc_html__( 'Title', 'faz-cookie-manager' ); ?></label>
					<input type="text" class="faz-input" id="faz-b-pref-title" placeholder="<?php echo esc_attr__( 'Customize consent preferences', 'faz-cookie-manager' ); ?>">
				</div>
				<div class="faz-form-group">
					<label><?php echo esc_html__( 'Description', 'faz-cookie-manager' ); ?></label>
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
						<label><?php echo esc_html__( 'Accept All Button', 'faz-cookie-manager' ); ?></label>
						<input type="text" class="faz-input" id="faz-b-pref-accept" placeholder="<?php echo esc_attr__( 'Accept All', 'faz-cookie-manager' ); ?>">
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Save Preferences Button', 'faz-cookie-manager' ); ?></label>
						<input type="text" class="faz-input" id="faz-b-pref-save" placeholder="<?php echo esc_attr__( 'Save My Preferences', 'faz-cookie-manager' ); ?>">
					</div>
					<div class="faz-form-group">
						<label><?php echo esc_html__( 'Reject All Button', 'faz-cookie-manager' ); ?></label>
						<input type="text" class="faz-input" id="faz-b-pref-reject" placeholder="<?php echo esc_attr__( 'Reject All', 'faz-cookie-manager' ); ?>">
					</div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php echo esc_html__( 'Audit Table', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-audit-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php echo esc_html__( 'Show cookie audit table in preference center', 'faz-cookie-manager' ); ?></span>
					</label>
					<div class="faz-help"><?php echo esc_html__( 'Displays a detailed table of all cookies in each category inside the preference center. Recommended for GDPR transparency.', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>
	</div>

	<!-- ─── Advanced ───────────────────────────────────── -->
	<div id="tab-advanced" class="faz-tab-panel">
		<div class="faz-card">
			<div class="faz-card-header"><h3><?php echo esc_html__( 'Revisit Consent', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-revisit-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php echo esc_html__( 'Show revisit consent widget', 'faz-cookie-manager' ); ?></span>
					</label>
					<div class="faz-help"><?php echo esc_html__( 'Displays a small floating button that lets visitors reopen the preference center and change their consent choices at any time. Required by GDPR for the right to withdraw consent.', 'faz-cookie-manager' ); ?></div>
				</div>
				<div class="faz-form-group">
					<label><?php echo esc_html__( 'Widget Position', 'faz-cookie-manager' ); ?></label>
					<select class="faz-select" id="faz-b-revisit-position" style="width:auto;max-width:280px;">
						<option value="bottom-left"><?php echo esc_html__( 'Bottom Left', 'faz-cookie-manager' ); ?></option>
						<option value="bottom-right"><?php echo esc_html__( 'Bottom Right', 'faz-cookie-manager' ); ?></option>
					</select>
				</div>
				<div class="faz-form-group">
					<label><?php echo esc_html__( 'Widget Label', 'faz-cookie-manager' ); ?></label>
					<input type="text" class="faz-input" id="faz-b-revisit-title" placeholder="<?php echo esc_attr__( 'Consent Preferences', 'faz-cookie-manager' ); ?>" style="max-width:320px;">
					<div class="faz-help"><?php echo esc_html__( 'Used as tooltip and screen reader label (aria-label).', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php echo esc_html__( 'Behaviours', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-reload-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php echo esc_html__( 'Reload page after accepting consent', 'faz-cookie-manager' ); ?></span>
					</label>
					<div class="faz-help"><?php echo esc_html__( 'Forces a full page reload after consent is given. Useful if your theme or plugins need a fresh page load to initialise scripts that were blocked before consent.', 'faz-cookie-manager' ); ?></div>
				</div>
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-gpc-toggle">
						<input type="checkbox">
						<span class="faz-toggle-track"></span>
						<span><?php echo esc_html__( 'Respect Global Privacy Control (GPC)', 'faz-cookie-manager' ); ?></span>
					</label>
					<div class="faz-help"><?php echo esc_html__( 'When the visitor\'s browser sends a GPC signal (Sec-GPC: 1), automatically treat it as an opt-out. Required by some US state privacy laws (e.g. California CPRA, Colorado).', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php echo esc_html__( 'Custom CSS', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label><?php echo esc_html__( 'Additional CSS for the banner', 'faz-cookie-manager' ); ?></label>
					<textarea class="faz-textarea faz-textarea-code" id="faz-b-custom-css" rows="6" placeholder=".faz-consent-container { /* your styles */ }"></textarea>
					<div class="faz-help"><?php echo esc_html__( 'CSS applied only to the cookie banner. Use this to override colours, fonts, or spacing without editing your theme.', 'faz-cookie-manager' ); ?></div>
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
			<button class="faz-btn faz-btn-primary" id="faz-b-save"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> <?php echo esc_html__( 'Save Banner Settings', 'faz-cookie-manager' ); ?></button>
			<button class="faz-btn faz-btn-outline" id="faz-b-toggle-preview" type="button"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> <?php echo esc_html__( 'Hide Preview', 'faz-cookie-manager' ); ?></button>
			<button class="faz-btn faz-btn-outline" id="faz-b-refresh-preview" type="button"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> <?php echo esc_html__( 'Refresh Preview', 'faz-cookie-manager' ); ?></button>
			<span class="faz-save-status" id="faz-b-status"></span>
		</div>
	</div>
	<div id="faz-b-preview-styles"></div>
</div>
