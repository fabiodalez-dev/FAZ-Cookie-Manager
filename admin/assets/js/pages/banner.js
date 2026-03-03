/**
 * FAZ Cookie Manager — Cookie Banner Settings Page JS
 * Loads and saves deeply nested banner settings + per-language contents.
 * Fixed-position live preview + WordPress media uploader for brand logo.
 */
(function () {
	'use strict';

	var bannerId = 1; // default banner
	var bannerData = null; // full API response
	var currentLang = 'en';
	var previewVisible = true;

	FAZ.ready(function () {
		FAZ.tabs('#faz-banner');
		loadBanner();

		document.getElementById('faz-b-save').addEventListener('click', saveBanner);
		document.getElementById('faz-b-refresh-preview').addEventListener('click', function () {
			syncFormToBannerData();
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
				// Adjust spacer height
				var spacer = document.getElementById('faz-b-spacer');
				if (spacer) spacer.style.height = previewVisible ? '320px' : '80px';
			});
		}

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

		// General tab — type is stored directly; legacy data may have banner+pushdown for classic
		var displayType = s.type || 'box';
		if (displayType === 'banner' && s.preferenceCenterType === 'pushdown') {
			displayType = 'classic'; // backward compat: old data stored classic as banner+pushdown
		}
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

		// Determine languages — prefer global config (Languages page) over banner's stale copy
		var globalLangs = (typeof fazConfig !== 'undefined' && fazConfig.languages) || {};
		var langs = (globalLangs.selected && globalLangs.selected.length) ? globalLangs.selected : ((s.languages && s.languages.selected) || ['en']);
		currentLang = globalLangs['default'] || (s.languages && s.languages['default']) || langs[0] || 'en';
		populateLangSelects(langs, currentLang);

		// Colours — notice
		var noticeStyles = (config.notice && config.notice.styles) || {};
		setColor('faz-b-notice-bg', noticeStyles['background-color'] || '#FFFFFF');
		setColor('faz-b-notice-border', noticeStyles['border-color'] || '#F4F4F4');

		var titleStyles = (config.notice && config.notice.elements && config.notice.elements.title && config.notice.elements.title.styles) || {};
		setColor('faz-b-title-color', titleStyles.color || '#1e293b');

		var descStyles = (config.notice && config.notice.elements && config.notice.elements.description && config.notice.elements.description.styles) || {};
		setColor('faz-b-desc-color', descStyles.color || '#64748b');

		// Colours — buttons
		var buttons = (config.notice && config.notice.elements && config.notice.elements.buttons && config.notice.elements.buttons.elements) || {};
		populateButtonColors('accept', buttons.accept);
		populateButtonColors('reject', buttons.reject);
		populateButtonColors('settings', buttons.settings);

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

	function storeCurrentLangContents() {
		if (!bannerData) return;
		var contents = bannerData.contents || {};
		if (!contents[currentLang]) contents[currentLang] = {};
		var c = contents[currentLang];

		// Notice
		if (!c.notice) c.notice = { elements: {} };
		if (!c.notice.elements) c.notice.elements = {};
		c.notice.elements.title = getVal('faz-b-notice-title');
		c.notice.elements.description = getVal('faz-b-notice-desc');
		c.notice.elements.closeButton = getVal('faz-b-close-label');
		if (!c.notice.elements.buttons) c.notice.elements.buttons = { elements: {} };
		if (!c.notice.elements.buttons.elements) c.notice.elements.buttons.elements = {};
		c.notice.elements.buttons.elements.accept = getVal('faz-b-btn-accept-label');
		c.notice.elements.buttons.elements.reject = getVal('faz-b-btn-reject-label');
		c.notice.elements.buttons.elements.settings = getVal('faz-b-btn-settings-label');
		c.notice.elements.buttons.elements.readMore = getVal('faz-b-btn-readmore-label');

		// Cookie policy link (fallback to /cookie-policy if empty)
		var privacyLinkVal = (getVal('faz-b-privacy-link') || '').trim();
		c.notice.elements.privacyLink = privacyLinkVal || '/cookie-policy';

		// Preference center
		// Revisit consent title
		if (!c.revisitConsent) c.revisitConsent = { elements: {} };
		if (!c.revisitConsent.elements) c.revisitConsent.elements = {};
		c.revisitConsent.elements.title = getVal('faz-b-revisit-title');

		if (!c.preferenceCenter) c.preferenceCenter = { elements: {} };
		if (!c.preferenceCenter.elements) c.preferenceCenter.elements = {};
		c.preferenceCenter.elements.title = getVal('faz-b-pref-title');
		c.preferenceCenter.elements.description = getVal('faz-b-pref-desc');
		if (!c.preferenceCenter.elements.buttons) c.preferenceCenter.elements.buttons = { elements: {} };
		if (!c.preferenceCenter.elements.buttons.elements) c.preferenceCenter.elements.buttons.elements = {};
		c.preferenceCenter.elements.buttons.elements.accept = getVal('faz-b-pref-accept');
		c.preferenceCenter.elements.buttons.elements.save = getVal('faz-b-pref-save');
		c.preferenceCenter.elements.buttons.elements.reject = getVal('faz-b-pref-reject');

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

		var btns = (ne.buttons && ne.buttons.elements) || {};
		populateButtonColors('accept', btns.accept);
		populateButtonColors('reject', btns.reject);
		populateButtonColors('settings', btns.settings);

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

	// ── Sync form → bannerData (used by save and live preview) ──

	function syncFormToBannerData() {
		if (!bannerData) return;
		storeCurrentLangContents();

		if (!bannerData.properties || typeof bannerData.properties !== 'object') bannerData.properties = {};
		var props = bannerData.properties;
		if (!props.settings || typeof props.settings !== 'object') props.settings = {};
		if (!props.config || typeof props.config !== 'object') props.config = {};
		if (!props.config.categoryPreview || typeof props.config.categoryPreview !== 'object') props.config.categoryPreview = {};

		// Settings — save type directly; classic is its own type (not banner+pushdown).
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

		// Colours — notice
		ensureObj(props, 'config.notice.styles');
		props.config.notice.styles['background-color'] = getColor('faz-b-notice-bg');
		props.config.notice.styles['border-color'] = getColor('faz-b-notice-border');

		ensureObj(props, 'config.notice.elements.title.styles');
		props.config.notice.elements.title.styles.color = getColor('faz-b-title-color');
		ensureObj(props, 'config.notice.elements.description.styles');
		props.config.notice.elements.description.styles.color = getColor('faz-b-desc-color');

		// Colours + status — buttons
		ensureObj(props, 'config.notice.elements.buttons.elements');
		var btns = props.config.notice.elements.buttons.elements;
		ensureObj(btns, 'accept.styles');
		ensureObj(btns, 'reject.styles');
		ensureObj(btns, 'settings.styles');
		readButtonColors('accept', btns.accept);
		readButtonColors('reject', btns.reject);
		readButtonColors('settings', btns.settings);

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

		FAZ.post('banners/preview', payload).then(function (result) {
			renderPreview(result.html || '', result.styles || '', hiddenTags);
		}).catch(function () {
			host.innerHTML = '<div style="padding:24px;color:#94a3b8;text-align:center;">Preview unavailable</div>';
		});
	}

	function renderPreview(html, css, hiddenTags) {
		var host = document.getElementById('faz-b-preview-host');
		var stylesHost = document.getElementById('faz-b-preview-styles');
		if (!host) return;
		hiddenTags = hiddenTags || [];

		// Determine the position class the frontend would add.
		// Full-width banners only use top/bottom; box uses corner positions.
		var type = getVal('faz-b-type') || 'box';
		var position = getVal('faz-b-position') || 'bottom-right';
		var ptype = getVal('faz-b-pref-type') || 'popup';
		// For the position class, pushdown forces classic layout on frontend.
		var positionType = type;
		if (ptype === 'pushdown') positionType = 'classic';
		var positionForClass = position;
		if (positionType !== 'box') {
			positionForClass = (position.indexOf('top') !== -1) ? 'top' : 'bottom';
		}
		var positionClass = 'faz-' + positionType + '-' + positionForClass;
		// For preview layout CSS, use the actual selected type (not overridden).
		var bannerType = type;

		// Override CSS: render preview inline in the fixed-bottom panel.
		// Only the .faz-consent-container is injected (see above), so CSS
		// overrides are minimal — just position it inline in the panel.
		var overrideCSS =
			'#faz-b-preview-host{' +
			'position:relative;overflow:hidden;min-height:60px;background:none;}' +
			'#faz-b-preview-host .faz-consent-container{' +
			'position:relative!important;' +
			'top:auto!important;bottom:auto!important;left:auto!important;right:auto!important;' +
			'opacity:1!important;visibility:visible!important;z-index:1!important;' +
			'transform:none!important;}' +
			'#faz-b-preview-host .faz-consent-bar button,' +
			'#faz-b-preview-host .faz-consent-bar a{pointer-events:none;cursor:default;}';

		// Box type: keep compact width, use flexbox to show corner positioning.
		if (bannerType === 'box') {
			var justifyVal = 'flex-end';
			if (position === 'bottom-left' || position === 'top-left') justifyVal = 'flex-start';
			overrideCSS +=
				'#faz-b-preview-host{display:flex;justify-content:' + justifyVal + ';' +
				'align-items:flex-end;padding:16px;min-height:120px;}';
		} else {
			// Full-width types (classic, banner): use flexbox to show top/bottom position.
			var vAlign = (positionForClass === 'top') ? 'flex-start' : 'flex-end';
			overrideCSS +=
				'#faz-b-preview-host{display:flex;flex-direction:column;' +
				'justify-content:' + vAlign + ';min-height:140px;}' +
				'#faz-b-preview-host .faz-consent-container{width:100%!important;}';
		}

		// Inject CSS
		if (stylesHost) {
			stylesHost.innerHTML =
				'<style id="faz-preview-css">' + css + '</style>' +
				'<style id="faz-preview-overrides">' + overrideCSS + '</style>';
		}

		// Parse the server-rendered banner template and extract only the consent
		// bar (.faz-consent-container). The full template includes overlay, revisit
		// widget, preference center, and opt-out popup — none needed for preview.
		var tempDiv = document.createElement('div');
		tempDiv.innerHTML = html; // Trusted admin-only content from our own API
		var container = tempDiv.querySelector('.faz-consent-container');
		while (host.firstChild) host.removeChild(host.firstChild);
		if (!container) {
			var fallback = document.createElement('div');
			fallback.className = 'faz-consent-fallback';
			fallback.style.cssText = 'padding:24px;color:#94a3b8;text-align:center;';
			fallback.textContent = 'Preview unavailable';
			host.appendChild(fallback);
			return;
		}
		host.appendChild(container);

		// Add position class to .faz-consent-container
		if (container) {
			container.classList.add(positionClass);
			// Remove hide classes
			container.classList.remove('faz-hide');
			container.style.opacity = '1';
			container.style.visibility = 'visible';
		}

		// Remove hide classes from all descendants
		host.querySelectorAll('.faz-hide,.faz-revisit-hide').forEach(function (el) {
			el.classList.remove('faz-hide', 'faz-revisit-hide');
			el.style.opacity = '1';
			el.style.visibility = 'visible';
		});

		// Hide toggled-off elements
		hiddenTags.forEach(function (tag) {
			host.querySelectorAll('[data-faz-tag="' + tag + '"]').forEach(function (el) {
				el.style.display = 'none';
			});
		});

		// Inject readmore link (not in template — frontend JS adds it dynamically)
		attachPreviewReadMore(host);

		// Update brand logo src from our form field
		var logoUrl = getVal('faz-b-brandlogo-url');
		if (logoUrl) {
			host.querySelectorAll('[data-faz-tag="brand-logo"] img').forEach(function (img) {
				img.setAttribute('src', logoUrl);
			});
		}

		// Initialize category toggle switches (the frontend JS isn't loaded in admin)
		initPreviewToggles(host);

		// Apply display state (panel-level, not host)
		var panel = document.getElementById('faz-b-preview-panel');
		if (panel) panel.classList.toggle('hidden', !previewVisible);
	}

	function attachPreviewReadMore(host) {
		if (!bannerData) return;
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
			el = document.createElement('a');
			el.href = href;
			el.target = '_blank';
			el.rel = 'noopener';
		} else {
			el = document.createElement('button');
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
		target.appendChild(document.createTextNode('\u00A0'));
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

		// Find all switch checkboxes in the preview
		host.querySelectorAll('.faz-switch input[type="checkbox"]').forEach(function (cb) {
			cb.checked = true;
			cb.style.backgroundColor = activeColor;
			cb.style.pointerEvents = 'auto';
			cb.style.cursor = 'pointer';
			cb.addEventListener('change', function () {
				cb.style.backgroundColor = cb.checked ? activeColor : inactiveColor;
			});
		});

		// Category direct preview checkboxes — use category preview colours from form
		var catActiveColor = getColor('faz-b-catprev-toggle-active') || activeColor;
		var catInactiveColor = getColor('faz-b-catprev-toggle-inactive') || inactiveColor;
		host.querySelectorAll('input[id^="fazCategoryDirect"]').forEach(function (cb) {
			cb.checked = true;
			cb.style.backgroundColor = catActiveColor;
			cb.style.pointerEvents = 'auto';
			cb.style.cursor = 'pointer';
			cb.addEventListener('change', function () {
				cb.style.backgroundColor = cb.checked ? catActiveColor : catInactiveColor;
			});
		});
	}

	// ── Brand Logo Media Uploader ──

	function initBrandLogoUploader() {
		var uploadBtn = document.getElementById('faz-b-brandlogo-upload');
		var removeBtn = document.getElementById('faz-b-brandlogo-remove');

		if (uploadBtn) {
			uploadBtn.addEventListener('click', function (e) {
				e.preventDefault();
				// Open WordPress media frame
				var frame = wp.media({
					title: 'Select Brand Logo',
					button: { text: 'Use this image' },
					multiple: false,
					library: { type: 'image' },
				});

				frame.on('select', function () {
					var attachment = frame.state().get('selection').first().toJSON();
					var url = attachment.url;
					setVal('faz-b-brandlogo-url', url);
					updateBrandLogoPreview(url);
					// Trigger preview refresh
					syncFormToBannerData();
					refreshPreview();
				});

				frame.open();
			});
		}

		if (removeBtn) {
			removeBtn.addEventListener('click', function (e) {
				e.preventDefault();
				setVal('faz-b-brandlogo-url', '');
				updateBrandLogoPreview('');
				syncFormToBannerData();
				refreshPreview();
			});
		}
	}

	function updateBrandLogoPreview(url) {
		var preview = document.getElementById('faz-b-brandlogo-preview');
		var removeBtn = document.getElementById('faz-b-brandlogo-remove');
		if (preview) {
			if (url && url !== '#') {
				preview.src = url;
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
			if (editor) return editor.getContent();
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
		// <input type="color"> only accepts #rrggbb format
		if (!/^#[0-9a-fA-F]{6}$/.test(hex)) hex = '#000000';
		if (picker) picker.value = hex;
		if (text) text.value = hex;
	}
	function getColor(baseId) {
		var text = document.getElementById(baseId + '-hex');
		return text ? text.value : '#000000';
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
		var keys = path.split('.');
		var cur = obj;
		for (var i = 0; i < keys.length; i++) {
			if (!cur[keys[i]] || typeof cur[keys[i]] !== 'object') cur[keys[i]] = {};
			cur = cur[keys[i]];
		}
	}

})();
