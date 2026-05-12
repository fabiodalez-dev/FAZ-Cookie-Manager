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
		if (!form || !form.classList.contains('faz-dnsmpi-form')) return;
		e.preventDefault();

		var wrap = form.parentElement;
		if (!wrap) return;
		var notice = wrap.querySelector('.faz-dnsmpi-notice');
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
						notice.textContent =
							normalizeMessage(res.data, config.successMsg || 'Request submitted successfully.');
						form.style.display = 'none';
						notice.tabIndex = -1;
						notice.focus();
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
