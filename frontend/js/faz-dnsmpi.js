/**
 * CCPA "Do Not Sell My Personal Information" opt-out form handler.
 *
 * Uses event delegation on the document so it works even when the shortcode
 * HTML is injected into the DOM client-side by page builders (Bricks, etc.).
 * Localized config is provided via fazDnsmpiConfig (wp_localize_script).
 *
 * Two jobs belong to the client because the page is cacheable and the server
 * must not read anything about the visitor (see Do_Not_Sell_Shortcode::render()):
 *
 *   - which state the visitor sees. The server renders the opt-out form live
 *     and the "already opted out / withdraw" state inert in a <template>;
 *     applyState() swaps the template in when document.cookie carries the
 *     opt-out cookie, which is the visitor's own and never comes from a cache.
 *     The DOM holds exactly one state at any time;
 *   - a nonce baked into a cached page expires after 12 to 24 hours, and
 *     reloading returns the same page. When the server refuses with the
 *     `stale_nonce` code, the handler fetches fresh nonces from the no-store
 *     refresh action and resends ONCE, so the visitor's click still lands.
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

	// ── State selection ─────────────────────────────────────────────────────

	/**
	 * Whether this visitor carries the opt-out cookie: exactly `name=1`, the
	 * same test the server applies (`'1' === $_COOKIE[...]`), so a look-alike
	 * name or another value does not count.
	 */
	function hasOptoutCookie() {
		var needle = (getConfig().cookieName || 'fazcookie-dnsmpi') + '=1';
		var parts = (document.cookie || '').split(';');
		for (var i = 0; i < parts.length; i++) {
			if (parts[i].replace(/^\s+|\s+$/g, '') === needle) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Show the withdraw state to a visitor who has already opted out.
	 *
	 * The server rendered the opt-out form live — the control the law
	 * requires, and the right one for everybody without the cookie — and the
	 * rescind state inside a <template>. For a visitor with the cookie the
	 * template's content replaces the live state, so the DOM still holds one
	 * state and every existing selector keeps meaning what it meant.
	 */
	function applyState(wrap) {
		if (!wrap || wrap._fazDnsmpiStateApplied) { return; }
		var tpl  = wrap.querySelector('template.faz-dnsmpi-rescind-template');
		var live = wrap.querySelector('.faz-dnsmpi-state');
		if (!tpl || !live || !live.parentNode) { return; }
		wrap._fazDnsmpiStateApplied = true;
		if (!hasOptoutCookie()) { return; }

		var node = null;
		if (tpl.content && tpl.content.firstElementChild) {
			node = document.importNode(tpl.content.firstElementChild, true);
		} else {
			// A browser without template support parsed the children as
			// ordinary nodes; take the first element among them.
			var child = tpl.firstChild;
			while (child && child.nodeType !== 1) { child = child.nextSibling; }
			node = child ? child.cloneNode(true) : null;
		}
		if (!node) { return; }
		live.parentNode.replaceChild(node, live);
	}

	function applyStates(root) {
		if (!root || !root.querySelectorAll) { return; }
		if (root.classList && root.classList.contains('faz-dnsmpi-wrap')) {
			applyState(root);
		}
		var wraps = root.querySelectorAll('.faz-dnsmpi-wrap');
		for (var i = 0; i < wraps.length; i++) {
			applyState(wraps[i]);
		}
	}

	// ── Nonce refresh ───────────────────────────────────────────────────────

	function isStaleNonce(res) {
		return !!(res && res.success === false && res.data && typeof res.data === 'object' && res.data.code === 'stale_nonce');
	}

	/**
	 * Fetch fresh nonces from the read-only refresh action. Resolves with
	 * { optout, rescind } or rejects; the caller shows the original message
	 * on rejection, so a refresh that fails costs nothing a stale page did
	 * not already cost.
	 */
	function refreshNonces(config) {
		var action = config.nonceAction || 'faz_dnsmpi_nonce';
		var url = config.ajaxUrl + (config.ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'action=' + encodeURIComponent(action);
		return fetch(url, { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success || !res.data || typeof res.data !== 'object') {
					throw new Error('nonce refresh refused');
				}
				return res.data;
			});
	}

	function setNonce(container, value) {
		if (!container || !value) { return; }
		var inputs = container.querySelectorAll('input[name="nonce"]');
		for (var i = 0; i < inputs.length; i++) {
			inputs[i].value = value;
		}
	}

	/**
	 * Write fresh nonces into the live form AND the inert template, so the
	 * state a later swap brings in is as fresh as the one on screen.
	 */
	function applyNonces(form, isRescind, nonces) {
		setNonce(form, isRescind ? nonces.rescind : nonces.optout);
		var wrap = form.parentElement ? form.parentElement.parentElement : null;
		if (!wrap || !wrap.querySelector) { return; }
		var tpl = wrap.querySelector('template.faz-dnsmpi-rescind-template');
		if (!tpl) { return; }
		var root = tpl.content || tpl;
		var tplForms = root.querySelectorAll ? root.querySelectorAll('form') : [];
		for (var i = 0; i < tplForms.length; i++) {
			var rescindTpl = tplForms[i].classList && tplForms[i].classList.contains('faz-dnsmpi-rescind-form');
			setNonce(tplForms[i], rescindTpl ? nonces.rescind : nonces.optout);
		}
	}

	// ── Submit ──────────────────────────────────────────────────────────────

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

		var successFallback = isRescind
			? (config.rescindSuccess || 'Your opt-out has been withdrawn.')
			: (config.successMsg || 'Request submitted successfully.');

		function showError(payload) {
			if (notice) {
				// Failure: assertive announcement.
				notice.setAttribute('role', 'alert');
				notice.setAttribute('aria-live', 'assertive');
				notice.className = 'faz-dnsmpi-notice error';
				notice.textContent = normalizeMessage(payload, config.errMsg);
				notice.style.display = 'block';
			}
			form.style.display = 'block';
			if (btn) btn.disabled = false;
			if (btn) btn.setAttribute('aria-busy', 'false');
			releaseSentinel();
		}

		function showSuccess(payload) {
			if (!notice) { return; }
			notice.style.display = 'block';
			// Success: reset to role=status (polite) so the
			// confirmation doesn't bark in user's ears.
			notice.setAttribute('role', 'status');
			notice.setAttribute('aria-live', 'polite');
			notice.className = 'faz-dnsmpi-notice success';
			notice.textContent = normalizeMessage(payload, successFallback);
			form.style.display = 'none';
			notice.tabIndex = -1;
			notice.focus();
			// Reload after either action so the server-side state change
			// applies immediately on this page, not just on the next navigation:
			// a rescind clears the opt-out cookie; an opt-out sets it, and
			// get_blocked_categories() then blocks the sell/share scripts on the
			// reloaded response — and applyState() shows the state the new
			// cookie calls for. Delayed so screen readers can announce the
			// success notice first.
			setTimeout(function () { window.location.reload(); }, 800);
		}

		/**
		 * Post the form. `retried` is true on the one resend that follows a
		 * nonce refresh: a second stale_nonce after that is shown, not
		 * retried, so a server that keeps refusing is never hammered.
		 */
		function send(retried) {
			return fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: new FormData(form),
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.success) {
						showSuccess(res.data);
						return;
					}
					if (!retried && isStaleNonce(res)) {
						// The nonce in the (cached) markup has expired. Get a
						// fresh one and send the same click again; if that
						// fails for any reason, the original message stands.
						return refreshNonces(config)
							.then(function (nonces) {
								applyNonces(form, isRescind, nonces);
								return send(true);
							})
							.catch(function () {
								showError(res ? res.data : null);
							});
					}
					showError(res ? res.data : null);
				});
		}

		send(false).catch(function () {
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

	// ── Bootstrap ───────────────────────────────────────────────────────────

	function init() {
		document.addEventListener('submit', handleSubmit);
		applyStates(document);

		// Shortcode markup a page builder injects after load gets the same
		// treatment: the server could not pick the state, and neither could
		// the pass above.
		if (typeof MutationObserver !== 'undefined') {
			var observer = new MutationObserver(function (mutations) {
				for (var m = 0; m < mutations.length; m++) {
					var added = mutations[m].addedNodes;
					for (var n = 0; n < added.length; n++) {
						if (added[n].nodeType === 1) {
							applyStates(added[n]);
						}
					}
				}
			});
			observer.observe(document.body || document.documentElement, {
				childList: true,
				subtree: true,
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
