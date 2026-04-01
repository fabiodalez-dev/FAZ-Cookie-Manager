/**
 * FAZ Cookie Manager - Cookie Banner Settings Page JS
 * Loads and saves deeply nested banner settings + per-language contents.
 * Fixed-position live preview + WordPress media uploader for brand logo.
 */
(function () {
	'use strict';

	var bannerId = 1; // default banner
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
			populateSettings();
			populateContents(currentLang);
			// Init color pickers after populating values
			FAZ.initColorPickers();
			// Filter position options for current type
			updatePositionOptions();
			ensurePreviewFrame(false);
			// Render live preview
			refreshPreview();
		}).catch(function () {
			FAZ.notify('Failed to load banner settings', 'error');
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
		setColor('faz-b-catprev-save-bg', catSaveBg === 'transparent' ? '#FFFFFF' : catSaveBg);
		setColor('faz-b-catprev-save-border', catSave['border-color'] || '#1863DC');

		// Button toggles
		setChecked('faz-b-accept-toggle', getStatus(buttons.accept));
		setChecked('faz-b-reject-toggle', getStatus(buttons.reject));
		setChecked('faz-b-settings-toggle', getStatus(buttons.settings));
		setChecked('faz-b-readmore-toggle', getStatus(buttons.readMore));

		var closeBtn = (config.notice && config.notice.elements && config.notice.elements.closeButton) || {};
		setChecked('faz-b-close-toggle', typeof closeBtn === 'object' ? getStatus(closeBtn) : true);

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

		// Custom CSS
		var meta = props.meta || {};
		setVal('faz-b-custom-css', meta.customCSS || '');

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
		setColor('faz-b-catprev-save-bg', cpSaveBg === 'transparent' ? '#FFFFFF' : cpSaveBg);
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

		// Re-init color pickers so swatches update
		FAZ.initColorPickers();

		// Sync to bannerData and refresh preview
		syncFormToBannerData();
		refreshPreview();

		FAZ.notify('Preset applied: ' + preset.name, 'success');
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

		// Custom CSS
		if (!props.meta) props.meta = {};
		props.meta.customCSS = getVal('faz-b-custom-css') || '';
	}

	// ── Save ──

	function saveBanner() {
		if (!bannerData) return;
		var btn = document.getElementById('faz-b-save');
		FAZ.btnLoading(btn, true);

		syncFormToBannerData();

		var payload = {
			name: bannerData.name,
			status: bannerData.status,
			'default': bannerData['default'],
			properties: bannerData.properties,
			contents: bannerData.contents,
		};

		FAZ.put('banners/' + bannerId, payload).then(function (updated) {
			bannerData = updated;
			FAZ.btnLoading(btn, false);
			FAZ.notify('Banner settings saved');
			refreshPreview();
		}).catch(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify('Failed to save banner settings', 'error');
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
		FAZ.post('banners/preview', payload).then(function (result) {
			if (thisRequestId !== previewRequestId) return; // stale response
			renderPreview(result.html || '', result.styles || '', hiddenTags);
		}).catch(function () {
			if (thisRequestId !== previewRequestId) return;
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
