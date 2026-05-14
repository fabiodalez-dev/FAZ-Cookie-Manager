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
 */
(function () {
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

	function attachToForm(form) {
		if (form._fazDsarAttached) { return; }
		form._fazDsarAttached = true;
		form.addEventListener('submit', handleSubmit);
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
