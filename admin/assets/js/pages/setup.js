/**
 * FAZ Cookie Manager — Guided Setup Wizard page JS.
 *
 * Auto-enqueued as `faz-page-setup` (dependency: faz-admin). Handles step
 * navigation (no reload), the optional quick scan (best-effort, non-blocking),
 * the review summary, and the Finish call to the onboarding REST endpoint.
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

	var TOTAL_STEPS = 3;
	var SCAN_POLL_INTERVAL = 3000; // ms between scan-status polls
	var SCAN_POLL_TIMEOUT = 90000; // give up polling after 90s (scan runs on regardless)

	var root, steps, progressItems, backBtn, nextBtn, finishBtn;
	var currentStep = 1;
	var scanPollTimer = null;
	var scanPollDeadline = 0;
	var finishing = false;

	FAZ.ready(function () {
		root = document.getElementById('faz-setup');
		if (!root) { return; }

		steps = Array.prototype.slice.call(root.querySelectorAll('.faz-wizard-step'));
		progressItems = Array.prototype.slice.call(root.querySelectorAll('.faz-wizard-progress-item'));
		backBtn = document.getElementById('faz-setup-back');
		nextBtn = document.getElementById('faz-setup-next');
		finishBtn = document.getElementById('faz-setup-finish');

		bindLawSelection();
		bindNavigation();
		bindScan();

		showStep(1);
	});

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
			if (currentStep < TOTAL_STEPS) { showStep(currentStep + 1); }
		});
		backBtn.addEventListener('click', function () {
			if (currentStep > 1) { showStep(currentStep - 1); }
		});
		finishBtn.addEventListener('click', finish);
	}

	function showStep(step) {
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

		addReviewItem(list, list.getAttribute('data-label-law'), titleEl ? titleEl.textContent : '');
		addReviewItem(list, list.getAttribute('data-label-effect'), effectEl ? effectEl.textContent : '');
		if (input && input.getAttribute('data-expiry')) {
			addReviewItem(list, list.getAttribute('data-label-expiry'), input.getAttribute('data-expiry'));
		}
		addReviewItem(list, '', input ? input.getAttribute('data-buttons') : '');
		addReviewItem(list, '', list.getAttribute('data-logging'));
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
			btn.disabled = false;
			setScanStatus(__('setup.scan_failed', 'The scan could not be started. You can skip this step or run a full scan on the Cookies page.'));
			FAZ.notify(__('setup.scan_failed_notify', 'Cookie scan could not be started.'), 'error');
		});
	}

	function pollScan(btn) {
		if (scanPollTimer) { clearTimeout(scanPollTimer); }
		scanPollDeadline = Date.now() + SCAN_POLL_TIMEOUT;
		setScanStatus(__('setup.scan_running', 'Scanning your site… this can take a moment.'));

		var tick = function () {
			FAZ.get('scans/info').then(function (info) {
				var scanStatus = info && info.status;
				if (scanStatus && scanStatus !== 'scanning') {
					var found = (info && typeof info.total_cookies === 'number') ? info.total_cookies : 0;
					setScanStatus(
						found > 0
							? interpolate(__('setup.scan_done_found', 'Scan complete — %d cookies found.'), found)
							: __('setup.scan_done_empty', 'Scan complete. No new cookies were found.')
					);
					btn.disabled = false;
					return;
				}
				if (Date.now() >= scanPollDeadline) {
					// Scan keeps running server-side; stop polling so the wizard
					// stays responsive. Finishing never waited for it anyway.
					setScanStatus(__('setup.scan_slow', 'The scan is still running in the background. You can finish setup now — results will appear on the Cookies page.'));
					btn.disabled = false;
					return;
				}
				scanPollTimer = setTimeout(tick, SCAN_POLL_INTERVAL);
			}).catch(function () {
				btn.disabled = false;
				setScanStatus(__('setup.scan_status_error', 'Could not read the scan status. You can finish setup and check the Cookies page later.'));
			});
		};

		scanPollTimer = setTimeout(tick, SCAN_POLL_INTERVAL);
	}

	function interpolate(template, num) {
		return String(template).replace('%d', String(num));
	}

	/* ── Finish ── */

	function finish() {
		if (finishing) { return; }
		finishing = true;
		finishBtn.disabled = true;
		backBtn.disabled = true;

		var payload = { law: selectedLaw() };

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
		}).catch(function () {
			finishing = false;
			finishBtn.disabled = false;
			backBtn.disabled = false;
			FAZ.notify(__('setup.finish_failed', 'Setup could not be saved. Please try again.'), 'error');
		});
	}

})();
