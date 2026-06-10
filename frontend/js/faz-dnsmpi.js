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

		// Per-form sentinel guards against double-fire: if the script is
		// enqueued twice (e.g. an AJAX-loaded widget brings a second copy of
		// the asset) the document-level submit listener also fires twice for
		// the same submit, and the second fetch() trips the per-IP add_option
		// lock — the user sees "Too many requests" after having successfully
		// opted out on the first request. Mirrors faz-dsar.js:_fazDsarAttached.
		if (form._fazDnsmpiAttached) {
			e.preventDefault();
			return;
		}
		form._fazDnsmpiAttached = true;
		e.preventDefault();

		var wrap = form.parentElement;
		if (!wrap) return;
		var notice = wrap.querySelector('.faz-dnsmpi-notice:not(.success)') || wrap.querySelector('.faz-dnsmpi-notice');
		var btn = form.querySelector('button');
		if (btn) btn.disabled = true;
		if (btn) btn.setAttribute('aria-busy', 'true');

		// On every error path below we'll release the sentinel so the user
		// can retry after fixing whatever was wrong (network drop, server
		// error, validation rejection). Success path leaves it true — the
		// form is hidden anyway and any subsequent submit would be on stale
		// state.
		function releaseSentinel() { form._fazDnsmpiAttached = false; }

		var config = getConfig();
		if (!config.ajaxUrl) {
			if (btn) btn.disabled = false;
			if (btn) btn.setAttribute('aria-busy', 'false');
			if (notice) {
				// Switch the live-region to role=alert so screen readers
				// announce the error assertively (the static markup ships with
				// role=status / aria-live=polite, which queues behind the
				// current utterance and may be missed entirely on a submit
				// that errored out immediately). Mirrors setNoticeError() in
				// faz-dsar.js.
				notice.setAttribute('role', 'alert');
				notice.setAttribute('aria-live', 'assertive');
				notice.className = 'faz-dnsmpi-notice error';
				notice.textContent = normalizeMessage('', config.errMsg);
				notice.style.display = 'block';
			}
			releaseSentinel();
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
						// Success: reset to role=status (polite) so the
						// confirmation doesn't bark in user's ears.
						notice.setAttribute('role', 'status');
						notice.setAttribute('aria-live', 'polite');
						notice.className = 'faz-dnsmpi-notice success';
						notice.textContent = normalizeMessage(res.data, successFallback);
						form.style.display = 'none';
						notice.tabIndex = -1;
						notice.focus();
						// Rescind succeeded — reload so the page re-renders the opt-out
						// form (server-side state changed: cookie cleared). Delay so
						// screen readers can announce the success notice first.
						// Reload after either action so the server-side state change
						// applies immediately on this page, not just on the next navigation:
						// a rescind clears the opt-out cookie; an opt-out sets it, and
						// get_blocked_categories() then blocks the sell/share scripts on the
						// reloaded response.
						setTimeout(function () { window.location.reload(); }, 800);
					} else {
						// Failure: assertive announcement.
						notice.setAttribute('role', 'alert');
						notice.setAttribute('aria-live', 'assertive');
						notice.className = 'faz-dnsmpi-notice error';
						notice.textContent = normalizeMessage(res.data, config.errMsg);
						form.style.display = 'block';
						if (btn) btn.disabled = false;
						if (btn) btn.setAttribute('aria-busy', 'false');
						releaseSentinel();
					}
				}
			})
			.catch(function () {
				if (btn) btn.disabled = false;
				if (btn) btn.setAttribute('aria-busy', 'false');
				form.style.display = 'block';
				if (notice) {
					notice.setAttribute('role', 'alert');
					notice.setAttribute('aria-live', 'assertive');
					notice.className = 'faz-dnsmpi-notice error';
					notice.textContent = normalizeMessage('', getConfig().netMsg);
					notice.style.display = 'block';
				}
				releaseSentinel();
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
