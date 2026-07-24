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
	// True only once renderAbVariants()'s FAZ.get('banners') has resolved
	// successfully and the checkbox list (or the "need more banners" hint)
	// has been rendered into the DOM. False while that request is still in
	// flight or after it failed. saveSettings() must not trust the DOM-derived
	// serializeAbVariants() result while this is false — see saveSettings().
	var abVariantsReady = false;

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
			renderAbVariants(data);
			applyShowIf();
		}).catch(function () {
			FAZ.notify(__('settings.loadFailed', 'Failed to load settings.'), 'error');
		});
	}

	/**
	 * Build the A/B-test variant checkbox list from the site's active banners,
	 * pre-checking the ones already stored in banner_control.ab_test.variants.
	 * Inactive banners are excluded (they cannot serve as variants) and, when
	 * there are fewer than two active banners, a hint replaces the list.
	 *
	 * @param {Object} data Full settings payload (for the stored variant list).
	 */
	function renderAbVariants(data) {
		var container = document.getElementById('faz-abtest-variants');
		if (!container) return;

		var stored = (data && data.banner_control && data.banner_control.ab_test
			&& Array.isArray(data.banner_control.ab_test.variants))
			? data.banner_control.ab_test.variants
			: [];

		// The checkbox list doesn't exist in the DOM yet — until FAZ.get('banners')
		// below resolves (or fails), serializeAbVariants() cannot be trusted.
		abVariantsReady = false;

		FAZ.get('banners').then(function (banners) {
			var list = Array.isArray(banners) ? banners.filter(function (b) { return b && b.status; }) : [];

			while (container.firstChild) { container.removeChild(container.firstChild); }

			if (list.length < 2) {
				var hint = document.createElement('p');
				hint.style.color = 'var(--faz-text-muted)';
				hint.textContent = __(
					'settings.abTestNeedBanners',
					'Create at least two active banners on the Banner page to run an A/B test.'
				);
				container.appendChild(hint);
				// Fewer than two active banners renders no checkboxes, so
				// serializeAbVariants() would return []. Keep abVariantsReady
				// false (as the .catch() branch does) so saveSettings() preserves
				// the server-stored variants instead of wiping them.
				return;
			}

			list.forEach(function (banner) {
				var label = document.createElement('label');
				label.className = 'faz-checkbox';
				label.style.display = 'block';
				label.style.marginBottom = '6px';

				var cb = document.createElement('input');
				cb.type = 'checkbox';
				cb.className = 'faz-abtest-variant';
				cb.value = String(banner.slug || '');
				cb.checked = stored.indexOf(String(banner.slug || '')) !== -1;

				var text = document.createElement('span');
				text.style.marginLeft = '6px';
				text.textContent = String(banner.name || banner.slug || '');

				label.appendChild(cb);
				label.appendChild(text);
				container.appendChild(label);
			});

			abVariantsReady = true;
		}).catch(function () {
			while (container.firstChild) { container.removeChild(container.firstChild); }
			var err = document.createElement('p');
			err.style.color = 'var(--faz-text-muted)';
			err.textContent = __('settings.abTestLoadFailed', 'Could not load banners for the A/B test.');
			container.appendChild(err);
			// Load failed — the checkbox list is empty/stale. Keep saveSettings()
			// from treating serializeAbVariants() as authoritative.
			abVariantsReady = false;
		});
	}

	/** Collect the checked A/B-test variant slugs into an array. */
	function serializeAbVariants() {
		var slugs = [];
		var container = document.getElementById('faz-abtest-variants');
		if (!container) return slugs;
		container.querySelectorAll('input.faz-abtest-variant:checked').forEach(function (cb) {
			var v = String(cb.value || '').trim();
			if (v && slugs.indexOf(v) === -1) slugs.push(v);
		});
		return slugs;
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

			// A/B test variants: the checkbox list is built dynamically from the
			// banner rows (no data-path), so the generic serializer skips it —
			// collect the checked slugs here into banner_control.ab_test.variants.
			// The ab_test.status flag IS a data-path checkbox, so formData already
			// carries it; we only add the variant array alongside it.
			if (!formData.banner_control) formData.banner_control = {};
			if (!formData.banner_control.ab_test || typeof formData.banner_control.ab_test !== 'object') {
				formData.banner_control.ab_test = {};
			}
			if (abVariantsReady) {
				formData.banner_control.ab_test.variants = serializeAbVariants();
			} else {
				// The variant checkboxes never finished loading (renderAbVariants()'s
				// FAZ.get('banners') is still in flight, or it failed) — the DOM has
				// no checkboxes yet, so serializeAbVariants() would return [] and
				// silently wipe out the admin's previously configured variants.
				// Preserve whatever is already stored server-side instead.
				formData.banner_control.ab_test.variants = (current.banner_control && current.banner_control.ab_test
					&& Array.isArray(current.banner_control.ab_test.variants))
					? current.banner_control.ab_test.variants
					: [];
			}

			// Deep merge form data into current settings
			Object.keys(formData).forEach(function (key) {
				if (typeof formData[key] === 'object' && formData[key] !== null && !Array.isArray(formData[key])) {
					current[key] = Object.assign({}, current[key] || {}, formData[key]);
				} else {
					current[key] = formData[key];
				}
			});

			// A/B testing silently no-ops server-side in two configurations:
			// fewer than 2 selected variants (Ab_Test::pick_variant needs >=2
			// valid slugs to pick from) or Cache Compatibility Mode enabled
			// (maybe_apply_ab_test() short-circuits entirely under cache-compat).
			// Warn the admin instead of letting the generic success toast imply
			// the A/B test is actually running.
			var abTestWarning = null;
			if (current.banner_control && current.banner_control.ab_test
				&& current.banner_control.ab_test.status) {
				// current.banner_control.ab_test.variants was just overwritten by the
				// merge above with formData.banner_control.ab_test.variants (either the
				// freshly serialized checkboxes, or the preserved server-side value when
				// the checkbox list hadn't finished loading) — use it instead of a fresh
				// serializeAbVariants() call, which would be wrong while !abVariantsReady.
				var effectiveVariants = Array.isArray(current.banner_control.ab_test.variants)
					? current.banner_control.ab_test.variants
					: [];
				if (effectiveVariants.length < 2) {
					abTestWarning = __(
						'settings.abTestWarnVariants',
						'A/B testing needs at least 2 selected banner variants to run.'
					);
				} else if (current.banner_control.cache_compatibility) {
					abTestWarning = __(
						'settings.abTestWarnCache',
						'A/B testing is disabled while Cache Compatibility Mode is on.'
					);
				}
			}

			return FAZ.post('settings', current).then(function () {
				return abTestWarning;
			});
		}).then(function (abTestWarning) {
			FAZ.btnLoading(btn, false);
			FAZ.notify(__('settings.saved', 'Settings saved successfully.'));
			if (abTestWarning) {
				FAZ.notify(abTestWarning, 'warning');
			}
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
		var edInput = form.querySelector('[data-path="geolocation.geolite2_edition"]');
		var edition = edInput && (edInput.value === 'city' || edInput.value === 'country') ? edInput.value : '';

		if (!licenseKey) {
			FAZ.notify(__('settings.geoipNoKey', 'Please enter a MaxMind license key first.'), 'error');
			return;
		}

		FAZ.btnLoading(btn, true);
		FAZ.post('settings/geolite2/update', { license_key: licenseKey, edition: edition }).then(function (data) {
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
