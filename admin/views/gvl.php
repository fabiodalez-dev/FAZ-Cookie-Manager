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
			<h3>Global Vendor List (IAB TCF 2.3)</h3>
			<button class="faz-btn faz-btn-secondary faz-btn-sm" id="faz-gvl-download" type="button">Update GVL Now</button>
		</div>
		<div class="faz-card-body">
			<div id="faz-gvl-meta" style="padding:10px;border-radius:6px;background:var(--faz-bg-secondary);margin-bottom:16px;">
				<span style="color:var(--faz-text-secondary);">Loading GVL status...</span>
			</div>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header">
			<h3>Vendor Selection</h3>
		</div>
		<div class="faz-card-body">
			<div style="display:flex;gap:12px;margin-bottom:16px;align-items:center;">
				<input type="text" id="faz-gvl-search" class="faz-input" placeholder="Search vendors..." aria-label="Search vendors" style="flex:1;max-width:300px;">
				<select id="faz-gvl-purpose-filter" class="faz-input" aria-label="Filter by purpose" style="width:auto;">
					<option value="0">All purposes</option>
				</select>
				<span id="faz-gvl-selected-count" aria-live="polite" style="color:var(--faz-text-secondary);white-space:nowrap;"></span>
			</div>

			<div style="margin-bottom:8px;">
				<label for="faz-gvl-select-all" style="cursor:pointer;font-weight:600;">
					<input type="checkbox" id="faz-gvl-select-all"> Select all on this page
				</label>
			</div>

			<div id="faz-gvl-vendor-list"></div>

			<div id="faz-gvl-pagination" style="display:flex;gap:8px;align-items:center;justify-content:center;margin-top:16px;"></div>

			<div style="margin-top:16px;">
				<button class="faz-btn faz-btn-primary" id="faz-gvl-save" type="button">Save Selection</button>
			</div>
		</div>
	</div>

</div>
