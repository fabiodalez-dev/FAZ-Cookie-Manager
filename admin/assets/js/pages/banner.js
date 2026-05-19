/**
 * FAZ Cookie Manager - Cookie Banner Settings Page JS
 * Loads and saves deeply nested banner settings + per-language contents.
 * Fixed-position live preview + WordPress media uploader for brand logo.
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

	// Banner the page is currently editing. Read from the ?banner_id= query
	// string so the multi-banner switcher (1.14.0+) can deep-link to a
	// specific row. Falls back to 1 (the system-default banner shipped
	// with every install) when the param is missing or malformed.
	var bannerId = (function () {
		try {
			var match = (window.location.search || '').match(/[?&]banner_id=(\d+)/);
			var parsed = match ? parseInt(match[1], 10) : NaN;
			return isFinite(parsed) && parsed > 0 ? parsed : 1;
		} catch (e) { return 1; }
	})();
	var bannerData = null; // full API response
	var currentLang = 'en';
	var previewVisible = true;
	var previewRequestId = 0; // race-condition guard for preview requests
	var previewFrameReady = false;
	var previewFrameHandlersBound = false;
	var pendingPreviewState = null;
	var previewLayoutTimers = [];
	var DEFAULT_PREVIEW_FRAME_HEIGHT = 280;
	var MIN_PREVIEW_FRAME_HEIGHT = 72;
	var MAX_PREVIEW_FRAME_HEIGHT = 640;

	FAZ.ready(function () {
		// Serialize visible TinyMCE content into bannerData BEFORE FAZ.tabs
		// hides the outgoing panel. Registered first so it fires first.
		document.querySelectorAll('#faz-banner .faz-tab').forEach(function (btn) {
			btn.addEventListener('click', function () {
				storeCurrentLangContents();
			});
		});

		FAZ.tabs('#faz-banner');

		// TinyMCE editors initialized in hidden containers may not visually
		// render their content (iframe had 0 dimensions when setContent ran).
		// When their tab becomes visible, force a re-render:
		//  - content in data model but not painted → re-set to force paint
		//  - content lost entirely (iframe content gone) → restore from bannerData
		var tabEditors = {
			content: ['faz-b-notice-desc'],
			preferences: ['faz-b-pref-desc']
		};
		document.querySelectorAll('#faz-banner .faz-tab').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var ids = tabEditors[btn.dataset.tab];
				if (!ids) return;
				if (typeof tinyMCE === 'undefined' || !bannerData) return;
				var contents = bannerData.contents || {};
				var c = contents[currentLang] || contents[Object.keys(contents)[0]] || {};
				var notice = (c.notice && c.notice.elements) || {};
				var pref = (c.preferenceCenter && c.preferenceCenter.elements) || {};
				var stored = {
					'faz-b-notice-desc': notice.description || '',
					'faz-b-pref-desc': pref.description || ''
				};
				ids.forEach(function (id) {
					var editor = tinyMCE.get(id);
					if (!editor) return;
					var current = editor.getContent();
					// Re-set to force the iframe to paint the content now that
					// the panel is visible. Falls back to bannerData if lost.
					editor.setContent(current || stored[id] || '');
				});
			});
		});

		loadBanner();
		loadDesignPresets();
		loadBannerEnabledToggle();
		syncPreviewSpacer(DEFAULT_PREVIEW_FRAME_HEIGHT);

		document.getElementById('faz-b-save').addEventListener('click', saveBanner);
		document.getElementById('faz-b-refresh-preview').addEventListener('click', function () {
			syncFormToBannerData();
			ensurePreviewFrame(true);
			refreshPreview();
		});

		// Preview toggle
		var toggleBtn = document.getElementById('faz-b-toggle-preview');
		if (toggleBtn) {
			toggleBtn.addEventListener('click', function () {
				previewVisible = !previewVisible;
				toggleBtn.textContent = previewVisible ? 'Hide Preview' : 'Show Preview';
				var panel = document.getElementById('faz-b-preview-panel');
				if (panel) {
					panel.classList.toggle('hidden', !previewVisible);
				}
				if (previewVisible) {
					ensurePreviewFrame(false);
					syncFormToBannerData();
					refreshPreview();
					schedulePreviewFrameLayoutSync([0, 120]);
				}
				syncPreviewSpacer();
			});
		}

		window.addEventListener('resize', function () {
			schedulePreviewFrameLayoutSync([0, 120]);
		});

		// Language selectors (content + preferences tabs share same banner)
		['faz-b-content-lang', 'faz-b-pref-lang'].forEach(function (id) {
			var el = document.getElementById(id);
			if (el) {
				el.addEventListener('change', function () {
					storeCurrentLangContents();
					currentLang = el.value;
					syncLangSelects(currentLang);
					populateContents(currentLang);
				});
			}
		});

		// Auto-refresh preview on any form change (debounced)
		var previewTimer = null;
		var bannerEl = document.getElementById('faz-banner');
		if (bannerEl) {
			bannerEl.addEventListener('change', function () {
				clearTimeout(previewTimer);
				previewTimer = setTimeout(function () {
					syncFormToBannerData();
					refreshPreview();
				}, 600);
			});
			bannerEl.addEventListener('input', function (e) {
				// Only auto-refresh for color inputs (instant feedback)
				if (e.target && e.target.type === 'color') {
					clearTimeout(previewTimer);
					previewTimer = setTimeout(function () {
						syncFormToBannerData();
						refreshPreview();
					}, 300);
				}
			});
		}

		// Toggle "Do Not Sell" colour row when law changes
		var lawEl = document.getElementById('faz-b-law');
		if (lawEl) {
			lawEl.addEventListener('change', function () {
				toggleDoNotSellColorRow(lawEl.value);
			});
		}

		// ── Brand Logo Media Uploader ──
		initBrandLogoUploader();

		// ── Theme switch: reset colours to new preset ──
		var themeEl = document.getElementById('faz-b-theme');
		if (themeEl) {
			themeEl.addEventListener('change', function () {
				applyThemePreset(themeEl.value);
			});
		}

		// ── Hide irrelevant position options based on banner type ──
		var typeEl = document.getElementById('faz-b-type');
		if (typeEl) {
			typeEl.addEventListener('change', updatePositionOptions);
		}
	});

	function updatePositionOptions() {
		var type = getVal('faz-b-type') || 'box';
		var posEl = document.getElementById('faz-b-position');
		if (!posEl) return;
		var opts = posEl.options;
		for (var i = 0; i < opts.length; i++) {
			var v = opts[i].value;
			if (type === 'box') {
				// Box: only bottom-left / bottom-right make sense
				opts[i].hidden = (v === 'top' || v === 'bottom');
			} else {
				// Banner/Classic: only top / bottom make sense
				opts[i].hidden = (v === 'bottom-left' || v === 'bottom-right');
			}
		}
		// If current selection is now hidden, switch to a visible default
		if (posEl.options[posEl.selectedIndex] && posEl.options[posEl.selectedIndex].hidden) {
			posEl.value = (type === 'box') ? 'bottom-right' : 'bottom';
		}

		// Show category preview colours only for classic type
		var catPrevCard = document.getElementById('faz-catprev-colors-card');
		if (catPrevCard) {
			catPrevCard.style.display = (type === 'classic') ? '' : 'none';
		}

		// Classic forces pushdown preference center; other types allow free choice.
		var prefEl = document.getElementById('faz-b-pref-type');
		if (prefEl) {
			if (type === 'classic') {
				prefEl.value = 'pushdown';
				prefEl.disabled = true;
			} else {
				prefEl.disabled = false;
				if (type === 'box' && prefEl.value === 'pushdown') {
					prefEl.value = 'popup';
				}
			}
		}
	}

		function loadBanner() {
			FAZ.get('banners/' + bannerId).then(function (data) {
				bannerData = data;
				normalizeBannerConfig(bannerData.properties);
				populateSettings();
				populateContents(currentLang);
				populateGeoTargeting();
			// Init color pickers after populating values
			FAZ.initColorPickers();
			// Filter position options for current type
			updatePositionOptions();
			ensurePreviewFrame(false);
			// Render live preview
			refreshPreview();
			// Multi-banner switcher (1.14.0+) — list every banner row so
			// the admin can jump between them via ?banner_id=N.
			populateSwitcher();
		}).catch(function (err) {
			// Distinguish "the requested ?banner_id= row doesn't exist" (the
			// /banners/{id} endpoint returns 404 with code=fazcookie_rest_invalid_id)
			// from generic load failures (5xx, network). The former is the
			// common case after a banner deletion or an old bookmark from
			// before the 1.14.1 auto-increment fix — surface it in-page with
			// a recoverable CTA instead of a transient toast.
			var isMissing = !!err && (
				err.code === 'fazcookie_rest_invalid_id'
				|| err.code === 'rest_no_route'
				|| (err.data && err.data.status === 404)
			);
			if ( isMissing ) {
				showMissingBannerNotice(bannerId);
				return;
			}
			FAZ.notify(__('banner.loadFailed', 'Failed to load banner settings.'), 'error');
		});
	}

	// Render the "this banner does not exist" notice and hide the editor.
	// Looks up the actual default banner so the recovery link can deep-link
	// to a row that exists, instead of guessing id=1.
	function showMissingBannerNotice(badId) {
		var notice  = document.getElementById('faz-banner-missing');
		var body    = document.getElementById('faz-banner-body');
		var idEl    = document.getElementById('faz-banner-missing-id');
		var tabs    = document.getElementById('faz-banner-tabs');
		var switcher = document.getElementById('faz-b-switcher');
		var cta     = document.getElementById('faz-banner-missing-default');
		if (!notice) return;

		notice.style.display = '';
		if (body)    body.style.display = 'none';
		if (tabs)    tabs.style.display = 'none';
		if (switcher) switcher.style.display = 'none';
		if (idEl) idEl.textContent = '#' + String(badId);

		// Build the recovery link. Default target: the existing banner_default=1
		// row. Falls back to the first available banner. Final fallback: the
		// page without banner_id so the server-side default kicks in.
		var base = window.location.href.split('?')[0];
		var page = (window.location.search.match(/page=([^&]+)/) || [null, 'faz-cookie-manager-banner'])[1];
		var fallbackUrl = base + '?page=' + encodeURIComponent(page);
		if (cta) cta.href = fallbackUrl;
		FAZ.get('banners').then(function (rows) {
			if (!Array.isArray(rows) || !rows.length) return;
			var pick = rows.filter(function (b) { return Number(b['default']) === 1; })[0] || rows[0];
			if (pick && cta) cta.href = fallbackUrl + '&banner_id=' + Number(pick.id);
		}).catch(function () { /* keep the bare-page fallback */ });
	}

	// ── Multi-banner switcher (1.14.0+) ─────────────────────────────────
	// Populates #faz-b-switcher with every banner row, lets the admin
	// jump between banners via ?banner_id=N, create a new banner cloned
	// from the current one, and delete the current banner (except the
	// last remaining row).
	function populateSwitcher() {
		var wrap    = document.getElementById('faz-b-switcher');
		var chips   = document.getElementById('faz-b-switcher-chips');
		var newBtn  = document.getElementById('faz-b-switcher-new');
		var delBtn  = document.getElementById('faz-b-switcher-delete');
		if (!wrap || !chips || !newBtn) return;

		// Build a single chip element. Stored in a local helper so the
		// render call site stays narrative — we lay out the chip once,
		// then iterate once and append.
		function renderChip(b) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'faz-switcher-chip';
			btn.dataset.bannerId = String(b.id);
			var isActive = Number(b.id) === Number(bannerId);
			btn.style.padding = '.25rem .65rem';
			btn.style.fontSize = '13px';
			btn.style.lineHeight = '1.4';
			btn.style.borderRadius = '999px';
			btn.style.border = '1px solid ' + (isActive ? '#1f2937' : '#d1d5db');
			btn.style.background = isActive ? '#1f2937' : '#fff';
			btn.style.color = isActive ? '#fff' : '#374151';
			btn.style.cursor = isActive ? 'default' : 'pointer';
			btn.style.fontWeight = isActive ? '600' : '400';
			btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
			var label = b.name || ('Banner #' + b.id);
			if (Number(b['default']) === 1) label = '★ ' + label;
			if (Number(b.status) !== 1) label += ' (' + __('banner.inactive', 'inactive') + ')';
			btn.textContent = label;
			if (!isActive) {
				btn.addEventListener('click', function () {
					var base = window.location.href.split('?')[0];
					var page = (window.location.search.match(/page=([^&]+)/) || [null, 'faz-cookie-manager-banner'])[1];
					window.location.href = base + '?page=' + encodeURIComponent(page) + '&banner_id=' + Number(b.id);
				});
			}
			return btn;
		}

		FAZ.get('banners').then(function (data) {
			var rows = Array.isArray(data) ? data : [];
			while (chips.firstChild) { chips.removeChild(chips.firstChild); }
			rows.forEach(function (b) { chips.appendChild(renderChip(b)); });
			if (delBtn) {
				var current = rows.filter(function (b) { return Number(b.id) === Number(bannerId); })[0];
				var canDelete = rows.length > 1 && current && Number(current['default']) !== 1;
				delBtn.style.display = canDelete ? '' : 'none';
			}
		}).catch(function () { /* network glitch — switcher just doesn't appear */ });

		// In-page rename: the input now lives in the General tab as
		// #faz-b-name (1.14.1+). Bind once per page load. On commit we PUT
		// the new name, then re-render the chip row so the visible label
		// updates without a page reload.
		var nameIn = document.getElementById('faz-b-name');
		if (nameIn && !nameIn.dataset.fazNameBound) {
			var commitName = function () {
				if (!bannerData) return;
				var next = (nameIn.value || '').trim();
				if (!next) { nameIn.value = bannerData.name || ''; return; }
				if (next === bannerData.name) return;
				FAZ.put('banners/' + bannerId, {
					name: next,
					status: bannerData.status,
					'default': bannerData['default'],
					properties: bannerData.properties,
					contents: bannerData.contents
				}).then(function () {
					bannerData.name = next;
					FAZ.notify(__('banner.renamed', 'Banner renamed.'));
					// Re-render the chip row so the new name is reflected.
					populateSwitcher();
				}).catch(function () {
					FAZ.notify(__('banner.renameFailed', 'Failed to save the new name.'), 'error');
					nameIn.value = bannerData.name || '';
				});
			};
			nameIn.addEventListener('blur', commitName);
			nameIn.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') { e.preventDefault(); nameIn.blur(); }
				if (e.key === 'Escape') { nameIn.value = bannerData ? (bannerData.name || '') : ''; nameIn.blur(); }
			});
			nameIn.dataset.fazNameBound = '1';
		}
		// Seed the in-tab rename input from the loaded bannerData.
		if (nameIn && bannerData && typeof bannerData.name === 'string') {
			nameIn.value = bannerData.name;
		}
		if (!newBtn.dataset.fazSwitcherBound) {
			newBtn.addEventListener('click', openNewBannerModal);
			newBtn.dataset.fazSwitcherBound = '1';
		}
		if (delBtn && !delBtn.dataset.fazSwitcherBound) {
			delBtn.addEventListener('click', function () {
				if (!window.confirm(__('banner.deleteConfirm', 'Delete this banner permanently? This cannot be undone.'))) return;
				delBtn.disabled = true;
				// Helper: navigate back to the page-without-banner-id so the
				// editor mounts on the default banner. Used by both the
				// happy-path post-delete reload and the "already gone"
				// recovery path triggered by a 404 from a stale tab.
				// CodeRabbit feedback: use location.replace() so the back
				// button doesn't return to a dead banner_id state.
				var redirectToDefault = function () {
					var base = window.location.href.split('?')[0];
					var page = (window.location.search.match(/page=([^&]+)/) || [null, 'faz-cookie-manager-banner'])[1];
					window.location.replace(base + '?page=' + encodeURIComponent(page));
				};
				// "Already gone" recovery: longer delay than the happy-path
				// reload so the admin has time to perceive the toast, and
				// the user can cancel by interacting (mouse/keyboard).
				// Doubles further when prefers-reduced-motion is set.
				var redirectWithGrace = function () {
					var delay = 1500;
					try {
						if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
							delay = 2000;
						}
					} catch (e) { /* matchMedia unavailable on very old IE; keep default */ }
					var timer = setTimeout(redirectToDefault, delay);
					var cancelOnce = function () {
						clearTimeout(timer);
						window.removeEventListener('pointermove', cancelOnce);
						window.removeEventListener('keydown', cancelOnce);
					};
					window.addEventListener('pointermove', cancelOnce, { once: true });
					window.addEventListener('keydown', cancelOnce, { once: true });
				};
				FAZ.del('banners/' + bannerId).then(function (resp) {
					// REST returns the deleted row count from $wpdb->delete.
					// With the 1.14.1+ server-side existence probe a non-
					// existent id reaches us as a 404 (handled in .catch),
					// not as 0-affected — so a 0 here means the row was
					// gone between the probe and the DELETE statement
					// (race condition on a concurrent admin session). Treat
					// it as "already deleted" rather than a hard failure.
					var n = (typeof resp === 'number') ? resp : (resp && typeof resp.deleted === 'number' ? resp.deleted : 1);
					if (!n) {
						FAZ.notify(__('banner.alreadyDeleted', 'This banner was already removed. Reloading…'));
						redirectWithGrace();
						return;
					}
					// Verify with a second GET so we don't reload to a
					// cached page that still shows the deleted banner.
					return FAZ.get('banners').then(function (rows) {
						var still = Array.isArray(rows) && rows.some(function (b) { return Number(b.id) === Number(bannerId); });
						if (still) {
							delBtn.disabled = false;
							FAZ.notify(__('banner.deleteFailed', 'Failed to delete banner.') + ' (server still lists id=' + bannerId + ')', 'error');
							return;
						}
						FAZ.notify(__('banner.deleted', 'Banner deleted.'));
						redirectToDefault();
					});
				}).catch(function (err) {
					// "Already gone" path: 404 from the existence probe in
					// the REST DELETE handler. Happens when the tab is
					// stale (e.g. another admin already deleted this
					// banner, or it was the phantom id=2513570-style
					// orphan left over from the pre-1.14.1 auto-increment
					// leak). Quiet the error, tell the admin what
					// happened, reload onto the default banner.
					var alreadyGone = err && (
						err.code === 'fazcookie_rest_invalid_id'
						|| err.code === 'rest_no_route'
						|| (err.data && err.data.status === 404)
					);
					if ( alreadyGone ) {
						FAZ.notify(__('banner.alreadyDeleted', 'This banner was already removed. Reloading…'));
						redirectWithGrace();
						return;
					}
					delBtn.disabled = false;
					var detail = '';
					if (err && err.code) detail = ' [' + err.code + ']';
					else if (err && err.message) detail = ' [' + err.message + ']';
					FAZ.notify(__('banner.deleteFailed', 'Failed to delete banner.') + detail, 'error');
					if (window.console && console.error) console.error('FAZ delete banner failed', err);
				});
			});
			delBtn.dataset.fazSwitcherBound = '1';
		}
	}

	// ── "+ New banner" modal — collects the minimum info needed to spin
	// up a meaningful banner row instead of silently cloning the current
	// one. Asks for: name, applicable law (GDPR/CCPA — drives the default
	// config seed), optional region presets + custom country codes, optional
	// priority, optional "use as default fallback" flag.
	function openNewBannerModal() {
		var form = document.createElement('div');
		form.style.cssText = 'display:flex;flex-direction:column;gap:1rem;min-width:480px;';

		// Name
		var nameWrap = document.createElement('div');
		var nameLabel = document.createElement('label');
		nameLabel.textContent = __('banner.new.name', 'Banner name');
		nameLabel.style.cssText = 'display:block;font-weight:500;margin-bottom:.25rem;';
		var nameInput = document.createElement('input');
		nameInput.type = 'text';
		nameInput.className = 'faz-input';
		nameInput.style.width = '100%';
		nameInput.placeholder = __('banner.new.namePlaceholder', 'e.g. CCPA US visitors');
		nameInput.value = __('banner.new.defaultName', 'New banner');
		nameWrap.appendChild(nameLabel);
		nameWrap.appendChild(nameInput);
		form.appendChild(nameWrap);

		// Law
		var lawWrap = document.createElement('div');
		var lawLabel = document.createElement('label');
		lawLabel.textContent = __('banner.new.law', 'Consent model');
		lawLabel.style.cssText = 'display:block;font-weight:500;margin-bottom:.25rem;';
		var lawHelp = document.createElement('div');
		lawHelp.className = 'faz-help';
		lawHelp.style.cssText = 'margin-bottom:.4rem;';
		lawHelp.innerHTML = __(
			'banner.new.lawHelp',
			'Pick the legal paradigm, not the country — language, "Do not sell" copy and country targeting live in the Content + Geo Targeting tabs after creation.<br><strong>Opt-in</strong> covers GDPR, UK-GDPR, ePrivacy, LGPD (Brazil), Swiss nFADP, PIPEDA (Canada), KVKK (Turkey) and similar consent-first regimes. <strong>Opt-out</strong> covers CCPA/CPRA (California), Virginia CDPA, Colorado CPA, Connecticut CTDPA, Utah UCPA and other US state laws.'
		);
		var lawSelect = document.createElement('select');
		lawSelect.className = 'faz-input';
		lawSelect.style.width = '100%';
		[
			{ value: 'gdpr', label: __('banner.new.lawOptionGdpr', 'Opt-in — GDPR, UK-GDPR, ePrivacy, LGPD, nFADP, PIPEDA, …') },
			{ value: 'ccpa', label: __('banner.new.lawOptionCcpa', 'Opt-out — CCPA/CPRA, Virginia, Colorado, Connecticut, Utah, …') }
		].forEach(function (opt) {
			var o = document.createElement('option');
			o.value = opt.value;
			o.textContent = opt.label;
			lawSelect.appendChild(o);
		});
		lawWrap.appendChild(lawLabel);
		lawWrap.appendChild(lawHelp);
		lawWrap.appendChild(lawSelect);
		form.appendChild(lawWrap);

		// Region preset chips — quick targets for the new banner. The same
		// REGION_PRESETS map the Geo Targeting tab uses, so toggling
		// "EU/EEA" here produces the same target_countries the tab does.
		var regWrap = document.createElement('div');
		var regLabel = document.createElement('label');
		regLabel.textContent = __('banner.new.regions', 'Target regions (optional)');
		regLabel.style.cssText = 'display:block;font-weight:500;margin-bottom:.25rem;';
		var regHelp = document.createElement('div');
		regHelp.className = 'faz-help';
		regHelp.style.cssText = 'margin-bottom:.4rem;';
		regHelp.textContent = __('banner.new.regionsHelp', 'Tick the regions this banner should target. Leave all unchecked to make this a match-all / fallback banner.');
		var regGrid = document.createElement('div');
		regGrid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.4rem;';
		var REGION_LABELS = {
			EU: __('banner.new.regionEu', 'EU / EEA (27 + IS, LI, NO)'),
			UK: __('banner.new.regionUk', 'United Kingdom (UK-GDPR)'),
			US: __('banner.new.regionUs', 'United States'),
			CA: __('banner.new.regionCa', 'Canada'),
			BR: __('banner.new.regionBr', 'Brazil (LGPD)'),
			AU: __('banner.new.regionAu', 'Australia'),
			JP: __('banner.new.regionJp', 'Japan'),
			CH: __('banner.new.regionCh', 'Switzerland (nFADP)')
		};
		Object.keys(REGION_PRESETS).forEach(function (key) {
			var lbl = document.createElement('label');
			lbl.className = 'faz-toggle';
			lbl.innerHTML = ''; // build child nodes safely
			var input = document.createElement('input');
			input.type = 'checkbox';
			input.className = 'faz-b-new-region';
			input.value = key;
			var track = document.createElement('span');
			track.className = 'faz-toggle-track';
			var txt = document.createElement('span');
			txt.textContent = REGION_LABELS[key] || key;
			lbl.appendChild(input);
			lbl.appendChild(track);
			lbl.appendChild(txt);
			regGrid.appendChild(lbl);
		});
		regWrap.appendChild(regLabel);
		regWrap.appendChild(regHelp);
		regWrap.appendChild(regGrid);
		form.appendChild(regWrap);

		// Custom country codes
		var customWrap = document.createElement('div');
		var customLabel = document.createElement('label');
		customLabel.textContent = __('banner.new.customCountries', 'Additional country codes (optional)');
		customLabel.style.cssText = 'display:block;font-weight:500;margin-bottom:.25rem;';
		var customInput = document.createElement('input');
		customInput.type = 'text';
		customInput.className = 'faz-input';
		customInput.style.width = '100%';
		customInput.placeholder = __('banner.new.customCountriesPlaceholder', 'NZ, SG, KR');
		customWrap.appendChild(customLabel);
		customWrap.appendChild(customInput);
		form.appendChild(customWrap);

		// Priority + default-fallback (compact row)
		var rowWrap = document.createElement('div');
		rowWrap.style.cssText = 'display:flex;gap:1rem;align-items:flex-end;';
		var prioWrap = document.createElement('div');
		var prioLabel = document.createElement('label');
		prioLabel.textContent = __('banner.new.priority', 'Priority');
		prioLabel.style.cssText = 'display:block;font-weight:500;margin-bottom:.25rem;font-size:13px;';
		var prioInput = document.createElement('input');
		prioInput.type = 'number';
		prioInput.className = 'faz-input';
		prioInput.min = '0'; prioInput.max = '9999'; prioInput.step = '1'; prioInput.value = '0';
		prioInput.style.width = '120px';
		prioWrap.appendChild(prioLabel);
		prioWrap.appendChild(prioInput);
		var defWrap = document.createElement('div');
		defWrap.style.cssText = 'flex:1;';
		var defLbl = document.createElement('label');
		defLbl.className = 'faz-toggle';
		var defInput = document.createElement('input');
		defInput.type = 'checkbox';
		var defTrack = document.createElement('span');
		defTrack.className = 'faz-toggle-track';
		var defText = document.createElement('span');
		defText.textContent = __('banner.new.useAsDefault', 'Use as default fallback');
		defLbl.appendChild(defInput);
		defLbl.appendChild(defTrack);
		defLbl.appendChild(defText);
		defWrap.appendChild(defLbl);
		rowWrap.appendChild(prioWrap);
		rowWrap.appendChild(defWrap);
		form.appendChild(rowWrap);

		// Footer buttons
		var footer = document.createElement('div');
		var cancelBtn = document.createElement('button');
		cancelBtn.type = 'button';
		cancelBtn.className = 'faz-btn faz-btn-secondary';
		cancelBtn.textContent = __('banner.new.cancel', 'Cancel');
		var createBtn = document.createElement('button');
		createBtn.type = 'button';
		createBtn.className = 'faz-btn faz-btn-primary';
		createBtn.textContent = __('banner.new.create', 'Create banner');
		footer.appendChild(cancelBtn);
		footer.appendChild(createBtn);

		var m = FAZ.modal({
			title: __('banner.new.title', 'Create a new banner'),
			body: form,
			footer: footer,
			size: 'lg'
		});

		cancelBtn.addEventListener('click', function () { m.close(); });

		createBtn.addEventListener('click', function () {
			var name = (nameInput.value || '').trim();
			if (!name) { nameInput.focus(); return; }
			var law = lawSelect.value === 'ccpa' ? 'ccpa' : 'gdpr';

			// Collect target countries: region preset codes + custom codes,
			// deduped + normalised by the helper the Geo Targeting tab uses.
			var targets = [];
			form.querySelectorAll('.faz-b-new-region:checked').forEach(function (cb) {
				targets = targets.concat(REGION_PRESETS[cb.value] || []);
			});
			if (customInput.value) {
				targets = targets.concat(customInput.value.split(/[,\s]+/));
			}
			targets = normaliseCountryCodes(targets);

			var priority = parseInt(prioInput.value, 10);
			if (!isFinite(priority) || priority < 0) priority = 0;

			createBtn.disabled = true;
			cancelBtn.disabled = true;
			createBtn.textContent = __('banner.new.creating', 'Creating…');

			// Pull the law-appropriate default config so the new banner
			// starts with sane content/translations/colours instead of an
			// empty shell.
			FAZ.get('banners/configs').then(function (configs) {
				var properties = (configs && configs[law]) ? configs[law] : (bannerData ? bannerData.properties : {});
				return FAZ.post('banners', {
					name: name,
					status: true,
					'default': !!defInput.checked,
					properties: properties,
					contents: bannerData ? bannerData.contents : {},
					target_countries: targets,
					priority: priority
				});
			}).then(function (created) {
				var newId = created && created.id ? Number(created.id) : 0;
				if (newId <= 0) {
					FAZ.notify(__('banner.new.failed', 'Failed to create banner.'), 'error');
					createBtn.disabled = false;
					cancelBtn.disabled = false;
					createBtn.textContent = __('banner.new.create', 'Create banner');
					return;
				}
				var base = window.location.href.split('?')[0];
				var page = (window.location.search.match(/page=([^&]+)/) || [null, 'faz-cookie-manager-banner'])[1];
				window.location.href = base + '?page=' + encodeURIComponent(page) + '&banner_id=' + newId;
			}).catch(function () {
				FAZ.notify(__('banner.new.failed', 'Failed to create banner.'), 'error');
				createBtn.disabled = false;
				cancelBtn.disabled = false;
				createBtn.textContent = __('banner.new.create', 'Create banner');
			});
		});
	}

	// ── Geo Targeting (multi-banner geo-routing, 1.14.0+) ────────────────
	//
	// Region presets group ISO-3166 alpha-2 codes into clickable bundles
	// used by the multi-banner picker.
	//
	// EU = 27 EU + 3 EEA (Iceland, Liechtenstein, Norway) — 30 countries,
	// deliberately WITHOUT GB. UK is a separate preset because UK-GDPR is
	// a distinct regime and admins that want different copy / cookie text
	// for UK-vs-EU visitors need to be able to target them independently.
	// (The legacy server-side region_map in Settings → Geolocation does
	// include GB in 'eu' for backward compat with installs that only have
	// the single-banner global geo-targeting setting, but multi-banner
	// geo-routing keeps the two paradigms separate by design.)
	var REGION_PRESETS = {
		EU: ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE','IS','LI','NO'],
		UK: ['GB'],
		US: ['US'],
		CA: ['CA'],
		BR: ['BR'],
		AU: ['AU'],
		JP: ['JP'],
		CH: ['CH']
	};

	function normaliseCountryCodes(input) {
		var out = [];
		var seen = {};
		var list = Array.isArray(input) ? input : String(input || '').split(/[,\s]+/);
		for (var i = 0; i < list.length; i++) {
			var code = String(list[i] || '').trim().toUpperCase();
			if (/^[A-Z]{2}$/.test(code) && !seen[code]) {
				seen[code] = true;
				out.push(code);
			}
		}
		out.sort();
		return out;
	}

	function populateGeoTargeting() {
		if (!bannerData) return;
		var targets = normaliseCountryCodes(bannerData.target_countries || []);
		// Region checkboxes: tick a preset only when ALL of its countries are
		// in the target list. When SOME (but not all) are present, leave the
		// checkbox unchecked AND set the HTML5 `indeterminate` flag so the
		// admin sees the partial-match state. collectGeoTargeting() inspects
		// the same indeterminate flag to avoid re-adding the missing
		// countries on save — fixes issue #105 (lossy round-trip).
		// `indeterminate` is purely visual; on user-click the browser clears
		// it automatically, switching the preset to the explicit "all in"
		// or "none in" semantic the admin just expressed.
		var regionInputs = document.querySelectorAll('.faz-b-geo-region');
		for (var i = 0; i < regionInputs.length; i++) {
			var key = regionInputs[i].value;
			var preset = REGION_PRESETS[key] || [];
			var inSet = preset.filter(function (c) { return targets.indexOf(c) !== -1; });
			var allIn = preset.length > 0 && inSet.length === preset.length;
			var someIn = !allIn && inSet.length > 0;
			regionInputs[i].checked = allIn;
			regionInputs[i].indeterminate = someIn;
			// Bind a one-time change handler that flips indeterminate off when
			// the admin actually clicks the checkbox — the browser does this
			// natively but explicit is safer across older webview engines.
			if (!regionInputs[i].dataset.fazTriBound) {
				regionInputs[i].addEventListener('change', function () { this.indeterminate = false; });
				regionInputs[i].dataset.fazTriBound = '1';
			}
		}
		// Custom field: codes that are NOT covered by any ticked region
		// preset AND not covered by any indeterminate-preset partial match
		// (those countries already represent the admin's manual selection,
		// so they don't need to be duplicated in the custom field).
		var coveredByRegion = {};
		for (var j = 0; j < regionInputs.length; j++) {
			if (!regionInputs[j].checked) continue;
			var p = REGION_PRESETS[regionInputs[j].value] || [];
			for (var k = 0; k < p.length; k++) coveredByRegion[p[k]] = true;
		}
		var leftover = targets.filter(function (c) { return !coveredByRegion[c]; });
		var customInput = document.getElementById('faz-b-geo-custom');
		if (customInput) customInput.value = leftover.join(', ');
		// Priority + default flag.
		var priorityInput = document.getElementById('faz-b-geo-priority');
		if (priorityInput) priorityInput.value = (bannerData.priority != null ? bannerData.priority : 0);
		var defaultInput = document.getElementById('faz-b-geo-default');
		if (defaultInput) defaultInput.checked = !!bannerData['default'];

		// Default-flag impact preview. When the admin ticks this toggle on
		// a banner that's NOT currently the default, list the names of the
		// peer banners whose default flag the save will clear. Gives the
		// admin visibility into the destructive side-effect documented in
		// the help text, before they hit Save.
		(function bindDefaultImpactPreview() {
			var toggle = document.getElementById('faz-b-geo-default');
			var impact = document.getElementById('faz-b-geo-default-impact');
			if (!toggle || !impact) return;
			var refresh = function () {
				if (!toggle.checked) { impact.style.display = 'none'; impact.textContent = ''; return; }
				FAZ.get('banners').then(function (data) {
					var rows = Array.isArray(data) ? data : [];
					var others = rows
						.filter(function (b) { return Number(b.id) !== Number(bannerId) && Number(b['default']) === 1; })
						.map(function (b) { return b.name || ('Banner #' + b.id); });
					if (others.length === 0) {
						impact.style.display = 'none';
						impact.textContent = '';
						return;
					}
					impact.textContent = __(
						'banner.defaultImpact',
						'Saving will clear the default flag on: '
					) + others.join(', ') + '.';
					impact.style.display = '';
				}).catch(function () { /* network glitch — silent, will be caught on save */ });
			};
			refresh();
			if (!toggle.dataset.fazDefaultImpactBound) {
				toggle.addEventListener('change', refresh);
				toggle.dataset.fazDefaultImpactBound = '1';
			}
		})();
	}

	function collectGeoTargeting() {
		var collected = [];
		var regionInputs = document.querySelectorAll('.faz-b-geo-region');
		for (var i = 0; i < regionInputs.length; i++) {
			// Tri-state semantics (issue #105): only checked presets
			// contribute their full country list. Indeterminate presets
			// (partial-match populated by populateGeoTargeting) stay out —
			// their already-present countries are in the custom field, so
			// adding the full preset back would re-introduce the missing
			// ones the admin had removed.
			if (!regionInputs[i].checked || regionInputs[i].indeterminate) continue;
			var preset = REGION_PRESETS[regionInputs[i].value] || [];
			collected = collected.concat(preset);
		}
		var customInput = document.getElementById('faz-b-geo-custom');
		if (customInput && customInput.value) {
			collected = collected.concat(customInput.value.split(/[,\s]+/));
		}
		var priorityInput = document.getElementById('faz-b-geo-priority');
		var priority = priorityInput ? parseInt(priorityInput.value, 10) : 0;
		if (!isFinite(priority) || priority < 0) priority = 0;
		var defaultInput = document.getElementById('faz-b-geo-default');
		return {
			target_countries: normaliseCountryCodes(collected),
			priority: priority,
			'default': defaultInput ? !!defaultInput.checked : false
		};
	}

	/**
	 * Load the "Enable cookie banner" toggle state from /settings and wire
	 * up a live change handler that writes back to the same option. The
	 * setting is also available on the Settings page (Banner Control card);
	 * we mirror it here so users have an obvious entry-point on the Cookie
	 * Banner page (publisher feedback, 2026-05).
	 */
	function loadBannerEnabledToggle() {
		var toggle = document.getElementById('faz-b-enabled');
		if (!toggle) return;
		FAZ.get('settings').then(function (settings) {
			var enabled = !!(settings && settings.banner_control && settings.banner_control.status);
			toggle.checked = enabled;
			toggle.addEventListener('change', function () {
				var newValue = !!toggle.checked;
				// Optimistic UI — revert on failure.
				FAZ.post('settings', {
					banner_control: { status: newValue }
				}).then(function () {
					FAZ.notify(newValue
						? __('banner.enabled', 'Cookie banner enabled.')
						: __('banner.disabled', 'Cookie banner disabled.'));
				}).catch(function () {
					toggle.checked = !newValue;
					FAZ.notify(__('banner.toggleFailed', 'Failed to update banner status.'), 'error');
				});
			});
		}).catch(function () {
			// Quiet failure — the Settings page remains as the source of truth.
			toggle.disabled = true;
		});
	}

	// ── Populate Settings (non-language fields) ──

	function populateSettings() {
		if (!bannerData) return;
		var props = bannerData.properties || {};
		var s = props.settings || {};
		var b = props.behaviours || {};
		var config = props.config || {};

		// General tab - type is stored directly (classic is its own type since v1.6).
		var displayType = s.type || 'box';
		setVal('faz-b-type', displayType);
		setVal('faz-b-position', s.position || 'bottom-right');
		setVal('faz-b-theme', s.theme || 'light');
		setVal('faz-b-pref-type', s.preferenceCenterType || 'popup');
		setVal('faz-b-expiry', (s.consentExpiry && s.consentExpiry.value) || 365);
		// Detect regulation mode: gdpr + donotSell.status=true → "Both" mode
		var lawVal = s.applicableLaw || 'gdpr';
		var donotSellEl = (config.notice && config.notice.elements && config.notice.elements.donotSell) || {};
		if (lawVal === 'gdpr' && donotSellEl.status === true) lawVal = 'gdpr_ccpa';
		setVal('faz-b-law', lawVal);

		// Determine languages - prefer global config (Languages page) over banner's stale copy
		var globalLangs = (typeof fazConfig !== 'undefined' && fazConfig.languages) || {};
		var langs = (globalLangs.selected && globalLangs.selected.length) ? globalLangs.selected : ((s.languages && s.languages.selected) || ['en']);
		currentLang = globalLangs['default'] || (s.languages && s.languages['default']) || langs[0] || 'en';
		populateLangSelects(langs, currentLang);

		// Colours - notice
		var noticeStyles = (config.notice && config.notice.styles) || {};
		setColor('faz-b-notice-bg', noticeStyles['background-color'] || '#FFFFFF');
		setColor('faz-b-notice-border', noticeStyles['border-color'] || '#F4F4F4');

		var titleStyles = (config.notice && config.notice.elements && config.notice.elements.title && config.notice.elements.title.styles) || {};
		setColor('faz-b-title-color', titleStyles.color || '#1e293b');

		var descStyles = (config.notice && config.notice.elements && config.notice.elements.description && config.notice.elements.description.styles) || {};
		setColor('faz-b-desc-color', descStyles.color || '#64748b');

		var ao = config.accessibilityOverrides || {};
		var linkStyles = (ao.elements && ao.elements.manualLinks && ao.elements.manualLinks.styles) || {};
		setColor('faz-b-link-color', linkStyles.color || '#1863DC');

		// Colours - buttons
		var buttons = (config.notice && config.notice.elements && config.notice.elements.buttons && config.notice.elements.buttons.elements) || {};
		populateButtonColors('accept', buttons.accept);
		populateButtonColors('reject', buttons.reject);
		populateButtonColors('settings', buttons.settings);
		// Do Not Sell text colour (single picker, not full button trio)
		var donotSellStyles = (buttons.donotSell && buttons.donotSell.styles) || {};
		setColor('faz-b-donotsell-text', donotSellStyles.color || '#1863DC');
		toggleDoNotSellColorRow(lawVal);

		// Category preview colours
		var catPreview = (config.categoryPreview && config.categoryPreview.elements) || {};
		var catTitle = (catPreview.title && catPreview.title.styles) || {};
		setColor('faz-b-catprev-label', catTitle.color || '#212121');
		var catToggle = catPreview.toggle || {};
		var catToggleActive = (catToggle.states && catToggle.states.active && catToggle.states.active.styles) || {};
		var catToggleInactive = (catToggle.states && catToggle.states.inactive && catToggle.states.inactive.styles) || {};
		setColor('faz-b-catprev-toggle-active', catToggleActive['background-color'] || '#1863DC');
		setColor('faz-b-catprev-toggle-inactive', catToggleInactive['background-color'] || '#D0D5D2');
		var catSave = (catPreview.buttons && catPreview.buttons.elements && catPreview.buttons.elements.save && catPreview.buttons.elements.save.styles) || {};
		setColor('faz-b-catprev-save-text', catSave.color || '#1863DC');
		var catSaveBg = catSave['background-color'] || 'transparent';
		setColor('faz-b-catprev-save-bg', catSaveBg);
		setColor('faz-b-catprev-save-border', catSave['border-color'] || '#1863DC');

		// Button toggles
		setChecked('faz-b-accept-toggle', getStatus(buttons.accept));
		setChecked('faz-b-reject-toggle', getStatus(buttons.reject));
		setChecked('faz-b-settings-toggle', getStatus(buttons.settings));
		setChecked('faz-b-readmore-toggle', getStatus(buttons.readMore));

		var closeBtn = (config.notice && config.notice.elements && config.notice.elements.closeButton) || {};
		setChecked('faz-b-close-toggle', typeof closeBtn === 'object' ? getStatus(closeBtn) : true);

		// Per-banner override of the Garante/EDPB dark-pattern auto-hide
		// (1.14.0+). The flag lives at properties.settings.allowCloseButtonWithReject.
		var bannerSettings = (bannerData.properties && bannerData.properties.settings) || {};
		setChecked('faz-b-close-with-reject-toggle', !!bannerSettings.allowCloseButtonWithReject);

		// The sub-toggle is only meaningful when the parent "Show Close Button"
		// is on — a ticked override on an OFF parent is a no-op that
		// misleadingly suggests something is active. Bind disabled state to
		// the parent and re-run the binding any time the parent changes.
		(function bindCloseSubToggle() {
			// F001 fix: `faz-b-close-toggle` is the wrapping <label>,
			// not the <input>. Reading `label.checked` returns undefined,
			// so the sub-toggle was unconditionally disabled regardless
			// of parent state. Query the underlying checkbox via a
			// descendant selector so .checked is a real boolean.
			var parentLabel = document.getElementById('faz-b-close-toggle');
			var parent = parentLabel ? parentLabel.querySelector('input[type="checkbox"]') : null;
			var sub = document.getElementById('faz-b-close-with-reject');
			var group = document.getElementById('faz-b-close-with-reject-group');
			if (!parent || !sub) return;
			var sync = function () {
				var enabled = !!parent.checked;
				sub.disabled = !enabled;
				if (group) {
					group.style.opacity = enabled ? '1' : '0.5';
					group.style.pointerEvents = enabled ? '' : 'none';
					group.setAttribute('aria-disabled', enabled ? 'false' : 'true');
				}
				if (!enabled) sub.checked = false; // can't override when X isn't shown.
			};
			sync();
			// Avoid double-binding on populate re-runs.
			if (!parent.dataset.fazCloseSubBound) {
				parent.addEventListener('change', sync);
				parent.dataset.fazCloseSubBound = '1';
			}
		})();

		// Audit table
		var auditTable = config.auditTable || {};
		setChecked('faz-b-audit-toggle', getStatus(auditTable));

		// Revisit consent
		var revisit = config.revisitConsent || {};
		setChecked('faz-b-revisit-toggle', getStatus(revisit));
		setVal('faz-b-revisit-position', revisit.position || 'bottom-left');
		var revisitStyles = revisit.styles || {};
		setColor('faz-b-revisit-bg', revisitStyles['background-color'] || '#0056A7');
		setColor('faz-b-revisit-icon', revisitStyles['color'] || '#FFFFFF');

		// Behaviours
		setChecked('faz-b-reload-toggle', b.reloadBannerOnAccept && b.reloadBannerOnAccept.status);
		setChecked('faz-b-gpc-toggle', b.respectGPC && b.respectGPC.status);

		// Custom CSS field removed from the admin UI in 1.13.11 for wp.org
		// compliance. Legacy values stay in props.meta.customCSS but no
		// textarea exposes them; see admin/views/banner.php.

		// Brand logo
		var brandLogo = (config.notice && config.notice.elements && config.notice.elements.brandLogo) || {};
		setChecked('faz-b-brandlogo-toggle', getStatus(brandLogo));
		var logoUrl = (brandLogo.meta && brandLogo.meta.url) || '';
		if (logoUrl === '#') logoUrl = '';
		// Fallback to default cookie.png if no custom logo
		if (!logoUrl && typeof fazConfig !== 'undefined' && fazConfig.defaultLogo) {
			logoUrl = fazConfig.defaultLogo;
		}
		setVal('faz-b-brandlogo-url', logoUrl);
		updateBrandLogoPreview(logoUrl);
	}

	function toggleDoNotSellColorRow(law) {
		var row = document.getElementById('faz-donotsell-color-row');
		if (row) row.style.display = (law === 'ccpa' || law === 'gdpr_ccpa') ? '' : 'none';
	}

	function populateButtonColors(name, btnData) {
		if (!btnData || !btnData.styles) return;
		var st = btnData.styles;
		setColor('faz-b-' + name + '-bg', st['background-color'] || '#1863DC');
		setColor('faz-b-' + name + '-text', st.color || '#FFFFFF');
		setColor('faz-b-' + name + '-border', st['border-color'] || '#1863DC');
	}

	// ── Populate Contents (per-language) ──

	function populateLangSelects(langs, defaultLang) {
		['faz-b-content-lang', 'faz-b-pref-lang'].forEach(function (id) {
			var sel = document.getElementById(id);
			if (!sel) return;
			sel.textContent = '';
			langs.forEach(function (code) {
				var opt = document.createElement('option');
				opt.value = code;
				opt.textContent = code.toUpperCase();
				if (code === defaultLang) opt.selected = true;
				sel.appendChild(opt);
			});
		});
	}

	function syncLangSelects(lang) {
		['faz-b-content-lang', 'faz-b-pref-lang'].forEach(function (id) {
			var sel = document.getElementById(id);
			if (sel) sel.value = lang;
		});
	}

	function populateContents(lang) {
		if (!bannerData) return;
		var allContents = bannerData.contents || {};
		var c = allContents[lang] || allContents[Object.keys(allContents)[0]] || {};

		// Notice
		var notice = (c.notice && c.notice.elements) || {};
		setVal('faz-b-notice-title', notice.title || '');
		setVal('faz-b-notice-desc', notice.description || '');
		setVal('faz-b-close-label', notice.closeButton || '');

		var btnLabels = (notice.buttons && notice.buttons.elements) || {};
		setVal('faz-b-btn-accept-label', btnLabels.accept || '');
		setVal('faz-b-btn-reject-label', btnLabels.reject || '');
		setVal('faz-b-btn-settings-label', btnLabels.settings || '');
		setVal('faz-b-btn-readmore-label', btnLabels.readMore || '');

		// Cookie policy link
		var privacyLink = (notice.privacyLink || '').trim();
		setVal('faz-b-privacy-link', privacyLink || '/cookie-policy');

		// Revisit consent title (tooltip / aria-label)
		var revisitContent = (c.revisitConsent && c.revisitConsent.elements) || {};
		setVal('faz-b-revisit-title', revisitContent.title || '');

		// Preference center
		var pref = (c.preferenceCenter && c.preferenceCenter.elements) || {};
		setVal('faz-b-pref-title', pref.title || '');
		setVal('faz-b-pref-desc', pref.description || '');
		var prefBtns = (pref.buttons && pref.buttons.elements) || {};
		setVal('faz-b-pref-accept', prefBtns.accept || '');
		setVal('faz-b-pref-save', prefBtns.save || '');
		setVal('faz-b-pref-reject', prefBtns.reject || '');
	}

	// Helper: only overwrite field if the value is readable (not undefined).
	// getVal returns undefined when a TinyMCE editor is on a hidden tab.
	function storeField(obj, key, id) {
		var v = getVal(id);
		if (v !== undefined) obj[key] = v;
	}

	function storeCurrentLangContents() {
		if (!bannerData) return;
		var contents = bannerData.contents || {};
		if (!contents[currentLang]) contents[currentLang] = {};
		var c = contents[currentLang];

		// Notice
		if (!c.notice) c.notice = { elements: {} };
		if (!c.notice.elements) c.notice.elements = {};
		storeField(c.notice.elements, 'title', 'faz-b-notice-title');
		storeField(c.notice.elements, 'description', 'faz-b-notice-desc');
		storeField(c.notice.elements, 'closeButton', 'faz-b-close-label');
		if (!c.notice.elements.buttons) c.notice.elements.buttons = { elements: {} };
		if (!c.notice.elements.buttons.elements) c.notice.elements.buttons.elements = {};
		storeField(c.notice.elements.buttons.elements, 'accept', 'faz-b-btn-accept-label');
		storeField(c.notice.elements.buttons.elements, 'reject', 'faz-b-btn-reject-label');
		storeField(c.notice.elements.buttons.elements, 'settings', 'faz-b-btn-settings-label');
		storeField(c.notice.elements.buttons.elements, 'readMore', 'faz-b-btn-readmore-label');

		// Cookie policy link (fallback to /cookie-policy if empty)
		var privacyLinkVal = getVal('faz-b-privacy-link');
		if (privacyLinkVal !== undefined) {
			c.notice.elements.privacyLink = (privacyLinkVal || '').trim() || '/cookie-policy';
		}

		// Revisit consent title
		if (!c.revisitConsent) c.revisitConsent = { elements: {} };
		if (!c.revisitConsent.elements) c.revisitConsent.elements = {};
		storeField(c.revisitConsent.elements, 'title', 'faz-b-revisit-title');

		// Preference center
		if (!c.preferenceCenter) c.preferenceCenter = { elements: {} };
		if (!c.preferenceCenter.elements) c.preferenceCenter.elements = {};
		storeField(c.preferenceCenter.elements, 'title', 'faz-b-pref-title');
		storeField(c.preferenceCenter.elements, 'description', 'faz-b-pref-desc');
		if (!c.preferenceCenter.elements.buttons) c.preferenceCenter.elements.buttons = { elements: {} };
		if (!c.preferenceCenter.elements.buttons.elements) c.preferenceCenter.elements.buttons.elements = {};
		storeField(c.preferenceCenter.elements.buttons.elements, 'accept', 'faz-b-pref-accept');
		storeField(c.preferenceCenter.elements.buttons.elements, 'save', 'faz-b-pref-save');
		storeField(c.preferenceCenter.elements.buttons.elements, 'reject', 'faz-b-pref-reject');

		bannerData.contents = contents;
	}

	// ── Theme switch: apply preset colours ──

	function applyThemePreset(themeName) {
		var presets = (typeof fazConfig !== 'undefined' && fazConfig.themePresets) || [];
		var preset = null;
		for (var i = 0; i < presets.length; i++) {
			if (presets[i].name === themeName) { preset = presets[i].settings; break; }
		}
		if (!preset) return;

		// Strip all styles from bannerData.properties.config so preset applies fresh
		if (bannerData && bannerData.properties && bannerData.properties.config) {
			stripStyles(bannerData.properties.config);
		}

		// Update colour pickers from preset values
		var n = preset.notice || {};
		var ns = n.styles || {};
		setColor('faz-b-notice-bg', ns['background-color'] || '#FFFFFF');
		setColor('faz-b-notice-border', ns['border-color'] || '#F4F4F4');

		var ne = n.elements || {};
		setColor('faz-b-title-color', (ne.title && ne.title.styles && ne.title.styles.color) || '#212121');
		setColor('faz-b-desc-color', (ne.description && ne.description.styles && ne.description.styles.color) || '#212121');

		var presetAo = preset.accessibilityOverrides || {};
		var presetLink = (presetAo.elements && presetAo.elements.manualLinks && presetAo.elements.manualLinks.styles) || {};
		setColor('faz-b-link-color', presetLink.color || '#1863DC');

		var btns = (ne.buttons && ne.buttons.elements) || {};
		populateButtonColors('accept', btns.accept);
		populateButtonColors('reject', btns.reject);
		populateButtonColors('settings', btns.settings);
		var presetDns = (btns.donotSell && btns.donotSell.styles) || {};
		setColor('faz-b-donotsell-text', presetDns.color || '#1863DC');

		// Category preview colours from preset
		var catPrev = (preset.categoryPreview && preset.categoryPreview.elements) || {};
		var cpTitle = (catPrev.title && catPrev.title.styles) || {};
		setColor('faz-b-catprev-label', cpTitle.color || '#212121');
		var cpToggle = catPrev.toggle || {};
		var cpActive = (cpToggle.states && cpToggle.states.active && cpToggle.states.active.styles) || {};
		var cpInactive = (cpToggle.states && cpToggle.states.inactive && cpToggle.states.inactive.styles) || {};
		setColor('faz-b-catprev-toggle-active', cpActive['background-color'] || '#1863DC');
		setColor('faz-b-catprev-toggle-inactive', cpInactive['background-color'] || '#D0D5D2');
		var cpSave = (catPrev.buttons && catPrev.buttons.elements && catPrev.buttons.elements.save && catPrev.buttons.elements.save.styles) || {};
		setColor('faz-b-catprev-save-text', cpSave.color || '#1863DC');
		var cpSaveBg = cpSave['background-color'] || 'transparent';
		setColor('faz-b-catprev-save-bg', cpSaveBg);
		setColor('faz-b-catprev-save-border', cpSave['border-color'] || '#1863DC');

		// Re-init color pickers (update swatch display)
		FAZ.initColorPickers();

		// Theme changes should reflect immediately in the live preview.
		syncFormToBannerData();
		refreshPreview();
	}

	function stripStyles(obj) {
		if (!obj || typeof obj !== 'object') return;
		if (Array.isArray(obj)) {
			obj.forEach(function (item) { stripStyles(item); });
			return;
		}
		if (obj.styles && typeof obj.styles === 'object') {
			delete obj.styles;
		}
		Object.keys(obj).forEach(function (key) {
			if (key !== 'styles') stripStyles(obj[key]);
		});
	}

	// ── Design Presets ──

	function loadDesignPresets() {
		FAZ.get('banners/design-presets').then(function (presets) {
			var grid = document.getElementById('faz-presets-grid');
			if (!grid || !presets || !presets.length) return;

			// Clear loading text safely
			while (grid.firstChild) grid.removeChild(grid.firstChild);

			presets.forEach(function (preset) {
				var card = document.createElement('button');
				card.type = 'button';
				card.className = 'faz-preset-card';
				card.style.cssText = 'padding:16px;border:2px solid var(--faz-border);border-radius:8px;cursor:pointer;text-align:center;transition:border-color 0.2s;background:none;display:block;width:100%;';
				card.onmouseenter = function () { card.style.borderColor = 'var(--faz-primary)'; };
				card.onmouseleave = function () { card.style.borderColor = 'var(--faz-border)'; };

				// Color preview dots
				var dots = document.createElement('div');
				dots.style.cssText = 'display:flex;gap:4px;justify-content:center;margin-bottom:8px;';
				var bg = preset.config.notice.styles['background-color'];
				var accent = preset.config.notice.elements.buttons.elements.accept.styles['background-color'];
				var text = preset.config.notice.elements.title.styles.color;
				[bg, accent, text].forEach(function (c) {
					var dot = document.createElement('span');
					dot.style.cssText = 'width:16px;height:16px;border-radius:50%;border:1px solid #ccc;display:inline-block;background:' + c;
					dots.appendChild(dot);
				});
				card.appendChild(dots);

				var name = document.createElement('div');
				name.style.cssText = 'font-weight:600;font-size:13px;';
				name.textContent = preset.name;
				card.appendChild(name);

				var desc = document.createElement('div');
				desc.style.cssText = 'font-size:11px;color:var(--faz-text-muted);margin-top:4px;';
				desc.textContent = preset.description;
				card.appendChild(desc);

				card.addEventListener('click', function () {
					applyDesignPreset(preset);
				});

				grid.appendChild(card);
			});
		}).catch(function () {
			var grid = document.getElementById('faz-presets-grid');
			if (grid) {
				while (grid.firstChild) grid.removeChild(grid.firstChild);
			}
		});
	}

	function applyDesignPreset(preset) {
		var c = preset.config;
		// Type, position, theme
		if (c.type) setVal('faz-b-type', c.type);
		if (c.position) setVal('faz-b-position', c.position);
		if (c.preferenceCenterType) setVal('faz-b-pref-type', c.preferenceCenterType);
		if (c.theme) {
			setVal('faz-b-theme', c.theme);
		}

		// Update position options for the new type
		updatePositionOptions();

		// Notice colours
		var n = c.notice;
		if (n && n.styles) {
			setColorPair('faz-b-notice-bg', n.styles['background-color']);
			setColorPair('faz-b-notice-border', n.styles['border-color']);
		}
		if (n && n.elements) {
			if (n.elements.title) setColorPair('faz-b-title-color', n.elements.title.styles.color);
			if (n.elements.description) setColorPair('faz-b-desc-color', n.elements.description.styles.color);

			var btns = n.elements.buttons && n.elements.buttons.elements;
			if (btns) {
				if (btns.accept) {
					setColorPair('faz-b-accept-bg', btns.accept.styles['background-color']);
					setColorPair('faz-b-accept-text', btns.accept.styles.color);
					setColorPair('faz-b-accept-border', btns.accept.styles['border-color']);
				}
				if (btns.reject) {
					setColorPair('faz-b-reject-bg', btns.reject.styles['background-color']);
					setColorPair('faz-b-reject-text', btns.reject.styles.color);
					setColorPair('faz-b-reject-border', btns.reject.styles['border-color']);
				}
				if (btns.settings) {
					setColorPair('faz-b-settings-bg', btns.settings.styles['background-color']);
					setColorPair('faz-b-settings-text', btns.settings.styles.color);
					setColorPair('faz-b-settings-border', btns.settings.styles['border-color']);
				}
			}
		}

		// Category preview colours (form inputs exist)
		var catPrev = (c.categoryPreview && c.categoryPreview.elements) || {};
		if (catPrev.title && catPrev.title.styles) {
			setColorPair('faz-b-catprev-label', catPrev.title.styles.color);
		}
		var cpToggle = catPrev.toggle || {};
		if (cpToggle.states) {
			if (cpToggle.states.active && cpToggle.states.active.styles) {
				setColorPair('faz-b-catprev-toggle-active', cpToggle.states.active.styles['background-color']);
			}
			if (cpToggle.states.inactive && cpToggle.states.inactive.styles) {
				setColorPair('faz-b-catprev-toggle-inactive', cpToggle.states.inactive.styles['background-color']);
			}
		}
		var cpSaveBtns = (catPrev.buttons && catPrev.buttons.elements && catPrev.buttons.elements.save && catPrev.buttons.elements.save.styles) || {};
		if (cpSaveBtns.color) setColorPair('faz-b-catprev-save-text', cpSaveBtns.color);
		if (cpSaveBtns['background-color']) setColorPair('faz-b-catprev-save-bg', cpSaveBtns['background-color']);
		if (cpSaveBtns['border-color']) setColorPair('faz-b-catprev-save-border', cpSaveBtns['border-color']);

		if (c.preferenceCenter && bannerData && bannerData.properties) {
			applyPresetSection('preferenceCenter', c.preferenceCenter, [
				'elements.closeButton',
				'elements.poweredBy'
			]);
		}

		if (c.optoutPopup && bannerData && bannerData.properties) {
			applyPresetSection('optoutPopup', c.optoutPopup, [
				'elements.closeButton',
				'elements.gpcOption',
				'elements.poweredBy'
			]);
		}

		// Re-init color pickers so swatches update
		FAZ.initColorPickers();

		// Sync to bannerData and refresh preview
		syncFormToBannerData();
		refreshPreview();

		FAZ.notify(__('banner.presetApplied', 'Preset applied: %s').replace('%s', preset.name), 'success');
	}

	function setColorPair(baseId, value) {
		if (!value) return;
		var colorEl = document.getElementById(baseId);
		var hexEl = document.getElementById(baseId + '-hex');
		if (colorEl) {
			// <input type="color"> only accepts #rrggbb; skip "transparent"
			if (/^#[0-9a-fA-F]{6}$/.test(value)) {
				colorEl.value = value;
			}
		}
		if (hexEl) {
			hexEl.value = value;
		}
	}

	function applyPresetSection(sectionKey, source, preservePaths) {
		if (!source || !bannerData || !bannerData.properties) return;
		ensureObj(bannerData.properties, 'config');
		var current = bannerData.properties.config[sectionKey] || {};
		var next = cloneJson(source);
		preserveStructuralKeys(next, current);
		(preservePaths || []).forEach(function (path) {
			preserveMissingBranch(next, current, path);
		});
		if (sectionKey === 'preferenceCenter') {
			syncPreferenceCenterToggle(next, current);
		}
		bannerData.properties.config[sectionKey] = next;
	}

	function cloneJson(value) {
		return JSON.parse(JSON.stringify(value));
	}

	function isPlainObject(value) {
		return !!value && typeof value === 'object' && !Array.isArray(value);
	}

	function preserveStructuralKeys(target, source) {
		if (!isPlainObject(target) || !isPlainObject(source)) return;
		['status', 'tag', 'type'].forEach(function (key) {
			if (source[key] !== undefined && target[key] === undefined) {
				target[key] = source[key];
			}
		});
		if (source.meta !== undefined && target.meta === undefined) {
			target.meta = cloneJson(source.meta);
		}
		Object.keys(target).forEach(function (key) {
			if (key === 'styles') return;
			if (isPlainObject(target[key]) && isPlainObject(source[key])) {
				preserveStructuralKeys(target[key], source[key]);
			}
		});
	}

	function preserveMissingBranch(target, source, path) {
		if (!isPlainObject(target) || !isPlainObject(source)) return;
		var existing = getPathValue(source, path);
		if (!isPlainObject(existing) || getPathValue(target, path) !== undefined) return;
		setPathValue(target, path, cloneJson(existing));
	}

	function getPathValue(obj, path) {
		return String(path || '').split('.').reduce(function (acc, key) {
			return acc && acc[key] !== undefined ? acc[key] : undefined;
		}, obj);
	}

	function setPathValue(obj, path, value) {
		var parts = String(path || '').split('.');
		var ref = obj;
		for (var i = 0; i < parts.length - 1; i++) {
			var key = parts[i];
			if (!isPlainObject(ref[key])) ref[key] = {};
			ref = ref[key];
		}
		ref[parts[parts.length - 1]] = value;
	}

		function syncPreferenceCenterToggle(section, current) {
			if (!isPlainObject(section)) return;
			var presetToggle = getPathValue(section, 'elements.categories.elements.toggle');
		if (!isPlainObject(presetToggle)) return;
		if (!isPlainObject(section.toggle)) section.toggle = {};
		if (isPlainObject(current && current.toggle)) {
			preserveStructuralKeys(section.toggle, current.toggle);
		}
			if (presetToggle.states !== undefined) {
				section.toggle.states = cloneJson(presetToggle.states);
			}
		}

		function normalizeBannerConfig(props) {
			if (!props || !props.config || !props.config.preferenceCenter) return;
			syncPreferenceCenterToggle(props.config.preferenceCenter, props.config.preferenceCenter);
		}

	// ── Sync form → bannerData (used by save and live preview) ──

	function syncFormToBannerData() {
		if (!bannerData) return;
		storeCurrentLangContents();

		if (!bannerData.properties || typeof bannerData.properties !== 'object') bannerData.properties = {};
		var props = bannerData.properties;
		if (!props.settings || typeof props.settings !== 'object') props.settings = {};
		if (!props.config || typeof props.config !== 'object') props.config = {};
		if (!props.config.categoryPreview || typeof props.config.categoryPreview !== 'object') props.config.categoryPreview = {};

		// Settings - save type directly; classic is its own type (not banner+pushdown).
		var formType = getVal('faz-b-type');
		props.settings.type = formType;
		if (formType === 'classic') {
			// Classic always uses pushdown preference center + inline toggles
			props.settings.preferenceCenterType = 'pushdown';
			props.config.categoryPreview.status = true;
		} else {
			props.settings.preferenceCenterType = getVal('faz-b-pref-type');
			// Non-classic: disable inline category preview
			props.config.categoryPreview.status = false;
		}
		props.settings.position = getVal('faz-b-position');
		props.settings.theme = getVal('faz-b-theme');
		if (!props.settings.consentExpiry) props.settings.consentExpiry = {};
		props.settings.consentExpiry.status = true;
		props.settings.consentExpiry.value = getVal('faz-b-expiry');

		// Sync global languages into banner settings
		var globalLangs = (typeof fazConfig !== 'undefined' && fazConfig.languages) || {};
		if (globalLangs.selected && globalLangs.selected.length) {
			props.settings.languages = {
				selected: globalLangs.selected,
				'default': globalLangs['default'] || globalLangs.selected[0]
			};
		}

		// Applicable law
		var law = getVal('faz-b-law') || 'gdpr';
		props.settings.applicableLaw = law === 'gdpr_ccpa' ? 'gdpr' : law;

		// "Do Not Sell" button: on for ccpa/both, off for gdpr-only
		ensureObj(props, 'config.notice.elements.donotSell');
		props.config.notice.elements.donotSell.tag = 'donotsell-button';
		props.config.notice.elements.donotSell.status = (law === 'ccpa' || law === 'gdpr_ccpa');

		// Colours - notice
		ensureObj(props, 'config.notice.styles');
		props.config.notice.styles['background-color'] = getColor('faz-b-notice-bg');
		props.config.notice.styles['border-color'] = getColor('faz-b-notice-border');

		ensureObj(props, 'config.notice.elements.title.styles');
		props.config.notice.elements.title.styles.color = getColor('faz-b-title-color');
		ensureObj(props, 'config.notice.elements.description.styles');
		props.config.notice.elements.description.styles.color = getColor('faz-b-desc-color');

		ensureObj(props, 'config.accessibilityOverrides.elements.manualLinks.styles');
		props.config.accessibilityOverrides.elements.manualLinks.styles.color = getColor('faz-b-link-color');

		// Colours + status - buttons
		ensureObj(props, 'config.notice.elements.buttons.elements');
		var btns = props.config.notice.elements.buttons.elements;
		ensureObj(btns, 'accept.styles');
		ensureObj(btns, 'reject.styles');
		ensureObj(btns, 'settings.styles');
		readButtonColors('accept', btns.accept);
		readButtonColors('reject', btns.reject);
		readButtonColors('settings', btns.settings);
		// Do Not Sell text colour + mirror status for consistency
		ensureObj(btns, 'donotSell.styles');
		btns.donotSell.tag = 'donotsell-button';
		btns.donotSell.status = (law === 'ccpa' || law === 'gdpr_ccpa');
		btns.donotSell.styles.color = getColor('faz-b-donotsell-text');

		btns.accept.status = isChecked('faz-b-accept-toggle');
		btns.reject.status = isChecked('faz-b-reject-toggle');
		btns.settings.status = isChecked('faz-b-settings-toggle');
		if (btns.readMore) btns.readMore.status = isChecked('faz-b-readmore-toggle');

		// Close button
		if (!props.config.notice.elements.closeButton) props.config.notice.elements.closeButton = {};
		if (typeof props.config.notice.elements.closeButton !== 'object') props.config.notice.elements.closeButton = {};
		props.config.notice.elements.closeButton.status = isChecked('faz-b-close-toggle');

		// Per-banner override of the Garante/EDPB dark-pattern auto-hide.
		// Lives under properties.settings so it travels with the banner row.
		if (!props.settings || typeof props.settings !== 'object') props.settings = {};
		props.settings.allowCloseButtonWithReject = isChecked('faz-b-close-with-reject-toggle');

		// Brand logo
		ensureObj(props, 'config.notice.elements.brandLogo');
		props.config.notice.elements.brandLogo.status = isChecked('faz-b-brandlogo-toggle');
		props.config.notice.elements.brandLogo.tag = 'brand-logo';
		if (!props.config.notice.elements.brandLogo.meta) props.config.notice.elements.brandLogo.meta = {};
		var logoUrl = getVal('faz-b-brandlogo-url');
		props.config.notice.elements.brandLogo.meta.url = logoUrl || '#';

		// Category preview colours
		ensureObj(props, 'config.categoryPreview.elements.title.styles');
		props.config.categoryPreview.elements.title.styles.color = getColor('faz-b-catprev-label');
		ensureObj(props, 'config.categoryPreview.elements.toggle.states.active.styles');
		props.config.categoryPreview.elements.toggle.states.active.styles['background-color'] = getColor('faz-b-catprev-toggle-active');
		ensureObj(props, 'config.categoryPreview.elements.toggle.states.inactive.styles');
		props.config.categoryPreview.elements.toggle.states.inactive.styles['background-color'] = getColor('faz-b-catprev-toggle-inactive');
		ensureObj(props, 'config.categoryPreview.elements.buttons.elements.save.styles');
		props.config.categoryPreview.elements.buttons.elements.save.styles.color = getColor('faz-b-catprev-save-text');
		props.config.categoryPreview.elements.buttons.elements.save.styles['background-color'] = getColor('faz-b-catprev-save-bg');
		props.config.categoryPreview.elements.buttons.elements.save.styles['border-color'] = getColor('faz-b-catprev-save-border');

		// Preference center toggles must always be enabled (GDPR granular consent)
		ensureObj(props, 'config.preferenceCenter.toggle');
		props.config.preferenceCenter.toggle.status = true;

		// Audit table
		if (!props.config.auditTable) props.config.auditTable = {};
		props.config.auditTable.status = isChecked('faz-b-audit-toggle');

		// Revisit consent
		if (!props.config.revisitConsent) props.config.revisitConsent = {};
		props.config.revisitConsent.status = isChecked('faz-b-revisit-toggle');
		props.config.revisitConsent.position = getVal('faz-b-revisit-position');
		if (!props.config.revisitConsent.styles) props.config.revisitConsent.styles = {};
		props.config.revisitConsent.styles['background-color'] = getColor('faz-b-revisit-bg');
		props.config.revisitConsent.styles['color'] = getColor('faz-b-revisit-icon');

		// Behaviours
		if (!props.behaviours) props.behaviours = {};
		if (!props.behaviours.reloadBannerOnAccept) props.behaviours.reloadBannerOnAccept = {};
		props.behaviours.reloadBannerOnAccept.status = isChecked('faz-b-reload-toggle');
		if (!props.behaviours.respectGPC) props.behaviours.respectGPC = {};
		props.behaviours.respectGPC.status = isChecked('faz-b-gpc-toggle');

			// Custom CSS field removed from the admin UI in 1.13.11 for
			// wp.org compliance — we no longer write props.meta.customCSS
			// from the editor. Legacy DB values are preserved but inert.
			if (!props.meta) props.meta = {};
			normalizeBannerConfig(props);
		}

	// ── Save ──

	function saveBanner() {
		if (!bannerData) return;
		var btn = document.getElementById('faz-b-save');
		FAZ.btnLoading(btn, true);

		syncFormToBannerData();

		var geo = collectGeoTargeting();
		bannerData.target_countries = geo.target_countries;
		bannerData.priority = geo.priority;
		bannerData['default'] = geo['default'];

		var payload = {
			name: bannerData.name,
			status: bannerData.status,
			'default': bannerData['default'],
			properties: bannerData.properties,
			contents: bannerData.contents,
			target_countries: bannerData.target_countries,
			priority: bannerData.priority,
		};

			FAZ.put('banners/' + bannerId, payload).then(function (updated) {
				bannerData = updated;
				normalizeBannerConfig(bannerData.properties);
				FAZ.btnLoading(btn, false);
				FAZ.notify(__('banner.saved', 'Banner settings saved.'));
			refreshPreview();
		}).catch(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify(__('banner.saveFailed', 'Failed to save banner settings.'), 'error');
		});
	}

	function readButtonColors(name, btnObj) {
		if (!btnObj) return;
		if (!btnObj.styles) btnObj.styles = {};
		btnObj.styles['background-color'] = getColor('faz-b-' + name + '-bg');
		btnObj.styles.color = getColor('faz-b-' + name + '-text');
		btnObj.styles['border-color'] = getColor('faz-b-' + name + '-border');
	}

	// ── Fixed-Position Live Preview ──

	function refreshPreview() {
		if (!bannerData) return;
		if (!previewVisible) return;
		var host = document.getElementById('faz-b-preview-host');
		if (!host) return;

		var payload = {
			id: bannerId,
			name: bannerData.name,
			status: bannerData.status,
			'default': bannerData['default'],
			properties: bannerData.properties || {},
			contents: bannerData.contents || {},
		};

		// Collect which tags should be hidden based on toggle states
		var hiddenTags = [];
		if (!isChecked('faz-b-accept-toggle')) hiddenTags.push('accept-button');
		if (!isChecked('faz-b-reject-toggle')) hiddenTags.push('reject-button');
		if (!isChecked('faz-b-settings-toggle')) hiddenTags.push('settings-button');
		if (!isChecked('faz-b-close-toggle')) hiddenTags.push('close-button');
		if (!isChecked('faz-b-readmore-toggle')) hiddenTags.push('readmore-button');
		if (!isChecked('faz-b-revisit-toggle')) hiddenTags.push('revisit-consent');
		if (!isChecked('faz-b-audit-toggle')) hiddenTags.push('audit-table');
		if (!isChecked('faz-b-brandlogo-toggle')) hiddenTags.push('brand-logo');

		// Legislation: hide "do not sell" button for GDPR-only
		var law = getVal('faz-b-law') || 'gdpr';
		if (law === 'gdpr') hiddenTags.push('donotsell-button');

		var thisRequestId = ++previewRequestId;
		pendingPreviewState = null;
		FAZ.post('banners/preview', payload).then(function (result) {
			if (thisRequestId !== previewRequestId) return; // stale response
			renderPreview(result.html || '', result.styles || '', hiddenTags);
		}).catch(function () {
			if (thisRequestId !== previewRequestId) return;
			pendingPreviewState = null;
			showPreviewMessage('Preview unavailable', 'error');
		});
	}

	function sanitizeHttpUrl(raw, allowRelativePath) {
		if (typeof raw !== 'string') return '';
		var value = raw.trim();
		if (!value) return '';
		try {
			var parsed;
			if (allowRelativePath && value.charAt(0) === '/' && value.charAt(1) !== '/') {
				parsed = new URL(value, window.location.origin);
				if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return '';
				return parsed.pathname + parsed.search + parsed.hash;
			}
			parsed = new URL(value);
			if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return '';
			return parsed.href;
		} catch (_unused) {
			return '';
		}
	}

	function showPreviewMessage(message, type) {
		var el = document.getElementById('faz-b-preview-message');
		if (!el) return;
		if (!message) {
			el.textContent = '';
			el.classList.remove('is-error');
			el.classList.add('is-hidden');
			return;
		}
		el.textContent = message;
		el.classList.toggle('is-error', type === 'error');
		el.classList.remove('is-hidden');
	}

	function clearPreviewLayoutTimers() {
		previewLayoutTimers.forEach(function (timer) {
			window.clearTimeout(timer);
		});
		previewLayoutTimers = [];
	}

	function clampPreviewFrameHeight(height) {
		var numeric = Number(height) || 0;
		if (!numeric) return DEFAULT_PREVIEW_FRAME_HEIGHT;
		return Math.max(MIN_PREVIEW_FRAME_HEIGHT, Math.min(MAX_PREVIEW_FRAME_HEIGHT, Math.ceil(numeric)));
	}

	function syncPreviewSpacer(frameHeight) {
		var spacer = document.getElementById('faz-b-spacer');
		if (!spacer) return;
		var saveBar = document.querySelector('#faz-b-fixed-bottom .faz-save-bar');
		var saveBarHeight = saveBar ? Math.ceil(saveBar.getBoundingClientRect().height) : 80;
		var previewHeight = previewVisible ? clampPreviewFrameHeight(frameHeight || getCurrentPreviewFrameHeight()) : 0;
		spacer.style.height = (saveBarHeight + previewHeight + 20) + 'px';
	}

	function getCurrentPreviewFrameHeight() {
		var frame = document.getElementById('faz-b-preview-frame');
		if (!frame) return DEFAULT_PREVIEW_FRAME_HEIGHT;
		return Math.ceil(frame.getBoundingClientRect().height) || DEFAULT_PREVIEW_FRAME_HEIGHT;
	}

	function setPreviewFrameHeight(height) {
		var frame = document.getElementById('faz-b-preview-frame');
		var host = document.getElementById('faz-b-preview-host');
		var safeHeight = clampPreviewFrameHeight(height);
		if (host) {
			host.style.height = safeHeight + 'px';
			host.style.minHeight = safeHeight + 'px';
		}
		if (frame) {
			frame.style.height = safeHeight + 'px';
			frame.style.minHeight = safeHeight + 'px';
		}
		syncPreviewSpacer(safeHeight);
	}

	function isPreviewNodeVisible(node) {
		if (!node || !node.ownerDocument || !node.ownerDocument.defaultView) return false;
		var style = node.ownerDocument.defaultView.getComputedStyle(node);
		return style.display !== 'none'
			&& style.visibility !== 'hidden'
			&& style.opacity !== '0';
	}

	function measurePreviewFrameHeight() {
		var doc = getPreviewFrameDocument();
		if (!doc) return DEFAULT_PREVIEW_FRAME_HEIGHT;
		var root = doc.getElementById('faz-b-preview-root');
		if (!root) return DEFAULT_PREVIEW_FRAME_HEIGHT;

		var candidates = [
			root.querySelector('.faz-modal.faz-modal-open .faz-preference-center'),
			root.querySelector('.faz-consent-container:not(.faz-hide) .faz-consent-bar'),
			root.querySelector('.faz-consent-container:not(.faz-hide)'),
			root.querySelector('[data-faz-tag="revisit-consent"]:not(.faz-revisit-hide)')
		];

		for (var i = 0; i < candidates.length; i++) {
			var node = candidates[i];
			if (!isPreviewNodeVisible(node)) continue;
			var rect = node.getBoundingClientRect();
			if (rect && rect.height > 0) {
				return rect.height + 12;
			}
		}

		var fallbackHeight = Math.max(
			root.scrollHeight || 0,
			doc.body ? doc.body.scrollHeight || 0 : 0,
			doc.documentElement ? doc.documentElement.scrollHeight || 0 : 0
		);
		return fallbackHeight || DEFAULT_PREVIEW_FRAME_HEIGHT;
	}

	function syncPreviewFrameLayout() {
		if (!previewVisible) {
			syncPreviewSpacer(0);
			return;
		}
		setPreviewFrameHeight(measurePreviewFrameHeight());
	}

	function schedulePreviewFrameLayoutSync(delays) {
		clearPreviewLayoutTimers();
		var waits = Array.isArray(delays) ? delays : [0];
		waits.forEach(function (delay) {
			previewLayoutTimers.push(window.setTimeout(function () {
				syncPreviewFrameLayout();
			}, Math.max(0, Number(delay) || 0)));
		});
	}

	function addPreviewCacheBust(urlString) {
		try {
			var url = new URL(urlString, window.location.origin);
			url.searchParams.set('faz_preview_t', String(Date.now()));
			return url.toString();
		} catch (_unused2) {
			return urlString;
		}
	}

	function getPreviewFrameUrl(forceReload) {
		var raw = (window.fazConfig && fazConfig.site && (fazConfig.site.previewUrl || fazConfig.site.url)) || window.location.origin;
		var url;
		try {
			url = new URL(raw, window.location.origin);
		} catch (_unused3) {
			url = new URL('/', window.location.origin);
		}
		if (!url.searchParams.get('faz_banner_preview')) {
			url.searchParams.set('faz_banner_preview', '1');
		}
		return forceReload ? addPreviewCacheBust(url.toString()) : url.toString();
	}

	function ensurePreviewFrame(forceReload) {
		var frame = document.getElementById('faz-b-preview-frame');
		if (!frame) return null;

		if (!previewFrameHandlersBound) {
			frame.addEventListener('load', function () {
				previewFrameReady = true;
				setPreviewFrameHeight(DEFAULT_PREVIEW_FRAME_HEIGHT);
				renderPreviewIntoFrame();
			});
			frame.addEventListener('error', function () {
				previewFrameReady = false;
				showPreviewMessage('Unable to load the real site preview.', 'error');
			});
			previewFrameHandlersBound = true;
		}

		if (!frame.getAttribute('src') || forceReload) {
			previewFrameReady = false;
			setPreviewFrameHeight(DEFAULT_PREVIEW_FRAME_HEIGHT);
			frame.setAttribute('src', getPreviewFrameUrl(forceReload));
			showPreviewMessage('Loading real site preview...');
		}

		return frame;
	}

	function getPreviewFrameDocument() {
		var frame = document.getElementById('faz-b-preview-frame');
		if (!frame || !previewFrameReady) return null;
		try {
			var doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document);
			return doc && doc.body ? doc : null;
		} catch (_unused4) {
			showPreviewMessage('Preview unavailable: cross-origin restriction.', 'error');
			previewFrameReady = false;
			return null;
		}
	}

	function renderPreview(html, css, hiddenTags) {
		pendingPreviewState = {
			html: html || '',
			css: css || '',
			hiddenTags: hiddenTags || [],
		};
		ensurePreviewFrame(false);
		renderPreviewIntoFrame();
	}

	function renderPreviewIntoFrame() {
		if (!pendingPreviewState) return;

		var doc = getPreviewFrameDocument();
		if (!doc || !doc.body) return;

		var state = pendingPreviewState;
		var parsed = new DOMParser().parseFromString(state.html || '', 'text/html');
		var head = doc.head || doc.getElementsByTagName('head')[0] || doc.body;
		var root = doc.getElementById('faz-b-preview-root');

		if (!root) {
			root = doc.createElement('div');
			root.id = 'faz-b-preview-root';
			doc.body.appendChild(root);
		}
		while (root.firstChild) root.removeChild(root.firstChild);
		var sourceContainer = parsed.querySelector('.faz-consent-container');
		if (!sourceContainer) {
			showPreviewMessage('Preview unavailable', 'error');
			return;
		}
		root.appendChild(doc.importNode ? doc.importNode(sourceContainer, true) : sourceContainer.cloneNode(true));

		var previewStyle = doc.getElementById('faz-preview-css');
		if (!previewStyle) {
			previewStyle = doc.createElement('style');
			previewStyle.id = 'faz-preview-css';
			head.appendChild(previewStyle);
		}
		previewStyle.textContent = String(state.css || '');

		var previewRuntimeStyle = doc.getElementById('faz-preview-runtime');
		if (!previewRuntimeStyle) {
			previewRuntimeStyle = doc.createElement('style');
			previewRuntimeStyle.id = 'faz-preview-runtime';
			head.appendChild(previewRuntimeStyle);
		}
		previewRuntimeStyle.textContent =
			'html,body{margin:0!important;padding:0!important;overflow:hidden!important;background:transparent!important;height:auto!important;min-height:0!important;}' +
			'#faz-b-preview-root{position:relative;z-index:2147483640;display:flex!important;width:100%!important;min-height:0!important;justify-content:flex-start;align-items:flex-start;}' +
			'#faz-b-preview-root[data-faz-preview-type="box"]{padding:0 16px!important;}' +
			'#faz-b-preview-root[data-faz-preview-type="box"][data-faz-preview-position$="right"]{justify-content:flex-end!important;}' +
			'#faz-b-preview-root[data-faz-preview-type="box"][data-faz-preview-position$="left"]{justify-content:flex-start!important;}' +
			'#faz-b-preview-root .faz-consent-container{position:relative!important;top:auto!important;right:auto!important;bottom:auto!important;left:auto!important;inset:auto!important;margin:0!important;z-index:auto!important;}' +
			'#faz-b-preview-root[data-faz-preview-type="banner"] .faz-consent-container,' +
			'#faz-b-preview-root[data-faz-preview-type="classic"] .faz-consent-container{width:100%!important;max-width:none!important;}' +
			'#faz-b-preview-root [data-faz-tag]{visibility:visible!important;}' +
			'#faz-b-preview-root .faz-consent-bar button,' +
			'#faz-b-preview-root .faz-consent-bar a,' +
			'#faz-b-preview-root .faz-category-direct-preview-btn-wrapper .faz-btn,' +
			'#faz-b-preview-root .faz-prefrence-btn-wrapper .faz-btn,' +
			'#faz-b-preview-root .faz-banner-btn-close,' +
			'#faz-b-preview-root .faz-btn-close{pointer-events:none!important;cursor:default!important;}';

		var container = root.querySelector('.faz-consent-container');
		if (!container) {
			showPreviewMessage('Preview unavailable', 'error');
			return;
		}

		var type = getVal('faz-b-type') || 'box';
		var position = getVal('faz-b-position') || 'bottom-right';
		var ptype = getVal('faz-b-pref-type') || 'popup';
		var positionType = type;
		if (type !== 'box' && ptype === 'pushdown') positionType = 'classic';
		var positionForClass = position;
		if (positionType !== 'box') {
			positionForClass = (position.indexOf('top') !== -1) ? 'top' : 'bottom';
		}
		var positionClass = 'faz-' + positionType + '-' + positionForClass;
		root.setAttribute('data-faz-preview-type', positionType);
		root.setAttribute('data-faz-preview-position', position);

		container.classList.add(positionClass);
		container.classList.remove('faz-hide');
		container.style.opacity = '1';
		container.style.visibility = 'visible';

		state.hiddenTags.forEach(function (tag) {
			root.querySelectorAll('[data-faz-tag="' + tag + '"]').forEach(function (el) {
				el.style.display = 'none';
			});
		});

		attachPreviewReadMore(root);

		var logoUrlRaw = (getVal('faz-b-brandlogo-url') || '').trim();
		var logoUrlSafe = '';
		try {
			if (logoUrlRaw) {
				var parsedLogoUrl = new URL(logoUrlRaw, window.location.origin);
				if (parsedLogoUrl.protocol === 'http:' || parsedLogoUrl.protocol === 'https:') {
					logoUrlSafe = parsedLogoUrl.href;
				}
			}
		} catch (_unused5) {
			logoUrlSafe = '';
		}
		if (logoUrlSafe) {
			root.querySelectorAll('[data-faz-tag="brand-logo"] img').forEach(function (img) {
				img.src = logoUrlSafe;
			});
		}

		initPreviewToggles(root);

		var linkColor = getColor('faz-b-link-color') || '#1863DC';
		root.querySelectorAll('.faz-link, a.faz-link, [data-faz-tag="detail"] a, [data-faz-tag="optout-popup"] a, [data-faz-tag="notice"] a').forEach(function (a) {
			a.style.color = linkColor;
			a.style.textDecorationColor = linkColor;
		});
		var dnsColor = getColor('faz-b-donotsell-text') || '#1863DC';
		root.querySelectorAll('[data-faz-tag="donotsell-button"]').forEach(function (el) {
			el.style.color = dnsColor;
			if (el.tagName === 'A') el.style.textDecorationColor = dnsColor;
		});

		syncPreviewFrameLayout();
		schedulePreviewFrameLayoutSync([80, 240]);
		showPreviewMessage('', 'clear');

		var panel = document.getElementById('faz-b-preview-panel');
		if (panel) panel.classList.toggle('hidden', !previewVisible);
	}

	function attachPreviewReadMore(host) {
		if (!bannerData) return;
		var doc = host && host.ownerDocument ? host.ownerDocument : document;
		var config = bannerData.properties && bannerData.properties.config || {};
		var readMoreCfg = config.notice && config.notice.elements
			&& config.notice.elements.buttons && config.notice.elements.buttons.elements
			&& config.notice.elements.buttons.elements.readMore;
		if (!readMoreCfg || readMoreCfg.status !== true) return;

		// Get label text and privacy link for current language
		var contents = bannerData.contents || {};
		var c = contents[currentLang] || contents[Object.keys(contents)[0]] || {};
		var noticeEl = (c.notice && c.notice.elements) || {};
		var label = (noticeEl.buttons && noticeEl.buttons.elements && noticeEl.buttons.elements.readMore) || '';
		var href = (noticeEl.privacyLink || getVal('faz-b-privacy-link') || '').trim() || '/cookie-policy';
		if (!label) return;

		// Build readmore element via DOM API (avoids XSS from unescaped values)
		var el;
		if (readMoreCfg.type === 'link') {
			el = doc.createElement('a');
			var hrefRaw = String(href || '').trim();
			var safeHref = '/cookie-policy';
			try {
				if (hrefRaw) {
					var parsedHref = new URL(hrefRaw, window.location.origin);
					var isHttpHref = parsedHref.protocol === 'http:' || parsedHref.protocol === 'https:';
					var isRelativePath = hrefRaw.charAt(0) === '/' && hrefRaw.charAt(1) !== '/';
					if (isHttpHref) {
						if (isRelativePath && parsedHref.origin === window.location.origin) {
							safeHref = parsedHref.pathname + parsedHref.search + parsedHref.hash;
						} else if (hrefRaw.indexOf('http://') === 0 || hrefRaw.indexOf('https://') === 0) {
							safeHref = parsedHref.href;
						}
					}
				}
			} catch (_unused3) {
				safeHref = '/cookie-policy';
			}
			el.href = safeHref;
			el.target = '_blank';
			el.rel = 'noopener';
		} else {
			el = doc.createElement('button');
		}
		el.className = 'faz-policy';
		el.setAttribute('aria-label', label);
		el.setAttribute('data-faz-tag', 'readmore-button');
		el.textContent = label;

		// Append to description element (same as frontend _fazAttachReadMore)
		var descEl = host.querySelector('[data-faz-tag="description"]');
		if (!descEl) return;
		var lastP = descEl.querySelector('p:last-child');
		var target = lastP || descEl;
		target.appendChild(doc.createTextNode('\u00A0'));
		target.appendChild(el);

		// Apply styles from config
		var styles = readMoreCfg.styles || {};
		var keys = Object.keys(styles);
		host.querySelectorAll('[data-faz-tag="readmore-button"]').forEach(function (rmEl) {
			keys.forEach(function (s) {
				if (styles[s]) rmEl.style[s] = styles[s];
			});
		});
	}

	function initPreviewToggles(host) {
		// Get toggle colors from banner data (preference center toggles)
		var activeColor = '#2563eb';
		var inactiveColor = '#cbd5e1';
		try {
			var toggle =
				bannerData.properties &&
				bannerData.properties.config &&
				bannerData.properties.config.preferenceCenter &&
				bannerData.properties.config.preferenceCenter.toggle;
			if (toggle && toggle.states) {
				var active = toggle.states.active && toggle.states.active.styles;
				var inactive = toggle.states.inactive && toggle.states.inactive.styles;
				activeColor = (active && active['background-color']) || activeColor;
				inactiveColor = (inactive && inactive['background-color']) || inactiveColor;
			}
		} catch (_unused) { /* fallback to defaults */ }

		var disabledColor = '#94a3b8';

		function applyPreviewToggleState(cb, isNecessary, onColor, offColor) {
			cb.checked = true;
			if (isNecessary) {
				cb.disabled = true;
				cb.style.backgroundColor = disabledColor;
				cb.style.opacity = '0.6';
				cb.style.cursor = 'not-allowed';
				return;
			}
			cb.style.backgroundColor = onColor;
			cb.style.pointerEvents = 'auto';
			cb.style.cursor = 'pointer';
			cb.addEventListener('change', function () {
				cb.style.backgroundColor = cb.checked ? onColor : offColor;
			});
		}

		// Preference center toggles
		// NOTE: In admin preview, .faz-always-active is on ALL categories in the
		// template, so we detect "necessary" by element ID instead. If the slug
		// changes or multiple necessary categories are added, update the ID checks below.
		host.querySelectorAll('.faz-switch input[type="checkbox"]').forEach(function (cb) {
			applyPreviewToggleState(cb, cb.id === 'fazSwitchnecessary', activeColor, inactiveColor);
		});

		// Inline category preview toggles (same ID-based detection)
		var catActiveColor = getColor('faz-b-catprev-toggle-active') || activeColor;
		var catInactiveColor = getColor('faz-b-catprev-toggle-inactive') || inactiveColor;
		host.querySelectorAll('input[id^="fazCategoryDirect"]').forEach(function (cb) {
			applyPreviewToggleState(cb, cb.id === 'fazCategoryDirectnecessary', catActiveColor, catInactiveColor);
		});
	}

	// ── Brand Logo Media Uploader ──
	// Cascade: wp.media (WordPress) → FilePond (ClassicPress) → native file input.

	function initBrandLogoUploader() {
		var uploadBtn = document.getElementById('faz-b-brandlogo-upload');
		var removeBtn = document.getElementById('faz-b-brandlogo-remove');
		var fileInput = document.getElementById('faz-b-brandlogo-file');
		var uploadInFlight = false;

		function applyLogoUrl(url) {
			setVal('faz-b-brandlogo-url', url || '');
			updateBrandLogoPreview(url || '');
			showBrandLogoStatus('', 'clear');
			syncFormToBannerData();
			refreshPreview();
		}

		function uploadFile(file, done, pond) {
			if (uploadInFlight) {
				if (typeof done === 'function') done(false);
				return;
			}
			if (!file || !window.fetch || !window.fazConfig || !fazConfig.api || !fazConfig.upload || !fazConfig.upload.mediaEndpoint) {
				showBrandLogoStatus('Upload is not available.', 'error');
				if (fileInput) fileInput.value = '';
				if (typeof done === 'function') done(false);
				return;
			}
			var maxBytes = fazConfig.upload.maxSize || (2 * 1024 * 1024);
			if (file.size > maxBytes) {
				var maxMB = Math.floor(maxBytes / (1024 * 1024));
				showBrandLogoStatus('File too large (max ' + maxMB + ' MB).', 'error');
				if (fileInput) fileInput.value = '';
				if (typeof done === 'function') done(false);
				return;
			}
			uploadInFlight = true;
			showBrandLogoStatus('Uploading logo\u2026');
			window.fetch(fazConfig.upload.mediaEndpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': (fazConfig.api.nonce || ''),
					'Content-Disposition': 'attachment; filename="' + encodeURIComponent(file.name || 'logo') + '"',
					'Content-Type': file.type || 'application/octet-stream',
				},
				body: file,
			}).then(function (response) {
				return response.json().catch(function () { return {}; }).then(function (payload) {
					if (!response.ok) {
						throw new Error((payload && payload.message) || 'Upload failed');
					}
					return payload;
				});
			}).then(function (payload) {
				var url = payload && (payload.source_url || (payload.guid && payload.guid.rendered)) || '';
				if (!url) throw new Error('Upload succeeded but no media URL was returned.');
				applyLogoUrl(url);
				showBrandLogoStatus('Logo uploaded successfully.', 'success');
				if (pond && typeof pond.removeFiles === 'function') pond.removeFiles();
				if (fileInput) fileInput.value = '';
				if (typeof done === 'function') done(true);
			}).catch(function (err) {
				showBrandLogoStatus('Upload failed: ' + (err.message || err), 'error');
				if (fileInput) fileInput.value = '';
				if (typeof done === 'function') done(false);
			}).then(function () {
				uploadInFlight = false;
			});
		}

		if (uploadBtn) {
			uploadBtn.addEventListener('click', function (e) {
				e.preventDefault();
				// 1. WordPress media library (standard WP).
				if (window.wp && window.wp.media && typeof window.wp.media === 'function') {
					var frame = wp.media({
						title: 'Select Brand Logo',
						button: { text: 'Use this image' },
						multiple: false,
						library: { type: 'image' },
					});
					frame.on('select', function () {
						var attachment = frame.state().get('selection').first().toJSON();
						applyLogoUrl(attachment.url || '');
					});
					frame.open();
					return;
				}
				// 2. Fallback: trigger file input (ClassicPress / no media library).
				if (fileInput) {
					fileInput.click();
				} else {
					showBrandLogoStatus('Media library unavailable.', 'error');
				}
			});
		}

		// File input handler — uses FilePond if available, else native.
		if (fileInput) {
			if (window.FilePond && typeof window.FilePond.create === 'function') {
				try {
					var pond = window.FilePond.create(fileInput, {
						allowMultiple: false,
						credits: false,
						acceptedFileTypes: ['image/*'],
						labelIdle: 'Drag & drop a logo image or browse',
					});
					if (pond && typeof pond.on === 'function') {
						pond.on('addfile', function (error, item) {
							if (error || !item || !item.file) return;
							uploadFile(item.file, null, pond);
						});
					}
					} catch (_unused6) {
						fileInput.addEventListener('change', function () {
							if (fileInput.files && fileInput.files[0]) uploadFile(fileInput.files[0]);
						});
					}
			} else {
				fileInput.addEventListener('change', function () {
					if (fileInput.files && fileInput.files[0]) uploadFile(fileInput.files[0]);
				});
			}
		}

		if (removeBtn) {
			removeBtn.addEventListener('click', function (e) {
				e.preventDefault();
				applyLogoUrl('');
				showBrandLogoStatus('', 'clear');
			});
		}
	}

	function showBrandLogoStatus(message, type) {
		var status = document.getElementById('faz-b-brandlogo-upload-status');
		if (!status) return;
		if (!message || type === 'clear') {
			status.textContent = '';
			status.style.display = 'none';
			return;
		}
		status.textContent = message;
		status.style.display = 'block';
		status.style.color = type === 'error' ? '#dc2626' : (type === 'success' ? '#16a34a' : '#64748b');
	}

	function updateBrandLogoPreview(url) {
		var preview = document.getElementById('faz-b-brandlogo-preview');
		var removeBtn = document.getElementById('faz-b-brandlogo-remove');
		var safeUrl = sanitizeHttpUrl(url, false);
		if (preview) {
			if (safeUrl) {
				preview.src = safeUrl;
				preview.style.display = 'block';
				if (removeBtn) removeBtn.style.display = '';
			} else {
				preview.src = '';
				preview.style.display = 'none';
				if (removeBtn) removeBtn.style.display = 'none';
			}
		}
	}

	// ── Helpers ──

	// List of fields that use wp_editor (TinyMCE)
	var wpEditorIds = ['faz-b-notice-desc', 'faz-b-pref-desc'];

	function getVal(id) {
		// For wp_editor fields, read from TinyMCE
		if (wpEditorIds.indexOf(id) > -1 && typeof tinyMCE !== 'undefined') {
			var editor = tinyMCE.get(id);
			if (editor) {
				// TinyMCE on a hidden tab can return empty; guard against it.
				var panel = editor.getContainer();
				if (panel) panel = panel.closest('.faz-tab-panel');
				if (panel && !panel.classList.contains('active')) {
					return undefined; // Signal: field not readable right now.
				}
				return editor.getContent();
			}
		}
		var el = document.getElementById(id);
		return el ? el.value : '';
	}
	function setVal(id, val) {
		val = val !== undefined && val !== null ? val : '';
		// For wp_editor fields, set via TinyMCE
		if (wpEditorIds.indexOf(id) > -1 && typeof tinyMCE !== 'undefined') {
			var editor = tinyMCE.get(id);
			if (editor) { editor.setContent(val); return; }
		}
		var el = document.getElementById(id);
		if (el) el.value = val;
	}
	function setColor(baseId, hex) {
		var picker = document.getElementById(baseId);
		var text = document.getElementById(baseId + '-hex');
		hex = hex || '#000000';
		if (text) text.value = hex;
		// <input type="color"> only accepts #rrggbb format
		if (picker) {
			picker.value = /^#[0-9a-fA-F]{6}$/.test(hex) ? hex : '#FFFFFF';
		}
	}
	function getColor(baseId) {
		var text = document.getElementById(baseId + '-hex');
		return text ? text.value.trim() : '';
	}
	function isChecked(id) {
		var el = document.getElementById(id);
		if (!el) return false;
		var cb = el.querySelector('input[type="checkbox"]');
		return cb ? cb.checked : false;
	}
	function setChecked(id, val) {
		var el = document.getElementById(id);
		if (!el) return;
		var cb = el.querySelector('input[type="checkbox"]');
		if (cb) cb.checked = !!val;
	}
	function getStatus(obj) {
		if (!obj) return false;
		return obj.status === true || obj.status === 'true';
	}
	function ensureObj(obj, path) {
		if (!obj || typeof obj !== 'object' || !path) return;
		var blocked = { '__proto__': true, 'constructor': true, 'prototype': true };
		var keys = path.split('.');
		var cur = obj;
		for (var i = 0; i < keys.length; i++) {
			var key = keys[i];
			if (blocked[key]) return;
			if (!Object.prototype.hasOwnProperty.call(cur, key) || !cur[key] || typeof cur[key] !== 'object') {
				cur[key] = Object.create(null);
			}
			cur = cur[key];
		}
	}

})();
