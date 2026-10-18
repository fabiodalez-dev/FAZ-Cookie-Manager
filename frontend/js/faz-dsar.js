/**
 * DSAR form submit handler — loaded via wp_enqueue_script so it runs even
 * when a page builder (e.g. Bricks) injects the shortcode HTML dynamically.
 * Inline scripts inside dynamically-injected HTML are silently ignored by
 * browsers, so the handler must live in a separately-enqueued file.
 *
 * The listener is attached directly to each .faz-dsar-form element (not
 * delegated from document) so that page-builder event interceptors that call
 * stopPropagation() at the document level cannot suppress the submit handler.
 * A MutationObserver covers forms added to the DOM after script execution.
 *
 * Data flow:
 *   ajaxUrl / error strings  — window.fazDsarConfig (set by wp_localize_script)
 *   nonce + action           — hidden inputs already in form HTML
 *   form data                — FormData(form) captures all inputs including nonce
 *
 * Fresh nonce and stamp. The markup carries an anonymous nonce (12 to 24 h)
 * and a signed stamp (24 h). A copy a page cache kept longer — a form in a
 * widget, a builder or block template, a synced pattern, an edge cache the
 * server-side veto cannot reach — refused every submission. So when the
 * visitor starts filling the form the script fetches current values from the
 * no-store `refreshAction` (DSAR_Shortcode::handle_refresh()) and writes them
 * over the cached ones; on submit it waits for that refresh, then for the
 * minimum dwell the server counts from the new stamp, then sends. A refresh
 * that fails leaves the markup's values in place, which is what was sent
 * before, and is tried again on the next submit.
 */
