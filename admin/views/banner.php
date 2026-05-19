<?php
/**
 * FAZ Cookie Manager — Cookie Banner (Customize) Page
 *
 * @package FazCookie\Admin
 */

defined( 'ABSPATH' ) || exit;
?>

<div id="faz-banner">

	<!-- Banner switcher (1.14.1+) — chip-style list of every banner row,
	     always visible (replaces the collapsed <select> dropdown used in
	     1.14.0). Populated by banner.js from /faz/v1/banners. Active chip
	     uses the dark-fill primary state; clicking any other chip
	     deep-links to its banner_id. The rename input that used to live
	     here moved into the General tab (less visual noise + clearer
	     scoping: "I'm editing this banner, here's its name"). -->
	<div id="faz-b-switcher" style="display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem;font-size:13px;flex-wrap:wrap;">
		<span style="color:#6b7280;white-space:nowrap;font-weight:500;">
			<?php esc_html_e( 'Banners:', 'faz-cookie-manager' ); ?>
		</span>
		<div id="faz-b-switcher-chips" style="display:flex;gap:.35rem;flex-wrap:wrap;align-items:center;"></div>
		<button type="button" class="faz-btn faz-btn-sm faz-btn-secondary" id="faz-b-switcher-new" title="<?php esc_attr_e( 'Create a new banner', 'faz-cookie-manager' ); ?>" style="padding:.25rem .6rem;font-size:13px;line-height:1;">
			+ <?php esc_html_e( 'New', 'faz-cookie-manager' ); ?>
		</button>
		<button type="button" class="faz-btn faz-btn-sm faz-btn-secondary" id="faz-b-switcher-delete" title="<?php esc_attr_e( 'Delete this banner', 'faz-cookie-manager' ); ?>" style="display:none;padding:.25rem .5rem;font-size:13px;line-height:1;color:#b91c1c;border-color:#fecaca;" aria-label="<?php esc_attr_e( 'Delete this banner', 'faz-cookie-manager' ); ?>">
			&times;
		</button>
	</div>

	<!--
	     Tab order (1.14.1+): Geo Targeting promoted to 2nd position so the
	     "WHO sees this banner" question is answered immediately after the
	     "is it on/off" toggle, BEFORE the admin starts customising content
	     and colours for a banner they may not have correctly targeted yet.
	     Content / Colours / Buttons / Preference Center / Advanced follow
	     in customisation-frequency order. The data-tab slugs are unchanged
	     so deep-links (#tab-geo, etc.) keep working.
	-->
	<div class="faz-tabs" id="faz-banner-tabs">
		<button class="faz-tab active" data-tab="general"><?php esc_html_e( 'General', 'faz-cookie-manager' ); ?></button>
		<button class="faz-tab" data-tab="geo"><?php esc_html_e( 'Geo Targeting', 'faz-cookie-manager' ); ?></button>
		<button class="faz-tab" data-tab="content"><?php esc_html_e( 'Content', 'faz-cookie-manager' ); ?></button>
		<button class="faz-tab" data-tab="colours"><?php esc_html_e( 'Colours', 'faz-cookie-manager' ); ?></button>
		<button class="faz-tab" data-tab="buttons"><?php esc_html_e( 'Buttons', 'faz-cookie-manager' ); ?></button>
		<button class="faz-tab" data-tab="preferences"><?php esc_html_e( 'Preference Center', 'faz-cookie-manager' ); ?></button>
		<button class="faz-tab" data-tab="advanced"><?php esc_html_e( 'Advanced', 'faz-cookie-manager' ); ?></button>
	</div>

	<!--
	     Missing-banner notice. Shown by banner.js when GET /faz/v1/banners/{id}
	     returns 404 (i.e. the ?banner_id= in the URL points to a row that no
	     longer exists — e.g. after a banner deletion or an old bookmark from
	     before the auto-increment bug fix in 1.14.1). The JS hides the tabs +
	     editor below it and surfaces a CTA that links back to the default
	     banner so the admin doesn't see an empty / half-rendered editor.
	-->
	<div id="faz-banner-missing" class="faz-card" style="display:none;border-color:#fbbf24;background:#fffbeb;">
		<div class="faz-card-body" style="display:flex;flex-direction:column;gap:.75rem;">
			<h3 style="margin:0;color:#92400e;">
				<?php esc_html_e( 'This banner does not exist', 'faz-cookie-manager' ); ?>
			</h3>
			<p style="margin:0;color:#78350f;">
				<?php
				printf(
					/* translators: %s: HTML <code> with the banner id from the URL. */
					esc_html__( 'Banner ID %s was not found. It may have been deleted, or the link you followed is from an older version of the plugin.', 'faz-cookie-manager' ),
					'<code id="faz-banner-missing-id" style="background:#fef3c7;padding:.1rem .35rem;border-radius:3px;">—</code>'
				);
				?>
			</p>
			<div style="display:flex;gap:.5rem;flex-wrap:wrap;">
				<a href="#" id="faz-banner-missing-default" class="faz-btn faz-btn-primary">
					<?php esc_html_e( 'Open the default banner', 'faz-cookie-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=faz-cookie-manager' ) ); ?>" class="faz-btn faz-btn-secondary">
					<?php esc_html_e( 'Back to dashboard', 'faz-cookie-manager' ); ?>
				</a>
			</div>
		</div>
	</div>
	<div id="faz-banner-body">

	<!-- ─── General ─────────────────────────────────────── -->
	<div id="tab-general" class="faz-tab-panel active">

		<!--
		     Banner name (1.14.1+) — moved here from the toolbar input that
		     used to live inside #faz-b-switcher. The toolbar version was
		     always visible and edited the *current* banner regardless of
		     where the admin was on the page, which was confusing when the
		     install had two banners with the same name. Keeping it here
		     makes the scoping explicit: this is the name of THE banner
		     you are currently editing, and you change it from inside its
		     own General tab.
		-->
		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Banner Name', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<input type="text" class="faz-input" id="faz-b-name" placeholder="<?php esc_attr_e( 'e.g. GDPR — EU + UK', 'faz-cookie-manager' ); ?>" maxlength="120" style="max-width:480px;" />
					<div class="faz-help"><?php esc_html_e( 'Used to tell your banners apart in the switcher above. Not shown to visitors.', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Banner Status', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label class="faz-toggle">
						<input type="checkbox" id="faz-b-enabled">
						<span class="faz-toggle-track"></span>
						<span class="faz-toggle-label"><?php esc_html_e( 'Enable cookie banner', 'faz-cookie-manager' ); ?></span>
					</label>
					<div class="faz-help"><?php
						$faz_settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=faz-cookie-manager-settings' ) ) . '">'
							. esc_html__( 'Settings → Banner Control', 'faz-cookie-manager' )
							. '</a>';
						echo wp_kses(
							sprintf(
								/* translators: %s: HTML <a> link to the Settings page (Banner Control card). */
								__( 'When disabled, the cookie consent banner will not appear on your site and no scripts will be blocked. This is the same setting available under %s.', 'faz-cookie-manager' ),
								$faz_settings_link
							),
							array(
								'a' => array(
									'href' => array(),
								),
							)
						);
					?></div>
				</div>
			</div>
		</div>

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
				<div class="faz-form-group" id="faz-b-close-with-reject-group" style="margin-left:1.5rem;border-left:3px solid #f59e0b;padding:.75rem 1rem;background:#fffbeb;border-radius:0 4px 4px 0;">
					<label class="faz-toggle" id="faz-b-close-with-reject-toggle">
						<input type="checkbox" id="faz-b-close-with-reject">
						<span class="faz-toggle-track"></span>
						<span style="font-weight:500;">
							<span aria-hidden="true" style="margin-right:.35rem;">&#9888;</span><?php esc_html_e( 'Allow Close (X) alongside Reject button', 'faz-cookie-manager' ); ?>
							<span style="display:inline-block;margin-left:.5rem;padding:.05rem .4rem;font-size:11px;border-radius:3px;background:#92400e;color:#fff;letter-spacing:.5px;text-transform:uppercase;"><?php esc_html_e( 'Non-EU only', 'faz-cookie-manager' ); ?></span>
						</span>
					</label>
					<div class="faz-help" style="margin-top:.4rem;color:#78350f;">
						<?php echo wp_kses(
							sprintf(
								/* translators: %1$s: EDPB Guidelines link, %2$s: Italian Garante Provvedimento link. */
								__( '<strong>Off by default — keep OFF for EU/EEA/UK visitors.</strong> The %1$s and the %2$s identify "X close + labelled Reject on the same banner" as a recognised dark pattern (unequal-weight dismissal paths). Safe to enable on banners targeted at US, Brazil, Canada or Australian traffic where this rule does not apply — typically used together with multi-banner geo-routing (Geo Targeting tab) to keep the X on a CCPA-style banner.', 'faz-cookie-manager' ),
								'<a href="https://edpb.europa.eu/system/files/2023-02/edpb_03-2022_guidelines_on_deceptive_design_patterns_in_social_media_platform_interfaces_v2_en.pdf" target="_blank" rel="noopener noreferrer">EDPB Guidelines 03/2022</a>',
								'<a href="https://www.garanteprivacy.it/web/guest/home/docweb/-/docweb-display/docweb/9677876" target="_blank" rel="noopener noreferrer">Italian Garante Provv. 10 June 2021</a>'
							),
							array(
								'a'      => array(
									'href'   => array(),
									'target' => array(),
									'rel'    => array(),
								),
								'strong' => array(),
							)
						); ?>
					</div>
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

	</div>

	<!-- ─── Tab: Geo Targeting ─────────────────────────────────────── -->
	<div id="tab-geo" class="faz-tab-panel">
		<?php
		// Prerequisite notice: geo-routing silently falls back to "show every
		// banner to everyone" when neither MaxMind nor Cloudflare CF-IPCountry
		// is wired up. Surface that to the admin BEFORE they configure target
		// countries, instead of letting them discover later that the feature
		// they thought they enabled is a no-op.
		$faz_geo_settings = get_option( 'faz_settings', array() );
		$faz_has_maxmind  = ! empty( $faz_geo_settings['geolocation']['maxmind_key'] )
			|| ( defined( 'FAZ_MAXMIND_DB_PATH' ) && FAZ_MAXMIND_DB_PATH && file_exists( FAZ_MAXMIND_DB_PATH ) );
		$faz_has_cf       = (bool) apply_filters( 'faz_trust_cf_ipcountry_header', false );
		if ( ! $faz_has_maxmind && ! $faz_has_cf ) :
			?>
			<div class="faz-card" style="border-left:3px solid #f59e0b;background:#fffbeb;">
				<div class="faz-card-body" style="color:#78350f;">
					<strong style="display:block;margin-bottom:.35rem;">
						<span aria-hidden="true" style="margin-right:.3rem;">&#9888;</span><?php esc_html_e( 'Geo source not configured', 'faz-cookie-manager' ); ?>
					</strong>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %1$s: Settings -> Geolocation link, %2$s: faz_trust_cf_ipcountry_header docs link. */
							__( 'No country signal is available on this install. Multi-banner geo-routing will resolve every visitor to "unknown" and fall back to your match-all / default banner. Configure %1$s with a MaxMind GeoLite2 key, or enable the %2$s if your site sits behind Cloudflare. Without one of these, the targets you set below have no effect.', 'faz-cookie-manager' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=faz-cookie-manager-settings#tab-geolocation' ) ) . '">' . esc_html__( 'Settings &raquo; Geolocation', 'faz-cookie-manager' ) . '</a>',
							'<code>faz_trust_cf_ipcountry_header</code>'
						),
						array(
							'a'    => array( 'href' => array() ),
							'code' => array(),
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>

		<!-- In-page guide: explains the split between WHAT-text vs WHERE-target
		     so admins do not look for "language / Do not sell copy" in the
		     Geo Targeting tab (they live in the Content tab) and vice versa.
		     Plus a Brexit / UK-GDPR caveat — EU preset deliberately excludes
		     GB; UK is its own preset. -->
		<div class="faz-card" style="border-left:3px solid #0ea5e9;background:#f0f9ff;">
			<div class="faz-card-body" style="color:#075985;">
				<strong style="display:block;margin-bottom:.5rem;">
					<span aria-hidden="true" style="margin-right:.3rem;">&#9432;</span><?php esc_html_e( 'How multi-banner works — quick guide', 'faz-cookie-manager' ); ?>
				</strong>
				<ul style="margin:0 0 .5rem 1.25rem;padding:0;line-height:1.6;">
					<li><?php
						echo wp_kses(
							sprintf(
								/* translators: %1$s: Geo Targeting (tab name), %2$s: Content (tab name). */
								__( '%1$s (this tab) = <em>WHICH visitors</em> see this banner. Pick countries / regions; the multi-banner picker matches against the visitor\'s detected country. Leave empty to make this banner the universal fallback.', 'faz-cookie-manager' ),
								'<strong>' . esc_html__( 'Geo Targeting', 'faz-cookie-manager' ) . '</strong>',
								'<strong>' . esc_html__( 'Content', 'faz-cookie-manager' ) . '</strong>'
							),
							array( 'strong' => array(), 'em' => array() )
						);
					?></li>
					<li><?php
						echo wp_kses(
							sprintf(
								/* translators: %1$s: Content (tab name). */
								__( '%1$s = <em>WHAT the banner says</em> — title, description, button labels, per-language translations. This is where you put law-specific copy: "Não vender minhas informações pessoais" for LGPD (Brazil), "Do not sell or share my personal information" for CCPA/CPRA (California 2024+), German consent text for GDPR-DE, and so on. Same banner row, multiple languages.', 'faz-cookie-manager' ),
								'<strong>' . esc_html__( 'Content', 'faz-cookie-manager' ) . '</strong>'
							),
							array( 'strong' => array(), 'em' => array() )
						);
					?></li>
					<li><?php
						echo wp_kses(
							__( '<strong>EU</strong> preset = 27 EU + 3 EEA (Iceland, Liechtenstein, Norway). It does <em>not</em> include the UK — pick the <strong>United Kingdom</strong> preset separately when you want a UK-GDPR variant with different copy. To cover EU+UK with one banner, tick both presets.', 'faz-cookie-manager' ),
							array( 'strong' => array(), 'em' => array() )
						);
					?></li>
				</ul>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Region presets', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<fieldset style="border:0;padding:0;margin:0;">
					<legend class="faz-help" style="margin-bottom:1rem;padding:0;">
						<?php esc_html_e( 'Tick the regions where this banner should be shown. A visitor from any country in the selected regions sees this banner. Leave all unchecked to make this banner match every visitor (fallback / single-banner installs).', 'faz-cookie-manager' ); ?>
					</legend>
					<div class="faz-form-group" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.5rem;">
						<label class="faz-toggle"><input type="checkbox" class="faz-b-geo-region" value="EU"><span class="faz-toggle-track"></span><span><?php esc_html_e( 'EU / EEA (27 + IS, LI, NO)', 'faz-cookie-manager' ); ?></span></label>
						<label class="faz-toggle"><input type="checkbox" class="faz-b-geo-region" value="UK"><span class="faz-toggle-track"></span><span><?php esc_html_e( 'United Kingdom (UK-GDPR)', 'faz-cookie-manager' ); ?></span></label>
						<label class="faz-toggle"><input type="checkbox" class="faz-b-geo-region" value="US"><span class="faz-toggle-track"></span><span><?php esc_html_e( 'United States (CCPA / state laws)', 'faz-cookie-manager' ); ?></span></label>
						<label class="faz-toggle"><input type="checkbox" class="faz-b-geo-region" value="CA"><span class="faz-toggle-track"></span><span><?php esc_html_e( 'Canada (PIPEDA)', 'faz-cookie-manager' ); ?></span></label>
						<label class="faz-toggle"><input type="checkbox" class="faz-b-geo-region" value="BR"><span class="faz-toggle-track"></span><span><?php esc_html_e( 'Brazil (LGPD)', 'faz-cookie-manager' ); ?></span></label>
						<label class="faz-toggle"><input type="checkbox" class="faz-b-geo-region" value="AU"><span class="faz-toggle-track"></span><span><?php esc_html_e( 'Australia', 'faz-cookie-manager' ); ?></span></label>
						<label class="faz-toggle"><input type="checkbox" class="faz-b-geo-region" value="JP"><span class="faz-toggle-track"></span><span><?php esc_html_e( 'Japan (APPI)', 'faz-cookie-manager' ); ?></span></label>
						<label class="faz-toggle"><input type="checkbox" class="faz-b-geo-region" value="CH"><span class="faz-toggle-track"></span><span><?php esc_html_e( 'Switzerland (nFADP)', 'faz-cookie-manager' ); ?></span></label>
					</div>
				</fieldset>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Custom country list', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label for="faz-b-geo-custom"><?php esc_html_e( 'Additional ISO-3166 alpha-2 country codes', 'faz-cookie-manager' ); ?></label>
					<input type="text" class="faz-input" id="faz-b-geo-custom" placeholder="<?php esc_attr_e( 'e.g. NZ, SG, KR', 'faz-cookie-manager' ); ?>" style="max-width:480px;">
					<div class="faz-help"><?php esc_html_e( 'Comma-separated, two letters per code. Use this for countries not covered by the region presets above. Codes are normalised to upper-case and deduplicated automatically.', 'faz-cookie-manager' ); ?></div>
				</div>
			</div>
		</div>

		<div class="faz-card">
			<div class="faz-card-header"><h3><?php esc_html_e( 'Priority & fallback', 'faz-cookie-manager' ); ?></h3></div>
			<div class="faz-card-body">
				<div class="faz-form-group">
					<label for="faz-b-geo-priority"><?php esc_html_e( 'Priority', 'faz-cookie-manager' ); ?></label>
					<input type="number" class="faz-input faz-input-sm" id="faz-b-geo-priority" min="0" max="9999" step="1" value="0" style="width:140px;">
					<div class="faz-help"><?php esc_html_e( 'Tie-breaker when multiple banners target the same country. Higher wins. Default 0.', 'faz-cookie-manager' ); ?></div>
				</div>
				<div class="faz-form-group">
					<label class="faz-toggle" id="faz-b-geo-default-toggle">
						<input type="checkbox" id="faz-b-geo-default">
						<span class="faz-toggle-track"></span>
						<span><?php esc_html_e( 'Use this banner as the default fallback', 'faz-cookie-manager' ); ?></span>
					</label>
					<div class="faz-help"><?php esc_html_e( 'Shown to visitors from countries no banner targets explicitly. Exactly one banner should be marked as default. Saving this option will clear the flag on every other banner.', 'faz-cookie-manager' ); ?></div>
					<div id="faz-b-geo-default-impact" class="faz-help" style="display:none;margin-top:.5rem;padding:.5rem .75rem;background:#fef3c7;border-left:3px solid #f59e0b;color:#78350f;border-radius:0 3px 3px 0;"></div>
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
					tabindex="-1"
					aria-hidden="true"
					loading="eager"
					referrerpolicy="same-origin"
					sandbox="allow-same-origin"
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
	</div><!-- /#faz-banner-body -->
</div>
