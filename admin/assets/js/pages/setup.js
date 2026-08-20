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

	var root, steps, progressItems, backBtn, nextBtn, finishBtn;
	var currentStep = 1;
	var finishing = false;
	var recommendations = null; // last recommendations payload (or null)
	var geoRegionsTouched = false;
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
		bindGeoRegions();
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
				applyLawRegionDefaults(input.value);
			});
		});
	}

	// Until the admin edits the region chips, keep their defaults aligned with
	// the jurisdiction selected in step 1. Once touched, their explicit choice
	// always wins across further law changes.
	function bindGeoRegions() {
		root.querySelectorAll('input[name="faz-setup-geo-region"]').forEach(function (input) {
			input.addEventListener('change', function () { geoRegionsTouched = true; });
		});
	}

	function applyLawRegionDefaults(law) {
		if (geoRegionsTouched) { return; }
		var regionsByLaw = {
			gdpr: ['eu', 'uk'],
			ccpa: ['us'],
			both: ['eu', 'uk', 'us'],
			popia: ['za']
		};
		var selected = regionsByLaw[law] || regionsByLaw.gdpr;
		root.querySelectorAll('input[name="faz-setup-geo-region"]').forEach(function (input) {
			input.checked = selected.indexOf(input.value) !== -1;
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

	/* ── Cookie scan (shared browser engine) ── */

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

	// The scan reports real per-page progress, so the bar is determinate — which
	// means role="progressbar" must carry a value, not just animate.
	function setScanProgress(pct) {
		var value = Math.max(0, Math.min(100, Math.round(pct)));
		var wrap = document.getElementById('faz-setup-scan-progress');
		var bar = wrap ? wrap.querySelector('.faz-setup-scan-bar') : null;
		if (bar) { bar.style.width = value + '%'; }
		if (wrap) {
			wrap.setAttribute('aria-valuenow', String(value));
			wrap.setAttribute('aria-valuemin', '0');
			wrap.setAttribute('aria-valuemax', '100');
		}
	}

	function startScanActivity() {
		var wrap = document.getElementById('faz-setup-scan-progress');
		if (wrap) { wrap.hidden = false; }
		setScanProgress(0);
	}

	// The run currently crawling in this tab, so leaving the step can stop it.
	var activeScanRun = null;

	// Also called on step change, so leaving the scan step tidies the UI.
	//
	// It used to ONLY hide the progress bar: the dispatch loop kept creating
	// iframes invisibly and kept holding the server-side capture lock, so the
	// function's name promised a behaviour it did not implement. Cancelling is
	// cooperative — pages already loading settle and the partial result is still
	// imported, flagged as incomplete coverage.
	function stopScanActivity() {
		var wrap = document.getElementById('faz-setup-scan-progress');
		if (wrap) { wrap.hidden = true; }
		if (activeScanRun && typeof activeScanRun.cancel === 'function') {
			activeScanRun.cancel();
		}
	}

	/**
	 * Run the same browser-based scan the Cookies page runs.
	 *
	 * Deliberately not the server-side `scans` endpoint this step used to call:
	 * a PHP crawl reads Set-Cookie headers only, so it reported a couple of
	 * cookies on sites that actually set dozens — everything written by
	 * Analytics, ad pixels or embeds is invisible to it. The shared engine loads
	 * pages in hidden iframes and therefore observes what a real visitor gets.
	 *
	 * The trade-off is that the crawl now lives in this tab: navigating away
	 * ends it, which the step copy states plainly.
	 */
	function startScan(btn) {
		if (!window.FAZ || !FAZ.scanEngine || typeof FAZ.scanEngine.run !== 'function') {
			// The engine script was blocked (ad blockers match "cookie"/"scan"
			// filenames). Say so rather than failing mutely.
			setScanStatus(__('setup.scan_engine_missing', 'The scanner could not load. You can skip this step and run a full scan on the Cookies page.'));
			return;
		}

		btn.disabled = true;
		// A fresh scan reclaims any held capture session on the server, so a
		// leftover retry offer would 409 the moment it was used. Remove it.
		removeHeldPanel();
		setScanStatus(__('setup.scan_starting', 'Starting scan…'));
		startScanActivity();

		var hooks = {
			status: setScanStatus,
			progress: setScanProgress,
			pages: function () {},
		};

		runScanAttempt(FAZ.scanEngine.run({ maxPages: 20 }, hooks), btn, hooks);
	}

	// Shared by the first run and both retry shapes, so every path re-enables
	// the button, tidies the progress UI, and lands in the same two handlers.
	function runScanAttempt(run, btn, hooks) {
		activeScanRun = run;
		run.then(function (res) {
			activeScanRun = null;
			stopScanActivity();
			btn.disabled = false;
			handleScanSuccess(res);
		}).catch(function (err) {
			activeScanRun = null;
			stopScanActivity();
			btn.disabled = false;
			console.error('[FAZ Setup] Scan failed:', err);
			// The import stage is the only failure worth a second attempt here:
			// the crawl already finished and the server says it kept the
			// evidence (`sessionHeld` is the server's own word, never a guess —
			// offering a retry without it would just 409). Everything else
			// stays the plain, non-blocking failure this optional step has
			// always had.
			if (err && err.sessionHeld === true) {
				offerImportRetry(btn, hooks, err);
				return;
			}
			showTerminalScanFailure(err);
		});
	}

	function showTerminalScanFailure(err) {
		setScanStatus((err && err.message) || __('setup.scan_failed', 'The scan could not be started. You can skip this step or run a full scan on the Cookies page.'));
		FAZ.notify(__('setup.scan_failed_notify', 'Cookie scan could not be started.'), 'error');
	}

	function handleScanSuccess(res) {
		var found = (res && typeof res.total === 'number') ? res.total : 0;
		var doneMessage;
		// `duplicate` means the server answered this submission with the
		// response an EARLIER attempt already produced — nothing was saved
		// twice, so the counts are described as what is already on record
		// rather than as a fresh import.
		if (res && res.importResult && res.importResult.duplicate) {
			doneMessage = __('cookies.scanAlreadySaved', 'Already saved — %1$d cookies on %2$d pages were imported by an earlier attempt. Nothing was saved twice.')
				.replace('%1$d', function () { return String(found); })
				.replace('%2$d', function () { return String((res && typeof res.pagesScanned === 'number') ? res.pagesScanned : 0); });
		} else {
			doneMessage = found > 0
				? interpolate(__('setup.scan_done_found', 'Scan complete — %d cookies found.'), found)
				: __('setup.scan_done_empty', 'Scan complete. No new cookies were found.');
		}
		if (res && res.importResult && res.importResult.enrichment_pending) {
			doneMessage += ' ' + __('setup.scan_enrichment_pending', 'Server-header enrichment continues in the background.');
		}
		setScanStatus(doneMessage);
		// The scan may have discovered payment-gateway cookies — refresh the
		// suggestions so the payments block reflects them.
		loadRecommendations();
	}

	function removeHeldPanel() {
		var panel = document.getElementById('faz-setup-scan-held');
		if (panel && panel.parentNode) { panel.parentNode.removeChild(panel); }
	}

	/**
	 * Offer the save again without blocking the wizard.
	 *
	 * The failure message promises that the results are held for a retry, so
	 * the step has to offer that retry — but the scan is an optional
	 * convenience inside a step-based flow, so the offer is an inline panel in
	 * the scan block only: Next/Back are never touched, the scan button stays
	 * usable, and skipping the step remains at least as easy as retrying.
	 *
	 * The engine hands back `retryImport()` whenever the evidence is held,
	 * which resubmits the payload the finished crawl already built — no
	 * re-crawl. Re-entering the held session by `scanId` is the defensive
	 * fallback: it works, but this browser walks the pages again, and the
	 * button says so instead of pretending the two are alike.
	 */
	function offerImportRetry(btn, hooks, err) {
		var statusEl = document.getElementById('faz-setup-scan-status');
		if (!statusEl || !statusEl.parentNode) {
			showTerminalScanFailure(err);
			return;
		}
		removeHeldPanel();
		setScanStatus(__('cookies.importNotSaved', 'Not saved'));

		var savesOnly = !!(err && typeof err.retryImport === 'function');

		var panel = document.createElement('div');
		panel.id = 'faz-setup-scan-held';
		panel.className = 'faz-scan-held';
		panel.setAttribute('role', 'status');
		panel.setAttribute('aria-live', 'polite');

		var explain = document.createElement('p');
		explain.className = 'faz-scan-held-text';
		explain.textContent = savesOnly
			? __('cookies.importHeldRetrySave', 'The scan finished but the results could not be saved. Nothing is lost — they are held on the server for a few minutes. Retrying saves them; it does not scan the site again.')
			: __('cookies.importHeldRerun', 'The scan finished but the results could not be saved. The capture session is held on the server for a few minutes, so retrying reuses it instead of failing — but this browser has to walk the pages again.');
		panel.appendChild(explain);

		if (err && err.message) {
			var detail = document.createElement('p');
			detail.className = 'faz-scan-held-detail';
			detail.textContent = err.message;
			panel.appendChild(detail);
		}

		var actions = document.createElement('div');
		actions.className = 'faz-scan-held-actions';
		var retryBtn = document.createElement('button');
		retryBtn.type = 'button';
		retryBtn.className = 'faz-btn faz-btn-sm faz-btn-primary';
		retryBtn.textContent = savesOnly
			? __('cookies.retryImport', 'Retry import')
			: __('cookies.retryImportRescan', 'Retry import (re-scans the site)');
		var dismissBtn = document.createElement('button');
		dismissBtn.type = 'button';
		dismissBtn.className = 'faz-btn faz-btn-sm';
		dismissBtn.textContent = __('cookies.discardHeldScan', 'Discard');
		actions.appendChild(retryBtn);
		actions.appendChild(dismissBtn);
		panel.appendChild(actions);
		statusEl.parentNode.insertBefore(panel, statusEl.nextSibling);

		retryBtn.addEventListener('click', function () {
			removeHeldPanel();
			btn.disabled = true;
			setScanStatus(__('cookies.savingResults', 'Saving results...'));
			var attempt;
			if (savesOnly) {
				attempt = err.retryImport();
			} else {
				// Same scan id, so the held session is re-entered rather than
				// 409'd against — but the pages are walked a second time.
				startScanActivity();
				attempt = FAZ.scanEngine.run({ maxPages: 20, scanId: err.scanId }, hooks);
			}
			// Still-held retry failures re-offer the panel; once the evidence
			// is gone the failure becomes the ordinary terminal one.
			runScanAttempt(attempt, btn, hooks);
		});

		dismissBtn.addEventListener('click', function () {
			removeHeldPanel();
			showTerminalScanFailure(err);
		});
	}

	function interpolate(template, num) {
		return String(template).replace('%d', String(num));
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
			if (!input.checked) { pushRowLabel(input, disabledLabels); }
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
			pushRowLabel(input, gatewayNames);
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
	function pushRowLabel(input, target) {
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