(function () {
	// ── Proof of work ────────────────────────────────────────────────────────
	//
	// The form may carry a signed challenge (a salt plus a difficulty) issued
	// by the server. Solving it means finding an integer whose
	// SHA-256(salt + n) begins with `difficulty` hex zeros; the server then
	// spends one hash checking it. Self-contained on purpose: no third-party
	// widget, no asset from a CDN, no key, nothing to consent to. Invisible and
	// therefore accessible — a visual puzzle on a form that exercises GDPR
	// Art. 15-22 could deny the right it exists to protect.
	//
	// SHA-256 is implemented here rather than taken from crypto.subtle because
	// that API is async per digest, and tens of thousands of promises cost far
	// more than the hashing. It is also unavailable on pages served over plain
	// HTTP, where this form must keep working.
	var SHA_K = [
		0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
		0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
		0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
		0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
		0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
		0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
		0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
		0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
	];
	var SHA_W = new Array(64);

	/**
	 * SHA-256 of an array of byte values, as a lowercase hex string.
	 * The challenge inputs are ASCII (alphanumeric salt + decimal digits), so
	 * no UTF-8 encoding step is needed.
	 */
	function sha256Hex(bytes) {
		var len = bytes.length;
		var padded = ((len + 9 + 63) >> 6) << 6;
		var m = new Array(padded);
		var i;
		for (i = 0; i < len; i++) { m[i] = bytes[i]; }
		m[len] = 0x80;
		for (i = len + 1; i < padded - 4; i++) { m[i] = 0; }
		var bits = len * 8;
		m[padded - 4] = (bits >>> 24) & 255;
		m[padded - 3] = (bits >>> 16) & 255;
		m[padded - 2] = (bits >>> 8) & 255;
		m[padded - 1] = bits & 255;

		var h0 = 0x6a09e667, h1 = 0xbb67ae85, h2 = 0x3c6ef372, h3 = 0xa54ff53a;
		var h4 = 0x510e527f, h5 = 0x9b05688c, h6 = 0x1f83d9ab, h7 = 0x5be0cd19;

		for (var off = 0; off < padded; off += 64) {
			for (i = 0; i < 16; i++) {
				var j = off + i * 4;
				SHA_W[i] = (m[j] << 24) | (m[j + 1] << 16) | (m[j + 2] << 8) | m[j + 3];
			}
			for (i = 16; i < 64; i++) {
				var x = SHA_W[i - 15], y = SHA_W[i - 2];
				var s0 = ((x >>> 7) | (x << 25)) ^ ((x >>> 18) | (x << 14)) ^ (x >>> 3);
				var s1 = ((y >>> 17) | (y << 15)) ^ ((y >>> 19) | (y << 13)) ^ (y >>> 10);
				SHA_W[i] = (SHA_W[i - 16] + s0 + SHA_W[i - 7] + s1) | 0;
			}
			var a = h0, b = h1, c = h2, d = h3, e = h4, f = h5, g = h6, h = h7;
			for (i = 0; i < 64; i++) {
				var S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
				var ch = (e & f) ^ (~e & g);
				var t1 = (h + S1 + ch + SHA_K[i] + SHA_W[i]) | 0;
				var S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
				var mj = (a & b) ^ (a & c) ^ (b & c);
				var t2 = (S0 + mj) | 0;
				h = g; g = f; f = e; e = (d + t1) | 0;
				d = c; c = b; b = a; a = (t1 + t2) | 0;
			}
			h0 = (h0 + a) | 0; h1 = (h1 + b) | 0; h2 = (h2 + c) | 0; h3 = (h3 + d) | 0;
			h4 = (h4 + e) | 0; h5 = (h5 + f) | 0; h6 = (h6 + g) | 0; h7 = (h7 + h) | 0;
		}

		var words = [h0, h1, h2, h3, h4, h5, h6, h7];
		var out = '';
		for (i = 0; i < 8; i++) {
			out += ('00000000' + (words[i] >>> 0).toString(16)).slice(-8);
		}
		return out;
	}

	function asciiBytes(str) {
		var out = [];
		for (var i = 0; i < str.length; i++) { out.push(str.charCodeAt(i) & 255); }
		return out;
	}

	/**
	 * Search for an answer to the challenge.
	 *
	 * Bounded by wall clock, not only by iterations: the number of hashes
	 * needed is geometrically distributed, so an unlucky salt can cost many
	 * times the average. Giving up returns '' — and the server ACCEPTS a
	 * submission with no answer, by design, so the worst case of the budget
	 * running out is a submission that still goes through. A form that
	 * exercises a legal right may never be the thing that freezes or refuses.
	 */
	function solvePow(salt, difficulty, budgetMs) {
		var target = '';
		for (var z = 0; z < difficulty; z++) { target += '0'; }
		var saltBytes = asciiBytes(salt);
		var deadline = Date.now() + budgetMs;
		var n = 0;
		while (n < 50000000) {
			var bytes = saltBytes.concat(asciiBytes('' + n));
			if (sha256Hex(bytes).slice(0, difficulty) === target) {
				return '' + n;
			}
			n++;
			// Check the clock every 2048 attempts rather than every one:
			// Date.now() is not free, and the point is a ceiling, not precision.
			if ((n & 2047) === 0 && Date.now() > deadline) {
				return '';
			}
		}
		return '';
	}

	/**
	 * Fill in the answer field, if this form carries a challenge.
	 * Any failure leaves the field empty, which the server accepts.
	 */
	function answerChallenge(form) {
		var saltEl = form.querySelector('[name="faz_pow_salt"]');
		var diffEl = form.querySelector('[name="faz_pow_diff"]');
		var ansEl  = form.querySelector('[name="faz_pow_n"]');
		if (!saltEl || !diffEl || !ansEl) { return; }
		var salt = String(saltEl.value || '');
		var diff = parseInt(diffEl.value, 10);
		if (!salt || !(diff > 0) || ansEl.value) { return; }
		ansEl.value = solvePow(salt, diff, 2000);
	}

	/**
	 * Switch the notice container into "error" mode: role=alert + aria-live=assertive
	 * so assistive tech announces validation/submit failures immediately. Success
	 * messages keep the polite role=status (set in setNoticeSuccess()).
	 */
	function setNoticeError(notice) {
		if (!notice) { return; }
		notice.className = 'faz-dsar-notice error';
		notice.setAttribute('role', 'alert');
		notice.setAttribute('aria-live', 'assertive');
	}

	function setNoticeSuccess(notice) {
		if (!notice) { return; }
		notice.className = 'faz-dsar-notice success';
		notice.setAttribute('role', 'status');
		notice.setAttribute('aria-live', 'polite');
	}

	/**
	 * Remove any prior per-field error markup. Called at the start of every
	 * submit attempt so stale errors do not linger after the user corrects them.
	 */
	function clearFieldErrors(form) {
		if (!form) { return; }
		var inputs = form.querySelectorAll('[aria-invalid="true"]');
		for (var i = 0; i < inputs.length; i++) {
			inputs[i].removeAttribute('aria-invalid');
			var describedBy = inputs[i].getAttribute('aria-describedby') || '';
			// Strip our error IDs (faz-dsar-err-*) but preserve any other describedby tokens.
			var tokens = describedBy.split(/\s+/).filter(function (tok) {
				return tok && tok.indexOf('faz-dsar-err-') !== 0;
			});
			if (tokens.length) {
				inputs[i].setAttribute('aria-describedby', tokens.join(' '));
			} else {
				inputs[i].removeAttribute('aria-describedby');
			}
		}
		var spans = form.querySelectorAll('.faz-field-error');
		for (var j = 0; j < spans.length; j++) {
			spans[j].parentNode.removeChild(spans[j]);
		}
	}

	/**
	 * Mark `input` as invalid: set aria-invalid, render a per-field <span> with
	 * a stable id, and wire it up via aria-describedby so screen readers
	 * announce the message when focus enters the field.
	 */
	function markFieldInvalid(input, message) {
		if (!input) { return; }
		input.setAttribute('aria-invalid', 'true');
		var errId = input.id ? (input.id + '-err') : ('faz-dsar-err-' + Math.random().toString(36).slice(2, 9));
		// Reuse our own id namespace to make cleanup deterministic.
		if (input.id) {
			errId = 'faz-dsar-err-' + input.id;
		}
		var existing = document.getElementById(errId);
		if (existing && existing.parentNode) {
			existing.parentNode.removeChild(existing);
		}
		var span = document.createElement('span');
		span.className = 'faz-field-error';
		span.id = errId;
		span.textContent = message;
		// Insert after the input so it is visually adjacent to the field.
		if (input.parentNode) {
			input.parentNode.insertBefore(span, input.nextSibling);
		}
		var prior = input.getAttribute('aria-describedby');
		var tokens = prior ? prior.split(/\s+/).filter(Boolean) : [];
		if (tokens.indexOf(errId) === -1) {
			tokens.push(errId);
		}
		input.setAttribute('aria-describedby', tokens.join(' '));
	}

	/**
	 * Move keyboard focus and viewport to the first invalid field so the user
	 * can correct it immediately without hunting through the form.
	 */
	function focusFirstInvalid(form) {
		if (!form) { return; }
		var first = form.querySelector('[aria-invalid="true"]');
		if (!first) { return; }
		try {
			first.focus({ preventScroll: true });
		} catch (err) {
			first.focus();
		}
		if (typeof first.scrollIntoView === 'function') {
			try {
				first.scrollIntoView({ behavior: 'smooth', block: 'center' });
			} catch (err) {
				first.scrollIntoView();
			}
		}
	}

	// ── Fresh nonce and stamp ────────────────────────────────────────────────

	function setHidden(form, name, value) {
		var el = form.querySelector('[name="' + name + '"]');
		if (!el) {
			el = document.createElement('input');
			el.type = 'hidden';
			el.name = name;
			form.insertBefore(el, form.firstChild);
		}
		el.value = String(value);
	}

	function dropField(form, name) {
		var el = form.querySelector('[name="' + name + '"]');
		if (el && el.parentNode) { el.parentNode.removeChild(el); }
	}

	/**
	 * Write a refreshed nonce and stamp over the values the markup carried.
	 * Every field the stamp signs is replaced together — issue time, signature,
	 * proof-of-work salt and difficulty — because the server verifies them as
	 * one, and the honeypot is renamed because its name derives from the time.
	 */
	function applyFresh(form, d) {
		setHidden(form, 'nonce', d.nonce);
		if (d.sig) {
			setHidden(form, 'faz_dsar_t', d.t);
			setHidden(form, 'faz_dsar_s', d.sig);
		} else {
			dropField(form, 'faz_dsar_t');
			dropField(form, 'faz_dsar_s');
		}
		if (d.salt && d.diff > 0) {
			setHidden(form, 'faz_pow_salt', d.salt);
			setHidden(form, 'faz_pow_diff', d.diff);
			setHidden(form, 'faz_pow_n', '');
		} else {
			dropField(form, 'faz_pow_salt');
			dropField(form, 'faz_pow_diff');
			dropField(form, 'faz_pow_n');
		}
		var hp = form.querySelector('.faz-dsar-honeypot input');
		if (hp && /^faz_hp_[A-Za-z0-9_]+$/.test(String(d.hp || ''))) {
			hp.name = d.hp;
		}
	}

	/**
	 * Fetch current values once per form; resolves true when they were applied.
	 * Never rejects: a failed refresh leaves the markup's values in place and
	 * clears the memo, so the next submit tries again.
	 */
	function refreshForm(form) {
		if (form._fazDsarRefresh) { return form._fazDsarRefresh; }
		var config = window.fazDsarConfig || {};
		var ajaxUrl = config.ajaxUrl || '';
		if (!ajaxUrl || !config.refreshAction || typeof fetch !== 'function') {
			return Promise.resolve(false);
		}
		var url = ajaxUrl + (ajaxUrl.indexOf('?') < 0 ? '?' : '&') +
			'action=' + encodeURIComponent(config.refreshAction) + '&_=' + Date.now();
		form._fazDsarRefresh = fetch(url, { credentials: 'same-origin', cache: 'no-store' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				var d = res && res.success ? res.data : null;
				if (!d || !d.nonce) { throw new Error('no refresh'); }
				applyFresh(form, d);
				var wait = parseInt(d.wait, 10);
				// The dwell is counted by the server from the new stamp, in whole
				// seconds; a quarter second of margin covers the rounding.
				form._fazDsarReadyAt = Date.now() + (wait > 0 ? wait * 1000 + 250 : 0);
				return true;
			})
			.catch(function () {
				form._fazDsarRefresh = null;
				return false;
			});
		return form._fazDsarRefresh;
	}

	function handleSubmit(e) {
		e.preventDefault();

		var form   = e.currentTarget;
		var wrap   = form.parentElement;
		var notice = wrap ? wrap.querySelector('.faz-dsar-notice') : null;
		var config = window.fazDsarConfig || {};
		var ajaxUrl = config.ajaxUrl || '';
		var errMsg  = config.errMsg  || 'An error occurred. Please try again.';
		var reqMsg  = config.reqMsg  || 'Please fill in all required fields.';

		var nameEl  = form.querySelector('[name="dsar_name"]');
		var emailEl = form.querySelector('[name="dsar_email"]');
		var typeEl  = form.querySelector('[name="dsar_type"]');
		var name    = nameEl  ? nameEl.value.trim()  : '';
		var email   = emailEl ? emailEl.value.trim() : '';
		var type    = typeEl  ? typeEl.value         : '';

		// Reset any prior validation markup before re-evaluating.
		clearFieldErrors(form);

		var missing = [];
		var nameLabel  = config.nameLabel  || 'Name';
		var emailLabel = config.emailLabel || 'Email';
		var typeLabel  = config.typeLabel  || 'Request type';
		if (!name)  {
			missing.push(nameLabel);
			markFieldInvalid(nameEl, reqMsg);
		}
		if (!email) {
			missing.push(emailLabel);
			markFieldInvalid(emailEl, reqMsg);
		}
		if (!type)  {
			missing.push(typeLabel);
			markFieldInvalid(typeEl, reqMsg);
		}
		if (missing.length) {
			if (notice) {
				setNoticeError(notice);
				notice.textContent   = reqMsg + ' Missing: ' + missing.join(', ') + '.';
				notice.style.display = 'block';
			}
			focusFirstInvalid(form);
			return;
		}

		var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
		if (!emailPattern.test(email)) {
			var emailErr = config.emailMsg || 'Please enter a valid email address.';
			markFieldInvalid(emailEl, emailErr);
			if (notice) {
				setNoticeError(notice);
				notice.textContent   = emailErr;
				notice.style.display = 'block';
			}
			focusFirstInvalid(form);
			return;
		}

		var btn = form.querySelector('button');
		if (btn) { btn.disabled = true; }
		if (notice) { notice.style.display = 'none'; }

		// Yield once before solving the proof of work. The search is a tight
		// synchronous loop, so without this the browser would never paint the
		// disabled button and the form would look unresponsive for the duration
		// instead of busy. Everything after the yield is the send.
		//
		// Before that, the fresh nonce and stamp (started when the visitor began
		// filling the form, or now), and the dwell the server counts from them.
		refreshForm(form).then(function () {
			var delay = Math.max(0, (form._fazDsarReadyAt || 0) - Date.now());
			window.setTimeout(function () {
				try {
					answerChallenge(form);
				} catch (err) {
					// No answer is a valid submission: the server accepts one.
				}
				sendSubmission(form, notice, btn, ajaxUrl, errMsg);
			}, delay);
		});
	}

	function sendSubmission(form, notice, btn, ajaxUrl, errMsg) {
		var config = window.fazDsarConfig || {};

		fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: new FormData(form),
		})
		.then(function (r) { return r.json(); })
		.then(function (res) {
			var payload = (res && typeof res.data !== 'undefined') ? res.data : null;
			var payloadMessage = '';
			if (payload && typeof payload === 'object') {
				payloadMessage = payload.message ? String(payload.message) : JSON.stringify(payload);
			} else if (typeof payload === 'string') {
				payloadMessage = payload;
			}
			if (res.success) {
				form.style.display = 'none';
				if (notice) {
					setNoticeSuccess(notice);
					notice.textContent = payloadMessage || config.successMsg || 'Request sent successfully.';
				}
			} else {
				if (notice) {
					setNoticeError(notice);
					notice.textContent = payloadMessage || errMsg;
				}
				if (btn) { btn.disabled = false; }
			}
			if (notice) {
				notice.style.display = 'block';
				notice.focus();
			}
		})
		.catch(function () {
			if (notice) {
				setNoticeError(notice);
				notice.textContent   = errMsg;
				notice.style.display = 'block';
				notice.focus();
			}
			if (btn) { btn.disabled = false; }
		});
	}

	// getFazConsent() is a page global: a missing, stale or replaced runtime
	// can return null or throw. Either used to abort attachToForm() before the
	// submit handler was registered, and the form fell back to a plain browser
	// submit. Only a non-empty string is an identifier worth prefilling.
	function prefillConsentId(form) {
		var field = form.querySelector('[name="dsar_consent_id"]');
		if (!field || field.value) return;
		var consent = null;
		try {
			consent = typeof window.getFazConsent === 'function' ? window.getFazConsent() : null;
		} catch (err) {
			consent = null;
		}
		if (consent && typeof consent.consentID === 'string' && consent.consentID) {
			field.value = consent.consentID;
		}
	}
	['fazcookie_banner_loaded', 'fazcookie_consent_update'].forEach(function (event) {
		document.addEventListener(event, function () {
			document.querySelectorAll('.faz-dsar-form').forEach(prefillConsentId);
		});
	});
	function attachToForm(form) {
		prefillConsentId(form);
		if (form._fazDsarAttached) { return; }
		form._fazDsarAttached = true;
		form.addEventListener('submit', handleSubmit);
		// Start the refresh when the visitor starts on the form, so it is done
		// — and the dwell mostly served — by the time they press Send.
		var start = function () { refreshForm(form); };
		form.addEventListener('focusin', start);
		form.addEventListener('input', start);
		form.addEventListener('pointerdown', start);
	}

	// Attach to any .faz-dsar-form already in the DOM.
	var existing = document.querySelectorAll('.faz-dsar-form');
	for (var i = 0; i < existing.length; i++) {
		attachToForm(existing[i]);
	}

	// Watch for forms injected after script execution (page-builder lazy render).
	if (typeof MutationObserver !== 'undefined') {
		var observer = new MutationObserver(function (mutations) {
			for (var m = 0; m < mutations.length; m++) {
				var added = mutations[m].addedNodes;
				for (var n = 0; n < added.length; n++) {
					var node = added[n];
					if (node.nodeType !== 1) { continue; }
					if (node.classList && node.classList.contains('faz-dsar-form')) {
						attachToForm(node);
					}
					var nested = node.querySelectorAll ? node.querySelectorAll('.faz-dsar-form') : [];
					for (var k = 0; k < nested.length; k++) {
						attachToForm(nested[k]);
					}
				}
			}
		});
		observer.observe(document.body || document.documentElement, {
			childList: true,
			subtree: true,
		});
	}
}());
