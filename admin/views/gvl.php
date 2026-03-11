<?php
/**
 * FAZ Cookie Manager — GVL (Global Vendor List) Admin Page
 *
 * @package FazCookie\Admin
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="faz-gvl">

	<div class="faz-card">
		<div class="faz-card-header" style="display:flex;align-items:center;justify-content:space-between;">
			<h3><?php esc_html_e( 'Global Vendor List (IAB TCF 2.3)', 'faz-cookie-manager' ); ?></h3>
			<button class="faz-btn faz-btn-secondary faz-btn-sm" id="faz-gvl-download" type="button"><?php esc_html_e( 'Update GVL Now', 'faz-cookie-manager' ); ?></button>
		</div>
		<div class="faz-card-body">
			<div id="faz-gvl-meta" style="padding:10px;border-radius:6px;background:var(--faz-bg-secondary);margin-bottom:16px;">
				<span style="color:var(--faz-text-secondary);"><?php esc_html_e( 'Loading GVL status...', 'faz-cookie-manager' ); ?></span>
			</div>
			<div class="faz-help"><?php esc_html_e( 'The Global Vendor List is published by IAB Europe and contains all registered ad-tech vendors with their declared purposes and legal bases. Select the vendors you work with to include them in the TCF consent flow.', 'faz-cookie-manager' ); ?></div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Vendor Selection', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">
			<div style="display:flex;gap:12px;margin-bottom:16px;align-items:center;">
				<input type="text" id="faz-gvl-search" class="faz-input" placeholder="<?php echo esc_attr__( 'Search vendors...', 'faz-cookie-manager' ); ?>" aria-label="<?php echo esc_attr__( 'Search vendors', 'faz-cookie-manager' ); ?>" style="flex:1;max-width:300px;">
				<select id="faz-gvl-purpose-filter" class="faz-input" aria-label="<?php echo esc_attr__( 'Filter by purpose', 'faz-cookie-manager' ); ?>" style="width:auto;">
					<option value="0"><?php esc_html_e( 'All purposes', 'faz-cookie-manager' ); ?></option>
				</select>
				<span id="faz-gvl-selected-count" aria-live="polite" style="color:var(--faz-text-secondary);white-space:nowrap;"></span>
			</div>

			<div style="margin-bottom:8px;">
				<label for="faz-gvl-select-all" style="cursor:pointer;font-weight:600;">
					<input type="checkbox" id="faz-gvl-select-all"> <?php esc_html_e( 'Select all on this page', 'faz-cookie-manager' ); ?>
				</label>
			</div>

			<div id="faz-gvl-vendor-list"></div>

			<div id="faz-gvl-pagination" style="display:flex;gap:8px;align-items:center;justify-content:center;margin-top:16px;"></div>

			<div style="margin-top:16px;">
				<button class="faz-btn faz-btn-primary" id="faz-gvl-save" type="button"><?php esc_html_e( 'Save Selection', 'faz-cookie-manager' ); ?></button>
			</div>
		</div>
	</div>

</div>
