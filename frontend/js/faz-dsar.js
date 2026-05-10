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

		if (!name || !email || !type) {
			if (notice) {
				notice.className     = 'faz-dsar-notice error';
				notice.textContent   = reqMsg;
				notice.style.display = 'block';
			}
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
			if (res.success) {
				form.style.display = 'none';
				if (notice) {
					notice.className   = 'faz-dsar-notice success';
					notice.textContent = res.data.message;
				}
			} else {
				if (notice) {
					notice.className   = 'faz-dsar-notice error';
					notice.textContent = res.data || errMsg;
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
				notice.className     = 'faz-dsar-notice error';
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
