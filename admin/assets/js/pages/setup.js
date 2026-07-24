/**
 * FAZ Cookie Manager — Guided Setup Wizard page JS.
 *
 * Auto-enqueued as `faz-page-setup` (dependency: faz-admin). Handles step
 * navigation (no reload), the optional quick scan (best-effort, non-blocking),
 * the environment recommendations (cache plugin / Google tags / payment
 * gateways), the review summary, and the Finish call to the onboarding REST
 * endpoint.
 *
 * Depends on: FAZ.ready / FAZ.get / FAZ.post / FAZ.notify (faz-admin.js).
 */
(function () {
	'use strict';

	// i18n helper — looks up fazConfig.i18n.<key> with dot-notation, falls back
	// to the provided English string. Mirrors dashboard.js.
	function __(key, fallback) {
		var parts = key.split('.');
		var obj = (window.fazConfig && window.fazConfig.i18n) || {};
		for (var i = 0; i < parts.length; i++) {
			if (!obj || typeof obj !== 'object') { return fallback; }
			obj = obj[parts[i]];
		}
		return typeof obj === 'string' ? obj : fallback;
	}

	var TOTAL_STEPS = 8;
	var SCAN_STEP = 7;          // the step hosting the scan + payment suggestions
	var TCF_STEP = 5;           // the step whose Next is gated on a valid CMP ID
	var SCAN_POLL_INTERVAL = 3000; // ms between scan-status polls
	var SCAN_POLL_TIMEOUT = 90000; // give up polling after 90s (scan runs on regardless)

	var root, steps, progressItems, backBtn, nextBtn, finishBtn;
	var currentStep = 1;
	var scanPollTimer = null;
	var scanPollDeadline = 0;
	var scanElapsedTimer = null;
	var scanStartedAt = 0;
	var finishing = false;
	var recommendations = null; // last recommendations payload (or null)
	// Checked-state of every persisted toggle at page load, so the review can
	// honestly list ON→OFF transitions ("Being turned off") — a deselection is
	// applied exactly like a selection and must be just as visible.
	var initialToggleState = {};

	FAZ.ready(function () {
		root = document.getElementById('faz-setup');
		if (!root) { return; }

		steps = Array.prototype.slice.call(root.querySelectorAll('.faz-wizard-step'));
		progressItems = Array.prototype.slice.call(root.querySelectorAll('.faz-wizard-progress-item'));
		backBtn = document.getElementById('faz-setup-back');
		nextBtn = document.getElementById('faz-setup-next');
		finishBtn = document.getElementById('faz-setup-finish');

		captureInitialToggleState();
		bindLawSelection();
		bindNavigation();
		bindScan();
		bindTcfFields();
		bindLanguageNote();
		bindCacheInteractionNote();
		loadRecommendations();

		// Initial render: no heading focus — the focus ring on load reads as a
		// stray underline. Focus management only matters on user step changes.
		showStep(1, false);
	});

	function trackedToggleIds() {
		var ids = ['faz-setup-gcm', 'faz-setup-ms-uet', 'faz-setup-ms-clarity', 'faz-setup-tcf', 'faz-setup-geo'];
		root.querySelectorAll('input[data-bc-key]').forEach(function (input) { ids.push(input.id); });
		return ids;
	}

	function captureInitialToggleState() {
		trackedToggleIds().forEach(function (id) {
			var el = document.getElementById(id);
			if (el) { initialToggleState[id] = el.checked; }
		});
	}

	// Language step: reveal the English-fallback note when the selected
	// language ships no bundled banner translation (options carry data-fallback).
	function bindLanguageNote() {
		var select = document.getElementById('faz-setup-lang');
		var note = document.getElementById('faz-setup-lang-fallback-note');
		if (!select || !note) { return; }
		var sync = function () {
			var opt = select.options[select.selectedIndex];
			note.hidden = !(opt && opt.getAttribute('data-fallback'));
		};
		select.addEventListener('change', sync);
		sync();
	}

	// Banner-options step: Cache Compatibility Mode keeps every cached page
	// identical for all visitors, which bypasses the bot-skip promised by
	// hide_from_bots (#158). Surface that interaction the moment both are on.
	function bindCacheInteractionNote() {
		var cache = document.getElementById('faz-setup-bc-cache_compatibility');
		var bots = document.getElementById('faz-setup-bc-hide_from_bots');
		var note = document.getElementById('faz-setup-cache-note');
		if (!cache || !bots || !note) { return; }
		var sync = function () { note.hidden = !(cache.checked && bots.checked); };
		cache.addEventListener('change', sync);
		bots.addEventListener('change', sync);
		sync();
	}

	/* ── Law selection ── */

	function bindLawSelection() {
		root.querySelectorAll('input[name="faz-setup-law"]').forEach(function (input) {
			input.addEventListener('change', function () {
				root.querySelectorAll('.faz-setup-law-card').forEach(function (card) {
					card.classList.remove('is-selected');
				});
				var label = input.closest('.faz-setup-law-card');
				if (label) { label.classList.add('is-selected'); }
			});
		});
	}

	function selectedLaw() {
		var checked = root.querySelector('input[name="faz-setup-law"]:checked');
		return checked ? checked.value : 'gdpr';
	}

	/* ── Navigation ── */

	function bindNavigation() {
		nextBtn.addEventListener('click', function () {
			if (!validateStep(currentStep)) { return; }
			if (currentStep < TOTAL_STEPS) { showStep(currentStep + 1); }
		});
		backBtn.addEventListener('click', function () {
			if (currentStep > 1) { showStep(currentStep - 1); }
		});
		finishBtn.addEventListener('click', finish);
	}

	// The TC-string CmpId field is 12 bits — IDs outside 2..4095 would be
	// silently clamped downstream, signing TC strings attributed to a
	// different CMP. Validated here AND refused server-side.
	function tcfCmpIdInvalid() {
		if (!isChecked('faz-setup-tcf')) { return false; }
		var cmpId = parseInt((document.getElementById('faz-setup-tcf-cmpid') || {}).value, 10);
		return isNaN(cmpId) || cmpId < 2 || cmpId > 4095;
	}

	// publisher_cc must be empty or exactly two letters — anything else would
	// be silently blanked by the server sanitiser while the admin believes it
	// was saved.
	function tcfCcInvalid() {
		var value = ((document.getElementById('faz-setup-tcf-cc') || {}).value || '').trim();
		return value !== '' && !/^[a-z]{2}$/i.test(value);
	}

	// Per-step gate for the Next button. Only the TCF step blocks: a dead or
	// silently-rewritten TCF configuration must never be persisted as if the
	// wizard applied what the admin typed.
	function validateStep(step) {
		if (step !== TCF_STEP) { return true; }
		var cmpInvalid = tcfCmpIdInvalid();
		var ccInvalid = tcfCcInvalid();
		var cmpError = document.getElementById('faz-setup-tcf-error');
		var ccError = document.getElementById('faz-setup-tcf-cc-error');
		if (cmpError) { cmpError.hidden = !cmpInvalid; }
		if (ccError) { ccError.hidden = !ccInvalid; }
		if (cmpInvalid) {
			var field = document.getElementById('faz-setup-tcf-cmpid');
			if (field) { field.focus(); }
		} else if (ccInvalid) {
			var ccField = document.getElementById('faz-setup-tcf-cc');
			if (ccField) { ccField.focus(); }
		}
		return !cmpInvalid && !ccInvalid;
	}

	// Live-clear the TCF inline errors as soon as the inputs become valid.
	function bindTcfFields() {
		['faz-setup-tcf', 'faz-setup-tcf-cmpid', 'faz-setup-tcf-cc'].forEach(function (id) {
			var el = document.getElementById(id);
			if (!el) { return; }
			el.addEventListener('input', clearTcfError);
			el.addEventListener('change', clearTcfError);
		});
	}

	function clearTcfError() {
		var cmpError = document.getElementById('faz-setup-tcf-error');
		var ccError = document.getElementById('faz-setup-tcf-cc-error');
		if (cmpError && !cmpError.hidden && !tcfCmpIdInvalid()) { cmpError.hidden = true; }
		if (ccError && !ccError.hidden && !tcfCcInvalid()) { ccError.hidden = true; }
	}

	function showStep(step, focusHeading) {
		if (typeof focusHeading === 'undefined') { focusHeading = true; }
		// Leaving the scan step stops the elapsed ticker so it can't keep
		// overwriting the status of another step in the background.
		if (currentStep === SCAN_STEP && step !== SCAN_STEP) { stopScanActivity(); }
		currentStep = Math.max(1, Math.min(TOTAL_STEPS, step));

		steps.forEach(function (section) {
			var isCurrent = parseInt(section.getAttribute('data-step'), 10) === currentStep;
			section.hidden = !isCurrent;
			section.classList.toggle('is-active', isCurrent);
		});

		progressItems.forEach(function (item) {
			var n = parseInt(item.getAttribute('data-progress'), 10);
			item.classList.toggle('is-active', n === currentStep);
			item.classList.toggle('is-done', n < currentStep);
		});

		backBtn.hidden = (currentStep === 1);
		nextBtn.hidden = (currentStep === TOTAL_STEPS);
		finishBtn.hidden = (currentStep !== TOTAL_STEPS);

		if (currentStep === TOTAL_STEPS) { renderReview(); }

		// Move focus to the newly-active step's heading so keyboard/screen-reader
		// users get step-change feedback (focus would otherwise remain on a
		// just-hidden Next/Back button). Standard wizard pattern — skipped on
		// the initial render, where a focus ring would be visual noise.
		if (focusHeading) {
			var heading = document.getElementById('faz-setup-step' + currentStep + '-title');
			if (heading) { heading.focus(); }
		}
	}

	/* ── Small DOM helpers ── */

	function isChecked(id) {
		var el = document.getElementById(id);
		return !!(el && el.checked);
	}

	function textOf(el) {
		return el ? el.textContent.trim() : '';
	}

	/* ── Environment recommendations ── */

	// Fetch the read-only environment suggestions (detected cache plugin,
	// Google tags, payment gateways, WooCommerce). Best-effort: a failure just
	// means no badges — every switch stays fully usable manually.
	function loadRecommendations() {
		FAZ.get('settings/onboarding/recommendations').then(function (data) {
			recommendations = data || null;
			applyRecommendations();
		}).catch(function () { /* silent — suggestions are optional */ });
	}

	function applyRecommendations() {
		if (!recommendations) { return; }

		// Cache Compatibility badge (step 3).
		var cacheBadge = document.getElementById('faz-setup-cache-badge');
		if (cacheBadge && recommendations.cache_plugin) {
			cacheBadge.textContent = interpolateStr(
				__('setup.detected_named', 'Detected: %s'),
				recommendations.cache_plugin
			);
			cacheBadge.hidden = false;
		}

		// Google tags badge (step 4).
		var googleBadge = document.getElementById('faz-setup-google-badge');
		if (googleBadge && recommendations.google_tags) {
			googleBadge.textContent = __('setup.detected_google', 'Google tags detected on this site');
			googleBadge.hidden = false;
		}

		renderPayments();
	}

	// Render the payment-gateway suggestions inside the scan step. Freshly
	// DETECTED gateways start unchecked — always-allowing a payment SDK is an
	// informed, explicit admin decision, never a pre-ticked default. Gateways
	// that are ALREADY opted in reflect their stored state (checked), so the
	// wizard never shows "off" while the stored value stays always-allowed —
	// and unticking one genuinely disables it on Finish.
	function renderPayments() {
		var wrap = document.getElementById('faz-setup-payments');
		var list = document.getElementById('faz-setup-payments-list');
		if (!wrap || !list) { return; }

		var gateways = (recommendations && Array.isArray(recommendations.gateways)) ? recommendations.gateways : [];
		if (!gateways.length) { wrap.hidden = true; return; }

		// Preserve user interaction across re-renders (the list refreshes after
		// a scan): a gateway the admin already touched keeps their choice.
		var previouslyChecked = {};
		list.querySelectorAll('input[data-gateway]').forEach(function (input) {
			previouslyChecked[input.getAttribute('data-gateway')] = input.checked;
		});
		while (list.firstChild) { list.removeChild(list.firstChild); }

		gateways.forEach(function (gateway) {
			if (!gateway || !gateway.key) { return; }
			var row = document.createElement('label');
			row.className = 'faz-setup-toggle-row';

			var input = document.createElement('input');
			input.type = 'checkbox';
			input.setAttribute('data-gateway', gateway.key);
			if (gateway.enabled) { input.setAttribute('data-was-enabled', '1'); }
			input.checked = Object.prototype.hasOwnProperty.call(previouslyChecked, gateway.key)
				? previouslyChecked[gateway.key]
				: !!gateway.enabled;

			var body = document.createElement('span');
			body.className = 'faz-setup-toggle-body';

			var label = document.createElement('span');
			label.className = 'faz-setup-toggle-label';
			label.textContent = gateway.label || gateway.key;

			var badge = document.createElement('span');
			badge.className = 'faz-setup-badge';
			badge.textContent = gateway.source === 'scan'
				? __('setup.detected_scan', 'Found by the cookie scan')
				: (gateway.source === 'enabled'
					? __('setup.detected_enabled', 'Currently always allowed')
					: __('setup.detected_plugin', 'Active plugin detected'));
			label.appendChild(document.createTextNode(' '));
			label.appendChild(badge);

			body.appendChild(label);
			row.appendChild(input);
			row.appendChild(body);
			list.appendChild(row);
		});

		var wcNote = document.getElementById('faz-setup-payments-wc-note');
		if (wcNote) { wcNote.hidden = !(recommendations && recommendations.woocommerce); }
		wrap.hidden = false;
	}

	/* ── Quick scan (optional, non-blocking) ── */

	function bindScan() {
		var btn = document.getElementById('faz-setup-scan-btn');
		if (!btn) { return; }
		btn.addEventListener('click', function () {
			startScan(btn);
		});
	}

	function setScanStatus(message) {
		var el = document.getElementById('faz-setup-scan-status');
		if (el) { el.textContent = message || ''; }
	}

	// Animated activity indicator for the server-side scan. The crawl runs in a
	// background process, so there is no reliable per-page signal to poll; an
	// indeterminate bar plus a live elapsed counter shows the scan is alive and
	// moving rather than frozen. Cleared on completion, failure, or step change.
	function startScanActivity() {
		var bar = document.getElementById('faz-setup-scan-progress');
		if (bar) { bar.hidden = false; bar.classList.add('is-scanning'); }
		scanStartedAt = Date.now();
		if (scanElapsedTimer) { clearInterval(scanElapsedTimer); }
		var render = function () {
			var secs = Math.max(0, Math.round((Date.now() - scanStartedAt) / 1000));
			setScanStatus(interpolate(__('setup.scan_running_elapsed', 'Scanning your site… (%ds)'), secs));
		};
		render();
		scanElapsedTimer = setInterval(render, 1000);
	}

	function stopScanActivity() {
		var bar = document.getElementById('faz-setup-scan-progress');
		if (bar) { bar.classList.remove('is-scanning'); bar.hidden = true; }
		if (scanElapsedTimer) { clearInterval(scanElapsedTimer); scanElapsedTimer = null; }
	}

	function startScan(btn) {
		btn.disabled = true;
		setScanStatus(__('setup.scan_starting', 'Starting scan…'));

		FAZ.post('scans', { max_pages: 20 }).then(function (info) {
			// A fresh scan was accepted (status 'scanning') — begin polling.
			pollScan(btn);
		}).catch(function (err) {
			var status = err && (err.data && err.data.status || err.status);
			if (status === 409) {
				// A scan is already running — attach to it rather than double-trigger.
				setScanStatus(__('setup.scan_in_progress', 'A scan is already in progress…'));
				pollScan(btn);
				return;
			}
			// Any other failure is non-fatal: the scan is optional.
			stopScanActivity();
			btn.disabled = false;
			setScanStatus(__('setup.scan_failed', 'The scan could not be started. You can skip this step or run a full scan on the Cookies page.'));
			FAZ.notify(__('setup.scan_failed_notify', 'Cookie scan could not be started.'), 'error');
		});
	}

	function pollScan(btn) {
		if (scanPollTimer) { clearTimeout(scanPollTimer); }
		scanPollDeadline = Date.now() + SCAN_POLL_TIMEOUT;
		startScanActivity();

		var tick = function () {
			FAZ.get('scans/info').then(function (info) {
				var scanStatus = info && info.status;
				if (scanStatus && scanStatus !== 'scanning') {
					var found = (info && typeof info.total_cookies === 'number') ? info.total_cookies : 0;
					// Rows actually ADDED this run; -1 = server state predates the
					// field. "57 found" alone misleads on re-scans, where all 57
					// may already be in the catalogue.
					var added = (info && typeof info.new_cookies === 'number') ? info.new_cookies : -1;
					stopScanActivity();
					var doneMessage;
					if (found <= 0) {
						doneMessage = __('setup.scan_done_empty', 'Scan complete. No new cookies were found.');
					} else if (added > 0) {
						doneMessage = interpolate2(
							__('setup.scan_done_found_new', 'Scan complete — %1$d cookies detected, %2$d new added to your catalogue.'),
							found, added
						);
					} else if (added === 0) {
						doneMessage = interpolate(
							__('setup.scan_done_no_new', 'Scan complete — %d cookies detected, none new (already in your catalogue).'),
							found
						);
					} else {
						doneMessage = interpolate(__('setup.scan_done_found', 'Scan complete — %d cookies found.'), found);
					}
					setScanStatus(doneMessage);
					btn.disabled = false;
					// The scan may have discovered payment-gateway cookies —
					// refresh the suggestions so the payments block reflects them.
					loadRecommendations();
					return;
				}
				if (Date.now() >= scanPollDeadline) {
					// Scan keeps running server-side; stop polling so the wizard
					// stays responsive. Finishing never waited for it anyway.
					stopScanActivity();
					setScanStatus(__('setup.scan_slow', 'The scan is still running in the background. You can finish setup now — results will appear on the Cookies page.'));
					btn.disabled = false;
					return;
				}
				scanPollTimer = setTimeout(tick, SCAN_POLL_INTERVAL);
			}).catch(function () {
				stopScanActivity();
				btn.disabled = false;
				setScanStatus(__('setup.scan_status_error', 'Could not read the scan status. You can finish setup and check the Cookies page later.'));
			});
		};

		scanPollTimer = setTimeout(tick, SCAN_POLL_INTERVAL);
	}

	function interpolate(template, num) {
		return String(template).replace('%d', String(num));
	}

	function interpolate2(template, first, second) {
		return String(template).replace('%1$d', String(first)).replace('%2$d', String(second));
	}

	function interpolateStr(template, value) {
		return String(template).replace('%s', String(value));
	}

	/* ── Option collection ── */

	// Build the optional-steps payload for the finish call. Mirrors the
	// allowlists in Onboarding::apply_options() — anything else is ignored
	// server-side anyway.
	function collectOptions() {
		var options = {};

		var lang = document.getElementById('faz-setup-lang');
		if (lang && lang.value) { options.language = lang.value; }

		var bannerControl = {};
		root.querySelectorAll('input[data-bc-key]').forEach(function (input) {
			bannerControl[input.getAttribute('data-bc-key')] = input.checked;
		});
		options.banner_control = bannerControl;

		options.gcm = { enabled: isChecked('faz-setup-gcm') };
		options.microsoft = {
			uet_consent_mode: isChecked('faz-setup-ms-uet'),
			clarity_consent: isChecked('faz-setup-ms-clarity')
		};

		var cmpId = parseInt((document.getElementById('faz-setup-tcf-cmpid') || {}).value, 10);
		var publisherCc = ((document.getElementById('faz-setup-tcf-cc') || {}).value || '').trim();
		options.iab = {
			enabled: isChecked('faz-setup-tcf'),
			cmp_id: isNaN(cmpId) ? 0 : cmpId,
			publisher_cc: publisherCc
		};

		var regions = [];
		root.querySelectorAll('input[name="faz-setup-geo-region"]:checked').forEach(function (input) {
			regions.push(input.value);
		});
		options.geolocation = {
			geo_targeting: isChecked('faz-setup-geo'),
			target_regions: regions,
			default_behavior: (document.getElementById('faz-setup-geo-behavior') || {}).value || 'show_banner'
		};

		// Explicit state of EVERY gateway the wizard showed, as a { key: bool }
		// map — so unticking a previously opted-in gateway genuinely disables
		// it. Gateways not shown are never touched server-side.
		var gatewayInputs = root.querySelectorAll('#faz-setup-payments-list input[data-gateway]');
		if (gatewayInputs.length) {
			var gatewayMap = {};
			gatewayInputs.forEach(function (input) {
				gatewayMap[input.getAttribute('data-gateway')] = input.checked;
			});
			options.payment_gateways = gatewayMap;
		}

		return options;
	}

	/* ── Review summary ── */

	function renderReview() {
		var list = document.getElementById('faz-setup-review');
		if (!list) { return; }
		while (list.firstChild) { list.removeChild(list.firstChild); }

		var input = root.querySelector('input[name="faz-setup-law"]:checked');
		var card = input ? input.closest('.faz-setup-law-card') : null;
		var titleEl = card ? card.querySelector('.faz-setup-law-title') : null;
		var effectEl = card ? card.querySelector('.faz-setup-law-effect') : null;

		addReviewItem(list, list.getAttribute('data-label-law'), textOf(titleEl));
		addReviewItem(list, list.getAttribute('data-label-effect'), textOf(effectEl));
		if (input && input.getAttribute('data-expiry')) {
			addReviewItem(list, list.getAttribute('data-label-expiry'), input.getAttribute('data-expiry'));
		}
		addReviewItem(list, '', input ? input.getAttribute('data-buttons') : '');

		// Banner language (human label of the selected option).
		var lang = document.getElementById('faz-setup-lang');
		if (lang && lang.selectedIndex >= 0) {
			addReviewItem(list, list.getAttribute('data-label-language'), textOf(lang.options[lang.selectedIndex]));
		}

		// Enabled optional switches, by their visible labels (badges stripped).
		var enabledLabels = [];
		['faz-setup-gcm', 'faz-setup-ms-uet', 'faz-setup-ms-clarity', 'faz-setup-tcf'].forEach(function (id) {
			collectToggleLabel(id, enabledLabels);
		});
		root.querySelectorAll('input[data-bc-key]:checked').forEach(function (input) {
			pushRowLabel(input, enabledLabels);
		});
		if (enabledLabels.length) {
			addReviewItem(list, list.getAttribute('data-label-options'), enabledLabels.join(' · '));
		}

		// ON→OFF transitions: a deselection is persisted exactly like a
		// selection, so anything that was on at load and is now off must be
		// just as visible in the review ("Here is what will be applied").
		var disabledLabels = [];
		trackedToggleIds().forEach(function (id) {
			var el = document.getElementById(id);
			if (el && initialToggleState[id] === true && !el.checked) { pushRowLabel(el, disabledLabels); }
		});
		root.querySelectorAll('#faz-setup-payments-list input[data-was-enabled]').forEach(function (input) {
			if (!input.checked) { pushRowLabel(input, disabledLabels, true); }
		});
		if (disabledLabels.length) {
			addReviewItem(list, list.getAttribute('data-label-disabled'), disabledLabels.join(' · '));
		}

		// Geo targeting summary — always rendered when the toggle is on: the
		// region list (mirroring the server's eu+uk fallback when none are
		// ticked) AND the consequential out-of-region behaviour.
		if (isChecked('faz-setup-geo')) {
			var regionNames = [];
			root.querySelectorAll('input[name="faz-setup-geo-region"]:checked').forEach(function (input) {
				var chip = input.closest('.faz-setup-region-chip');
				if (chip) { regionNames.push(textOf(chip)); }
			});
			var regionText = regionNames.length
				? regionNames.join(', ')
				: list.getAttribute('data-geo-default-regions');
			var behavior = (document.getElementById('faz-setup-geo-behavior') || {}).value === 'no_banner'
				? list.getAttribute('data-geo-others-hidden')
				: list.getAttribute('data-geo-others-shown');
			addReviewItem(list, list.getAttribute('data-label-geo'), regionText + ' — ' + behavior);
		}

		// Payment gateways opted in.
		var gatewayNames = [];
		root.querySelectorAll('#faz-setup-payments-list input[data-gateway]:checked').forEach(function (input) {
			pushRowLabel(input, gatewayNames, true);
		});
		if (gatewayNames.length) {
			addReviewItem(list, list.getAttribute('data-label-payments'), gatewayNames.join(', '));
		}

		// Interaction disclosures (#158): Cache Compatibility Mode forces every
		// cached page to be identical, bypassing the per-visitor variation the
		// admin just configured in other steps. Say it before they confirm.
		if (isChecked('faz-setup-bc-cache_compatibility')) {
			if (isChecked('faz-setup-geo')) {
				addReviewWarning(list, list.getAttribute('data-warn-cache-geo'));
			}
			if (isChecked('faz-setup-tcf')) {
				addReviewWarning(list, list.getAttribute('data-warn-cache-tcf'));
			}
		}

		addReviewItem(list, '', list.getAttribute('data-logging'));
	}

	function addReviewWarning(list, text) {
		if (!text) { return; }
		var li = document.createElement('li');
		li.className = 'faz-setup-review-item faz-setup-review-warning';
		li.appendChild(document.createTextNode(text));
		list.appendChild(li);
	}

	function collectToggleLabel(id, target) {
		var el = document.getElementById(id);
		if (el && el.checked) { pushRowLabel(el, target); }
	}

	// The visible label of a toggle row, minus any detection badge.
	function pushRowLabel(input, target, stripBadge) {
		var row = input.closest('.faz-setup-toggle-row');
		var label = row ? row.querySelector('.faz-setup-toggle-label') : null;
		if (!label) { return; }
		var clone = label.cloneNode(true);
		var badge = clone.querySelector('.faz-setup-badge');
		if (badge) { badge.parentNode.removeChild(badge); }
		var text = clone.textContent.trim();
		if (text) { target.push(text); }
	}

	function addReviewItem(list, label, value) {
		if (!value) { return; }
		var li = document.createElement('li');
		li.className = 'faz-setup-review-item';
		if (label) {
			var strong = document.createElement('strong');
			strong.textContent = label + ': ';
			li.appendChild(strong);
		}
		// textContent — never innerHTML — so translated strings can never inject markup.
		li.appendChild(document.createTextNode(value));
		list.appendChild(li);
	}

	/* ── Finish ── */

	function finish() {
		if (finishing) { return; }
		finishing = true;
		finishBtn.disabled = true;
		backBtn.disabled = true;

		var payload = collectOptions();
		payload.law = selectedLaw();

		FAZ.post('settings/onboarding', payload).then(function (result) {
			if (result && result.warning) {
				// Keep the response contract forward-compatible with advisory notices.
				FAZ.notify(result.warning, 'warning');
			} else {
				FAZ.notify(__('setup.finished', 'Setup complete. Your cookie banner is ready.'), 'success');
			}
			// Brief pause so the toast is visible before navigating.
			setTimeout(function () {
				// Same-directory, constant admin target: no DOM-derived URL reaches a
				// navigation sink (and custom WordPress admin paths keep working).
				window.location.assign('admin.php?page=faz-cookie-manager');
			}, 700);
		}).catch(function (err) {
			finishing = false;
			finishBtn.disabled = false;
			backBtn.disabled = false;
			FAZ.notify((err && err.message) || __('setup.finish_failed', 'Setup could not be saved. Please try again.'), 'error');
		});
	}

})();
