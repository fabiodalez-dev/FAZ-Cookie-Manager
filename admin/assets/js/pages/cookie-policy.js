/**
 * Admin JS — Cookie Policy generator (Spec 002 FR-02 + US-05).
 *
 * DOM-safe construction (createElement + textContent + appendChild) for
 * all dynamic content. No innerHTML for user input or API responses.
 * Preview HTML is server-rendered through wp_kses_post() at the Renderer
 * boundary — we still treat it as trusted-but-sanitized and write via a
 * sandbox iframe so any future regression cannot escape the preview modal.
 */
(function () {
	'use strict';

	var root = document.getElementById('faz-cookie-policy-app');
	if (!root) { return; }

	var REST_URL   = root.dataset.fazRestUrl || '';
	var REST_NONCE = root.dataset.fazRestNonce || '';

	// ---------- i18n helper ----------
	// Strings live in fazConfig.i18n.cookiePolicy.* (see admin/class-admin.php).
	// Fallbacks ship the English default so the page degrades gracefully
	// when the locale array is incomplete.
	var FAZ_I18N = (window.fazConfig && window.fazConfig.i18n && window.fazConfig.i18n.cookiePolicy) || {};
	function t(key, fallback) {
		return (FAZ_I18N && FAZ_I18N[key]) || fallback;
	}

	// Per-request monotonic id used to discard stale preview responses
	// when the user clicks Preview multiple times before the previous
	// fetch resolved.
	var previewRequestId = 0;

	function api(method, path, body) {
		var FAZ = window.FAZ;
		var verb = String(method || 'GET').toUpperCase();
		if (FAZ && typeof FAZ.api === 'function') {
			switch (verb) {
				case 'GET':  return FAZ.get('cookie-policy/' + path);
				case 'POST': return FAZ.post('cookie-policy/' + path, body || {});
			}
		}
		// Defensive fallback for the rare case faz-admin.js hasn't loaded
		// yet (e.g. async script race in the WP admin head). The raw
		// fetch path uses the fully-qualified REST_URL + nonce injected
		// by class-admin.php.
		return fetch(REST_URL + path, {
			method:      verb,
			credentials: 'same-origin',
			headers:     { 'X-WP-Nonce': REST_NONCE, 'Content-Type': 'application/json' },
			body:        body ? JSON.stringify(body) : undefined
		}).then(function (r) {
			if (!r.ok) {
				return r.json().then(function (j) { throw new Error(j.message || 'HTTP ' + r.status); });
			}
			return r.json();
		});
	}

	// ---------- form ↔ settings serialization ----------

	// Dot-path setter: setDeep(obj, "company.name", "ACME").
	// Hardened against prototype pollution via three independent guards:
	//   1. Reject path segments matching the well-known dangerous keys
	//      (__proto__ / prototype / constructor) BEFORE any property access.
	//   2. Use Object.prototype.hasOwnProperty.call() to check whether the
	//      key exists as an own property — never look up the prototype chain.
	//   3. Create intermediate objects with Object.create(null) so they
	//      have NO prototype chain at all; mutating their "__proto__" is a
	//      regular property write that cannot escalate to the global one.
	// Each layer is sufficient on its own; together they satisfy CodeQL's
	// "Prototype-polluting function" rule which flags any dynamic assignment
	// even when guarded by a denylist.
	function isUnsafeKey(k) {
		return k === '__proto__' || k === 'prototype' || k === 'constructor';
	}
	function setDeep(obj, path, value) {
		var parts = path.split('.');
		var cur = obj;
		for (var i = 0; i < parts.length - 1; i++) {
			var key = parts[i];
			if (isUnsafeKey(key)) { return; }
			var existing = Object.prototype.hasOwnProperty.call(cur, key) ? cur[key] : undefined;
			if (typeof existing !== 'object' || existing === null) {
				cur[key] = Object.create(null);
			}
			cur = cur[key];
		}
		var last = parts[parts.length - 1];
		if (isUnsafeKey(last)) { return; }
		cur[last] = value;
	}

	function getDeep(obj, path, fallback) {
		var parts = path.split('.');
		var cur = obj;
		for (var i = 0; i < parts.length; i++) {
			if (cur === null || typeof cur !== 'object') { return fallback; }
			cur = cur[parts[i]];
		}
		return (cur === undefined || cur === null) ? fallback : cur;
	}

	function readForm() {
		var form = document.getElementById('faz-cookie-policy-form');
		var out = {
			third_party_services: []
		};
		// Plain inputs / selects / textareas with name="X.Y"
		form.querySelectorAll('input[name],select[name],textarea[name]').forEach(function (el) {
			if (el.id === 'cp-services-list') { return; }
			var name = el.name;
			if (el.type === 'checkbox') {
				// Service checkboxes use name="third_party_services[]" effectively, value=svc id
				if (el.dataset.serviceId) {
					if (el.checked) { out.third_party_services.push(el.dataset.serviceId); }
					return;
				}
			}
			var v = el.value;
			if (el.type === 'number') { v = parseInt(v, 10); if (isNaN(v)) { v = 0; } }
			setDeep(out, name, v);
		});
		return out;
	}

	function writeForm(settings) {
		// Scalar fields.
		[
			'company.name', 'company.address', 'company.email', 'company.registry',
			'dpo.name', 'dpo.email',
			'jurisdiction', 'retention_months', 'privacy_policy_url', 'default_lang'
		].forEach(function (path) {
			var el = document.querySelector('[name="' + path + '"]');
			if (el) {
				var v = getDeep(settings, path, '');
				el.value = v;
			}
		});
		// Service checkboxes.
		var services = settings.third_party_services || [];
		document.querySelectorAll('#cp-services-list input[type=checkbox]').forEach(function (cb) {
			cb.checked = services.indexOf(cb.dataset.serviceId) !== -1;
		});
	}

	// ---------- services list (renders the checkboxes) ----------

	function renderServicesList() {
		var container = document.getElementById('cp-services-list');
		if (!container) { return; }
		while (container.firstChild) { container.removeChild(container.firstChild); }
		// Service labels are brand names; we still route them through t() so a
		// translator can choose to localize ("Cloudflare" → "Cloudflare", but
		// "Microsoft UET" might need clarification copy in some locales).
		var services = [
			{ id: 'ga4',       label: t( 'svcGa4',       'Google Analytics 4' ) },
			{ id: 'gtm',       label: t( 'svcGtm',       'Google Tag Manager' ) },
			{ id: 'meta',      label: t( 'svcMeta',      'Meta (Facebook) Pixel' ) },
			{ id: 'tiktok',    label: t( 'svcTiktok',    'TikTok Pixel' ) },
			{ id: 'linkedin',  label: t( 'svcLinkedin',  'LinkedIn Insight Tag' ) },
			{ id: 'msuet',     label: t( 'svcMsuet',     'Microsoft UET' ) },
			{ id: 'clarity',   label: t( 'svcClarity',   'Microsoft Clarity' ) },
			{ id: 'cf',        label: t( 'svcCf',        'Cloudflare' ) },
			{ id: 'recaptcha', label: t( 'svcRecaptcha', 'Google reCAPTCHA' ) },
			{ id: 'hotjar',    label: t( 'svcHotjar',    'Hotjar' ) }
		];
		services.forEach(function (svc) {
			var label = document.createElement('label');
			label.style.display = 'inline-block';
			label.style.marginRight = '14px';
			label.style.marginBottom = '6px';
			var cb = document.createElement('input');
			cb.type = 'checkbox';
			cb.dataset.serviceId = svc.id;
			label.appendChild(cb);
			label.appendChild(document.createTextNode(' ' + svc.label));
			container.appendChild(label);
		});
	}

	// ---------- preview modal ----------

	function showPreview(html) {
		var modal = document.getElementById('cp-preview-modal');
		var content = document.getElementById('cp-preview-content');
		// Strongest possible iframe sandbox: empty value disables scripts,
		// forms, top-navigation, popups AND same-origin privileges. Even if
		// a future regression slips XSS past the server-side wp_kses_post
		// boundary, the iframe document cannot reach the admin page DOM,
		// cookies, or REST nonce — it's a fully isolated null-origin frame.
		// srcdoc (instead of doc.write) lets us set the content atomically
		// without needing a setTimeout dance.
		while (content.firstChild) { content.removeChild(content.firstChild); }
		var iframe = document.createElement('iframe');
		iframe.style.cssText = 'width:100%; height:70vh; border:1px solid #ccd0d4; background:#fff;';
		iframe.setAttribute('sandbox', ''); // null sandbox — no scripts, no same-origin, no nothing
		iframe.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><style>' +
			'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; padding:18px; line-height:1.55;}' +
			'.faz-cookie-policy-disclaimer{margin-top:24px;padding:12px 14px;background:#fff5d6;border-left:3px solid #d4a017;font-size:13px;}' +
			'</style></head><body>' + html + '</body></html>';
		content.appendChild(iframe);
		modal.hidden = false;
	}

	function hidePreview() {
		document.getElementById('cp-preview-modal').hidden = true;
	}

	// ---------- wire-up ----------

	function setStatus(msg, kind) {
		var el = document.getElementById('cp-save-status');
		if (!el) { return; }
		el.textContent = msg || '';
		el.style.color = kind === 'error' ? '#c4302b' : (kind === 'ok' ? '#1d7d28' : '');
		if (msg && kind === 'ok') {
			setTimeout(function () { el.textContent = ''; }, 3000);
		}
	}

	function init() {
		renderServicesList();

		api('GET', 'settings')
			.then(function (data) { writeForm(data); })
			.catch(function (err) { setStatus(t( 'loadFailed', 'Load failed' ) + ': ' + err.message, 'error'); });

		document.getElementById('faz-cookie-policy-form').addEventListener('submit', function (e) {
			e.preventDefault();
			var payload = readForm();
			setStatus(t( 'saving', 'Saving…' ), '');
			api('POST', 'settings', payload)
				.then(function () { setStatus(t( 'saved', 'Saved.' ), 'ok'); })
				.catch(function (err) { setStatus(t( 'saveFailed', 'Save failed' ) + ': ' + err.message, 'error'); });
		});

		document.getElementById('cp-preview-btn').addEventListener('click', function () {
			// Race-condition guard: if the user clicks Preview multiple times
			// quickly the responses may resolve out of order. We capture the
			// pre-increment id and only commit results whose id is still the
			// latest. Older responses are silently dropped.
			previewRequestId += 1;
			var myReqId = previewRequestId;
			var payload = readForm();
			api('POST', 'preview', { settings: payload, lang: payload.default_lang || '', jurisdiction: payload.jurisdiction || '' })
				.then(function (resp) {
					if (myReqId !== previewRequestId) { return; } // stale response, drop
					showPreview(resp.html || '');
				})
				.catch(function (err) {
					if (myReqId !== previewRequestId) { return; } // stale error too
					setStatus(t( 'previewFailed', 'Preview failed' ) + ': ' + err.message, 'error');
				});
		});

		var modal = document.getElementById('cp-preview-modal');
		modal.querySelector('.faz-cp-modal-close').addEventListener('click', hidePreview);
		modal.addEventListener('click', function (e) {
			if (e.target === modal) { hidePreview(); }
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !modal.hidden) { hidePreview(); }
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
