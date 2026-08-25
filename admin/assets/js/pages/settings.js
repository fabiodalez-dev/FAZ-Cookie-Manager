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
	// Same pattern, separate token, for the bootstrap readiness line. Three
	// writers share that one element: the page-load fetch, the toggle's change
	// listener (synchronous), and the post-save refetch. Without a token the
	// load-time request can resolve AFTER the admin has flipped the toggle and
	// overwrite "Save settings to check…" with the readiness of the setting
	// they just changed — a status line asserting the opposite of the truth,
	// on the control whose entire job is to say whether caching is really on.
	// The toggle listener bumps it too, so an in-flight answer to the old
	// question is discarded rather than raced.
	var geoBootstrapStatusId = 0;
	// True only once renderAbVariants()'s FAZ.get('banners') has resolved
	// successfully and the checkbox list (or the "need more banners" hint)
	// has been rendered into the DOM. False while that request is still in
	// flight or after it failed. saveSettings() must not trust the DOM-derived
	// serializeAbVariants() result while this is false — see saveSettings().
	var abVariantsReady = false;
	// How many ACTIVE banners the server last reported. null means "not asked yet",
	// which must not be treated as zero: the warning below would then fire on every
	// page load before the request resolves.
	var abActiveBannerCount = null;

	FAZ.ready(function () {
		form = document.getElementById('faz-settings');
		if (!form) return;
		loadSettings();
		loadGeoDbStatus();
		loadGeoBootstrapStatus();
		loadGvlStatus();
		document.getElementById('faz-settings-save').addEventListener('click', saveSettings);
		var geoBtn = document.getElementById('faz-geodb-update');
		if (geoBtn) geoBtn.addEventListener('click', updateGeoDb);
		var gvlBtn = document.getElementById('faz-gvl-update');
		if (gvlBtn) gvlBtn.addEventListener('click', updateGvl);
		var invalidateBtn = document.getElementById('faz-invalidate-consents');
		if (invalidateBtn) invalidateBtn.addEventListener('click', invalidateConsents);
		var bootstrapToggle = form.querySelector('input[data-path="geolocation.cache_geo_bootstrap"]');
		if (bootstrapToggle) {
			bootstrapToggle.addEventListener('change', function () {
				var status = document.getElementById('faz-geo-bootstrap-status');
				if (!status) return;
				// Invalidate any readiness request still in flight: it answers
				// the question the admin has just stopped asking.
				geoBootstrapStatusId++;
				status.textContent = __('settings.bootstrapSaveToCheck', 'Save settings to check whether the bootstrap can activate.');
				status.setAttribute('data-level', 'info');
			});
		}
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
			hydrateLegalLinks(data.legal_links);
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
			abActiveBannerCount = list.length;

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

	/**
	 * Tick the footer legal-link rows that are already stored and fill in their
	 * custom labels.
	 *
	 * The page rows are server-rendered by admin/views/settings.php, so unlike
	 * the A/B-test variant list there is no async readiness to guard against:
	 * by the time this runs the checkboxes are guaranteed to be in the DOM.
	 *
	 * @param {Object} group The legal_links settings group.
	 */
	function hydrateLegalLinks(group) {
		var container = document.getElementById('faz-legal-links-pages');
		if (!container) return;
		var stored = (group && Array.isArray(group.link_items)) ? group.link_items : [];
		var labels = {};
		stored.forEach(function (item) {
			if (!item) return;
			labels[String(item.page_id)] = typeof item.label === 'string' ? item.label : '';
		});
		container.querySelectorAll('input.faz-legal-link-page').forEach(function (cb) {
			var key = String(cb.value || '');
			var isStored = Object.prototype.hasOwnProperty.call(labels, key);
			cb.checked = isStored;
			var labelInput = container.querySelector('input.faz-legal-link-label[data-page-id="' + key + '"]');
			if (labelInput) labelInput.value = isStored ? labels[key] : '';
		});
	}

	/**
	 * Collect the checked footer legal links, in DOM order, as
	 * [{ page_id, label }]. DOM order is the rendered order, which is what the
	 * admin sees on screen.
	 *
	 * The PHP view guarantees a row for every stored selection, including a
	 * deleted/unpublished page or one beyond the published-page query cap. An
	 * unchecked row can therefore always mean an explicit removal.
	 */
	function serializeLegalLinks() {
		var items = [];
		var container = document.getElementById('faz-legal-links-pages');
		if (!container) return items;
		container.querySelectorAll('input.faz-legal-link-page:checked').forEach(function (cb) {
			var pageId = parseInt(cb.value, 10);
			if (!pageId) return;
			var labelInput = container.querySelector('input.faz-legal-link-label[data-page-id="' + cb.value + '"]');
			items.push({ page_id: pageId, label: ((labelInput && labelInput.value) || '').trim() });
		});
		return items;
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

	/**
	 * Show/hide elements based on data-show-if="path.to.checkbox".
	 *
	 * A group may additionally carry data-clear-when-hidden, which unticks the
	 * checkboxes inside it whenever it is hidden. That is ONLY correct where the
	 * server enforces the same dependency: Settings::sanitize() drops
	 * per_cookie_consent when per_service_consent is off, so leaving the hidden
	 * checkbox ticked would submit a value the server discards, and — worse —
	 * re-ticking the parent later would resurrect a stale "on" the admin never
	 * chose. It is deliberately opt-in rather than applied to every hidden
	 * group: clearing e.g. geolocation.target_regions whenever geo-targeting is
	 * toggled off would destroy a configuration the server happily keeps.
	 *
	 * @param {HTMLElement} [root] Scope to search; defaults to the settings form.
	 */
	function applyShowIf(root) {
		var scope = root || form;
		scope.querySelectorAll('[data-show-if]').forEach(function (el) {
			var path = el.getAttribute('data-show-if');
			// The controller is looked up on the whole FORM, never on `scope`.
			// `root` exists to narrow which groups get processed after a partial
			// re-render; it says nothing about where the checkbox that governs
			// them lives, and that checkbox is routinely outside the re-rendered
			// fragment. Scoping the lookup made `src` null, the guard below
			// returned, and the group kept whatever visibility the markup shipped
			// with — silently, in a mechanism whose only job is staying in step
			// with the server-side sanitiser.
			var selector = 'input[type="checkbox"][data-path="' + path + '"]';
			// Scope first, widen only if it is not there. Searching the whole
			// form unconditionally would take the FIRST match in the document,
			// which is the wrong element as soon as two fragments carry the same
			// data-path — and searching only the scope is the defect being fixed.
			// Narrow-then-widen is correct in both directions.
			var src = scope.querySelector(selector) || (form || document).querySelector(selector);
			if (!src) {
				// A data-show-if naming a path with no checkbox is a markup bug.
				// It used to fail silently, which is how the previous defect went
				// unnoticed; say so instead.
				if (window.console && console.warn) {
					console.warn('FAZ: data-show-if="' + path + '" has no matching checkbox; group left as rendered.');
				}
				return;
			}
			var clears = el.hasAttribute('data-clear-when-hidden');
			function toggle() {
				el.style.display = src.checked ? '' : 'none';
				if (clears && !src.checked) {
					el.querySelectorAll('input[type="checkbox"][data-path]').forEach(function (cb) {
						cb.checked = false;
					});
				}
			}
			toggle();
			// Bind once per group element. applyShowIf runs again on every
			// re-scope, and the listener was re-registered each time: harmless
			// for the visibility toggle, but on a data-clear-when-hidden group it
			// meant N redundant DOM sweeps per change event, growing with the
			// number of times the panel had been re-rendered. A re-rendered group
			// is a NEW element, so it carries no flag and binds correctly.
			if (!el.__fazShowIfBound) {
				el.__fazShowIfBound = true;
				src.addEventListener('change', toggle);
			}
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

			// Footer legal links: the page rows carry no data-path (so the generic
			// serializer skips them), but they ARE server-rendered, so no
			// readiness guard is needed the way ab_test.variants needs one — the
			// checkboxes exist as soon as the page does. The enabled flag comes
			// through data-path, so formData already carries it; because the merge
			// below is a per-group Object.assign, both keys must travel together
			// or a partial group would replace only one of them.
			formData.legal_links = formData.legal_links || {};
			var storedLegalLinks = (current.legal_links && Array.isArray(current.legal_links.link_items))
				? current.legal_links.link_items
				: [];
			if (document.getElementById('faz-legal-links-pages')) {
				formData.legal_links.link_items = serializeLegalLinks();
			} else {
				// The site has no published pages, so the view rendered no rows at
				// all — serializing would report "nothing selected" and wipe a list
				// the admin configured while pages still existed.
				formData.legal_links.link_items = storedLegalLinks;
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
			var saveWarnings = collectSaveWarnings(current);

			return FAZ.post('settings', current).then(function () {
				return saveWarnings;
			});
		}).then(function (saveWarnings) {
			FAZ.btnLoading(btn, false);
			FAZ.notify(__('settings.saved', 'Settings saved successfully.'));
			loadGeoBootstrapStatus();
			(saveWarnings || []).forEach(function (message) {
				FAZ.notify(message, 'warning');
			});
		}).catch(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify(__('settings.saveFailed', 'Failed to save settings.'), 'error');
		});
	}

	function loadGeoBootstrapStatus() {
		var el = document.getElementById('faz-geo-bootstrap-status');
		if (!el) return;
		var requestId = ++geoBootstrapStatusId;
		FAZ.get('settings/geo-bootstrap/status').then(function (data) {
			if (requestId !== geoBootstrapStatusId) return;
			el.textContent = data && data.message
				? data.message
				: __('settings.bootstrapStatusFailed', 'Bootstrap readiness could not be determined. Pages keep the safe no-cache fallback.');
			el.setAttribute('data-level', data && data.level ? data.level : 'warning');
		}).catch(function () {
			if (requestId !== geoBootstrapStatusId) return;
			el.textContent = __('settings.bootstrapStatusFailed', 'Bootstrap readiness could not be determined. Pages keep the safe no-cache fallback.');
			el.setAttribute('data-level', 'warning');
		});
	}

	/**
	 * Return every warning implied by the effective settings payload.
	 *
	 * Kept separate from saveSettings() so the compatibility matrix can test the
	 * exact decision logic without making a REST write. In particular, the IAB
	 * warning must use the same activation gate as the frontend: an enabled
	 * checkbox alone does not activate TCF without a registered CMP ID (>= 2).
	 *
	 * @param {Object} current Sanitized settings-shaped payload being saved.
	 * @return {string[]} Localized warning messages.
	 */
	function collectSaveWarnings(current) {
		var saveWarnings = [];
		var abTestWarnings = [];
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
			// Two independent reasons the test cannot run, so two independent
			// conditions. Chained with else-if, a site that had BOTH problems was
			// told about one, fixed it, saved, and was told about the other — a
			// warning that hides its sibling turns one round trip into two.
			// Two ways to have fewer than two runnable variants, and the stored
			// slugs only reveal one of them. When fewer than two banners are
			// active the picker renders nothing, abVariantsReady stays false and
			// saveSettings() deliberately preserves the previously stored slugs —
			// so the count can still read 2 while both slugs name banners that are
			// no longer active and Ab_Test::pick_variant() has nothing to choose
			// between. Ask the catalogue, not the leftovers.
			if (effectiveVariants.length < 2 || (abActiveBannerCount !== null && abActiveBannerCount < 2)) {
				abTestWarnings.push(__(
					'settings.abTestWarnVariants',
					'A/B testing needs at least 2 selected banner variants to run.'
				));
			}
			if (current.banner_control.cache_compatibility) {
				abTestWarnings.push(__(
					'settings.abTestWarnCache',
					'A/B testing is disabled while Cache Compatibility Mode is on.'
				));
			}
		}

		abTestWarnings.forEach(function (w) { saveWarnings.push(w); });

		// Cache Compatibility Mode may be stored alongside Geo-Targeting, but it
		// is inert while that UI toggle keeps the jurisdiction runtime enabled.
		// Say so rather than letting the success toast imply both run together.
		if (current.banner_control && current.banner_control.cache_compatibility) {
			var geoOn = !!(current.geolocation && current.geolocation.geo_targeting);
			if (geoOn) {
					saveWarnings.push(__(
						'settings.cacheCompatWarnGeo',
						'Jurisdiction enforcement keeps Cache Compatibility Mode itself inactive. Enable the cache-safe jurisdiction bootstrap to cache compatible pages without weakening enforcement.'
					));
			}
			var cmpId = current.iab ? parseInt(current.iab.cmp_id, 10) : 0;
			if (!geoOn && current.iab && current.iab.enabled && !isNaN(cmpId) && cmpId >= 2) {
				saveWarnings.push(__(
					'settings.cacheCompatWarnIab',
					'Cache Compatibility Mode applies the conservative IAB TCF default (GDPR applies) to every visitor instead of deciding by country.'
				));
			}
		}

		return saveWarnings;
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
