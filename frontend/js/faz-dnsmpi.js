/**
 * CCPA "Do Not Sell My Personal Information" opt-out form handler.
 *
 * Uses event delegation on the document so it works even when the shortcode
 * HTML is injected into the DOM client-side by page builders (Bricks, etc.).
 * Localized config is provided via fazDnsmpiConfig (wp_localize_script).
 */
(function () {
	'use strict';

	function getConfig() {
		return window.fazDnsmpiConfig || {};
	}

	function normalizeMessage(payload, fallback) {
		if (payload && typeof payload === 'object' && payload.message) {
			return String(payload.message);
		}
		if (typeof payload === 'string' && payload) {
			return payload;
		}
		if (payload && typeof payload === 'object') {
			try {
				return JSON.stringify(payload);
			} catch (e) {}
		}
		return fallback || 'An error occurred. Please try again.';
	}

	function handleSubmit(e) {
		var form = e.target;
		if (!form) return;
		var isOptout  = form.classList && form.classList.contains('faz-dnsmpi-form');
		var isRescind = form.classList && form.classList.contains('faz-dnsmpi-rescind-form');
		if (!isOptout && !isRescind) return;
		e.preventDefault();

		var wrap = form.parentElement;
		if (!wrap) return;
		var notice = wrap.querySelector('.faz-dnsmpi-notice:not(.success)') || wrap.querySelector('.faz-dnsmpi-notice');
		var btn = form.querySelector('button');
		if (btn) btn.disabled = true;
		if (btn) btn.setAttribute('aria-busy', 'true');

		var config = getConfig();
		if (!config.ajaxUrl) {
			if (btn) btn.disabled = false;
			if (btn) btn.setAttribute('aria-busy', 'false');
			if (notice) {
				notice.className = 'faz-dnsmpi-notice error';
				notice.textContent = normalizeMessage('', config.errMsg);
				notice.style.display = 'block';
			}
			return;
		}

		var data = new FormData(form);
		var successFallback = isRescind
			? (config.rescindSuccess || 'Your opt-out has been withdrawn.')
			: (config.successMsg || 'Request submitted successfully.');

		fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (notice) {
					notice.style.display = 'block';
					if (res.success) {
						notice.className = 'faz-dnsmpi-notice success';
						notice.textContent = normalizeMessage(res.data, successFallback);
						form.style.display = 'none';
						notice.tabIndex = -1;
						notice.focus();
						// Rescind succeeded — reload so the page re-renders the opt-out
						// form (server-side state changed: cookie cleared). Delay so
						// screen readers can announce the success notice first.
						if (isRescind) {
							setTimeout(function () { window.location.reload(); }, 800);
						}
					} else {
						notice.className = 'faz-dnsmpi-notice error';
						notice.textContent = normalizeMessage(res.data, config.errMsg);
						form.style.display = 'block';
						if (btn) btn.disabled = false;
						if (btn) btn.setAttribute('aria-busy', 'false');
					}
				}
			})
			.catch(function () {
				if (btn) btn.disabled = false;
				if (btn) btn.setAttribute('aria-busy', 'false');
				form.style.display = 'block';
				if (notice) {
					notice.className = 'faz-dnsmpi-notice error';
					notice.textContent = normalizeMessage('', getConfig().netMsg);
					notice.style.display = 'block';
				}
			});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			document.addEventListener('submit', handleSubmit);
		});
	} else {
		document.addEventListener('submit', handleSubmit);
	}
})();
