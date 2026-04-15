/**
 * FAZ Cookie Manager - Settings Page JS
 */
(function () {
	'use strict';

	// i18n helper — looks up fazConfig.i18n.<key> with dot-notation, falls back to provided string.
	function __(key, fallback) {
		var parts = key.split('.');
		var obj = (window.fazConfig && window.fazConfig.i18n) || {};
		for (var i = 0; i < parts.length; i++) {
			if (!obj || typeof obj !== 'object') { return fallback; }
			obj = obj[parts[i]];
		}
		return typeof obj === 'string' ? obj : fallback;
	}

	var form;
	// Monotonic counter used to ignore stale loadSettings() responses that
	// resolve AFTER a newer action (e.g. invalidateConsents) has already
	// mutated the form. Each loadSettings() captures the current token and
	// only applies its payload if the token still matches at resolution time.
	var settingsRequestId = 0;

	FAZ.ready(function () {
		form = document.getElementById('faz-settings');
		if (!form) return;
		loadSettings();
		loadGeoDbStatus();
		loadGvlStatus();
		document.getElementById('faz-settings-save').addEventListener('click', saveSettings);
		var geoBtn = document.getElementById('faz-geodb-update');
		if (geoBtn) geoBtn.addEventListener('click', updateGeoDb);
		var gvlBtn = document.getElementById('faz-gvl-update');
		if (gvlBtn) gvlBtn.addEventListener('click', updateGvl);
		var invalidateBtn = document.getElementById('faz-invalidate-consents');
		if (invalidateBtn) invalidateBtn.addEventListener('click', invalidateConsents);
	});

	/**
	 * Bump the server-side consent revision. Returning visitors with a stored
	 * cookie carrying a lower revision will be shown the banner again on
	 * their next visit. This is a one-way action from the visitor's point of
	 * view: once bumped, the only way to "restore" a visitor's prior consent
	 * is for them to re-consent (or for the admin to manually lower the
	 * revision via the REST API — not exposed in the UI on purpose).
	 */
	function invalidateConsents() {
		var btn = document.getElementById('faz-invalidate-consents');
		var message = __(
			'settings.invalidateConfirm',
			'Show the cookie banner to ALL returning visitors on their next visit? This cannot be undone from the UI.'
		);
		if (!window.confirm(message)) return;

		FAZ.btnLoading(btn, true);
		FAZ.post('settings/invalidate-consents', {}).then(function (resp) {
			FAZ.btnLoading(btn, false);
			var rev = resp && typeof resp.consent_revision !== 'undefined' ? resp.consent_revision : null;
			var input = form.querySelector('input[data-path="general.consent_revision"]');
			if (input && rev !== null) input.value = rev;
			// Invalidate any in-flight loadSettings() so its stale payload
			// cannot overwrite the revision we just bumped.
			settingsRequestId++;
			FAZ.notify(__('settings.invalidateOk', 'All consents invalidated. Banner will reappear for returning visitors.'));
		}).catch(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify(__('settings.invalidateFail', 'Failed to invalidate consents.'), 'error');
		});
	}

	function loadSettings() {
		var requestId = ++settingsRequestId;
		FAZ.get('settings').then(function (data) {
			if (requestId !== settingsRequestId) return;
			// Excluded pages comes as array, convert to newline-separated text
			if (data.banner_control && Array.isArray(data.banner_control.excluded_pages)) {
				data.banner_control.excluded_pages = data.banner_control.excluded_pages.join('\n');
			}
			if (data.script_blocking && Array.isArray(data.script_blocking.excluded_pages)) {
				data.script_blocking.excluded_pages = data.script_blocking.excluded_pages.join('\n');
			}
			if (data.script_blocking && Array.isArray(data.script_blocking.whitelist_patterns)) {
				data.script_blocking.whitelist_patterns = data.script_blocking.whitelist_patterns.join('\n');
			}
			// Target domains comes as array, convert to newline-separated text
			if (data.consent_forwarding && Array.isArray(data.consent_forwarding.target_domains)) {
				data.consent_forwarding.target_domains = data.consent_forwarding.target_domains.join('\n');
			}
			// PMP exempt levels: array of IDs -> comma-separated string for the input field.
			if (data.integrations && data.integrations.paid_memberships_pro
				&& Array.isArray(data.integrations.paid_memberships_pro.exempt_levels)) {
				data.integrations.paid_memberships_pro.exempt_levels =
					data.integrations.paid_memberships_pro.exempt_levels.join(', ');
			}
			FAZ.populateForm(form, data);
			populateTargetRegions(data);
			applyShowIf();
		}).catch(function () {
			FAZ.notify(__('settings.loadFailed', 'Failed to load settings.'), 'error');
		});
	}

	/** Populate target region checkboxes from the stored array */
	function populateTargetRegions(data) {
		var regions = (data.geolocation && Array.isArray(data.geolocation.target_regions))
			? data.geolocation.target_regions
			: [];
		form.querySelectorAll('input[type="checkbox"][data-path="geolocation.target_regions"]').forEach(function (cb) {
			cb.checked = regions.indexOf(cb.value) !== -1;
		});
	}

	/** Collect checked target region values into an array */
	function serializeTargetRegions() {
		var regions = [];
		form.querySelectorAll('input[type="checkbox"][data-path="geolocation.target_regions"]').forEach(function (cb) {
			if (cb.checked) regions.push(cb.value);
		});
		return regions;
	}

	/** Show/hide elements based on data-show-if="path.to.checkbox" */
	function applyShowIf() {
		form.querySelectorAll('[data-show-if]').forEach(function (el) {
			var path = el.getAttribute('data-show-if');
			var src = form.querySelector('input[type="checkbox"][data-path="' + path + '"]');
			if (!src) return;
			function toggle() { el.style.display = src.checked ? '' : 'none'; }
			toggle();
			src.addEventListener('change', toggle);
		});
	}

	function saveSettings() {
		var btn = document.getElementById('faz-settings-save');
		FAZ.btnLoading(btn, true);

		// Load full settings first, then merge form changes on top
		FAZ.get('settings').then(function (current) {
			var formData = FAZ.serializeForm(form);

			// Convert excluded pages back to array
			if (formData.banner_control && typeof formData.banner_control.excluded_pages === 'string') {
				formData.banner_control.excluded_pages = formData.banner_control.excluded_pages
					.split('\n')
					.map(function (s) { return s.trim(); })
					.filter(Boolean);
			}
			if (formData.script_blocking && typeof formData.script_blocking.excluded_pages === 'string') {
				formData.script_blocking.excluded_pages = formData.script_blocking.excluded_pages
					.split('\n')
					.map(function (s) { return s.trim(); })
					.filter(Boolean);
			}
			if (formData.script_blocking && typeof formData.script_blocking.whitelist_patterns === 'string') {
				formData.script_blocking.whitelist_patterns = formData.script_blocking.whitelist_patterns
					.split('\n')
					.map(function (s) { return s.trim(); })
					.filter(Boolean);
			}
			// Convert target domains back to array
			if (formData.consent_forwarding && typeof formData.consent_forwarding.target_domains === 'string') {
				formData.consent_forwarding.target_domains = formData.consent_forwarding.target_domains
					.split('\n')
					.map(function (s) { return s.trim(); })
					.filter(Boolean);
			}

			// Target regions: replace boolean from generic serializer with proper array
			if (!formData.geolocation) formData.geolocation = {};
			formData.geolocation.target_regions = serializeTargetRegions();

			// Deep merge form data into current settings
			Object.keys(formData).forEach(function (key) {
				if (typeof formData[key] === 'object' && formData[key] !== null && !Array.isArray(formData[key])) {
					current[key] = Object.assign({}, current[key] || {}, formData[key]);
				} else {
					current[key] = formData[key];
				}
			});

			return FAZ.post('settings', current);
		}).then(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify(__('settings.saved', 'Settings saved successfully.'));
		}).catch(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify(__('settings.saveFailed', 'Failed to save settings.'), 'error');
		});
	}

	function loadGeoDbStatus() {
		FAZ.get('settings/geolite2/status').then(function (data) {
			var el = document.getElementById('faz-geodb-status');
			if (!el) return;
			el.textContent = '';
			if (data.installed && data.database) {
				var rawSize = parseInt(data.database.size, 10);
			var sizeKB = isFinite(rawSize) ? Math.round(rawSize / 1024) : 0;
				var b = document.createElement('strong');
				b.textContent = __('settings.dbLabel', 'Database: ');
				el.appendChild(b);
				el.appendChild(document.createTextNode(
					__('settings.dbFileInfo', '{file} ({size} KB) - Last updated: {date}')
						.replace('{file}', data.database.file)
						.replace('{size}', sizeKB)
						.replace('{date}', data.database.modified)
				));
			} else {
				el.textContent = __('settings.noGeoipDb', 'No GeoIP database installed. Enter your license key and click "Update Database".');
			}
			el.style.display = 'block';
		}).catch(function (err) {
			console.warn('Failed to load GeoIP status', err);
		});
	}

	function loadGvlStatus() {
		FAZ.get('gvl').then(function (data) {
			var el = document.getElementById('faz-gvl-status');
			if (!el) return;
			el.textContent = '';
			if (data.version && data.version > 0) {
				var b1 = document.createElement('strong');
				b1.textContent = __('settings.gvlVersion', 'GVL Version: ');
				el.appendChild(b1);
				el.appendChild(document.createTextNode(data.version + ' | '));
				var b2 = document.createElement('strong');
				b2.textContent = __('settings.gvlVendors', 'Vendors: ');
				el.appendChild(b2);
				el.appendChild(document.createTextNode((data.vendor_count || 0) + ' | '));
				var b3 = document.createElement('strong');
				b3.textContent = __('settings.gvlLastUpdated', 'Last Updated: ');
				el.appendChild(b3);
				el.appendChild(document.createTextNode(data.last_updated || 'N/A'));
			} else {
				el.textContent = __('settings.noGvlData', 'No GVL data downloaded yet. Click "Update GVL Now" to download.');
			}
		}).catch(function () {
			var el = document.getElementById('faz-gvl-status');
			if (el) el.textContent = __('settings.noGvlAvailable', 'No GVL data available.');
		});
	}

	function updateGvl(event) {
		if (event) event.preventDefault();
		var btn = document.getElementById('faz-gvl-update');
		FAZ.btnLoading(btn, true);
		FAZ.post('gvl/update').then(function (data) {
			FAZ.btnLoading(btn, false);
			if (data.success) {
				var gvlMsg = __('settings.gvlUpdatedWithMeta', 'GVL updated: v{version} ({count} vendors)')
					.replace('{version}', String(data.version))
					.replace('{count}', String(data.vendor_count));
				FAZ.notify(gvlMsg);
				loadGvlStatus();
			} else {
				FAZ.notify(data.message || __('settings.gvlFailed', 'Failed to update GVL.'), 'error');
			}
		}).catch(function (err) {
			FAZ.btnLoading(btn, false);
			FAZ.notify((err && err.message) || __('settings.gvlFailed', 'Failed to update GVL.'), 'error');
		});
	}

	function updateGeoDb(event) {
		if (event) event.preventDefault();
		var btn = document.getElementById('faz-geodb-update');
		var keyInput = form.querySelector('[data-path="geolocation.maxmind_license_key"]');
		var licenseKey = keyInput ? keyInput.value.trim() : '';

		if (!licenseKey) {
			FAZ.notify(__('settings.geoipNoKey', 'Please enter a MaxMind license key first.'), 'error');
			return;
		}

		FAZ.btnLoading(btn, true);
		FAZ.post('settings/geolite2/update', { license_key: licenseKey }).then(function (data) {
			FAZ.btnLoading(btn, false);
			if (data.success) {
				FAZ.notify(__('settings.geoipUpdated', 'GeoIP database updated successfully.'));
				loadGeoDbStatus();
			}
			else {
				FAZ.notify(data.message || __('settings.geoipFailed', 'Failed to update database.'), 'error');
			}
		}).catch(function (err) {
			FAZ.btnLoading(btn, false);
			var msg = (err && err.message) ? err.message : __('settings.geoipFailed', 'Failed to update database.');
			FAZ.notify(msg, 'error');
		});
	}

})();
