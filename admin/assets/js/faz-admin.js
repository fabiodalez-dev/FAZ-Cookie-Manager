/**
 * FAZ Cookie Manager — Admin JS Utilities
 *
 * Core functions for the server-rendered admin pages.
 * Depends on: wp.apiFetch (WordPress), fazConfig (localized data)
 */
(function (window) {
	'use strict';

	var FAZ = window.FAZ || {};

	// ── API wrapper ──────────────────────────────────────────
	// Uses wp.apiFetch which handles nonce + base URL automatically
	FAZ.api = function (method, endpoint, data) {
		var opts = {
			path: 'faz/v1/' + endpoint.replace(/^\//, ''),
			method: method.toUpperCase(),
		};
		if (data && (opts.method === 'POST' || opts.method === 'PUT' || opts.method === 'PATCH')) {
			opts.data = data;
		}
		if (data && opts.method === 'GET') {
			var params = [];
			Object.keys(data).forEach(function (k) {
				if (data[k] !== undefined && data[k] !== null) {
					params.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k]));
				}
			});
			if (params.length) {
				opts.path += (opts.path.indexOf('?') > -1 ? '&' : '?') + params.join('&');
			}
		}
		return wp.apiFetch(opts);
	};

	FAZ.get = function (endpoint, params) { return FAZ.api('GET', endpoint, params); };
	FAZ.post = function (endpoint, data) { return FAZ.api('POST', endpoint, data); };
	FAZ.put = function (endpoint, data) { return FAZ.api('PUT', endpoint, data); };
	FAZ.del = function (endpoint) { return FAZ.api('DELETE', endpoint); };

	// GET with response headers (for paginated endpoints)
	FAZ.getWithHeaders = function (endpoint, params) {
		var path = 'faz/v1/' + endpoint.replace(/^\//, '');
		if (params) {
			var qs = [];
			Object.keys(params).forEach(function (k) {
				if (params[k] !== undefined && params[k] !== null && params[k] !== '') {
					qs.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
				}
			});
			if (qs.length) path += (path.indexOf('?') > -1 ? '&' : '?') + qs.join('&');
		}
		return wp.apiFetch({ path: path, method: 'GET', parse: false }).then(function (response) {
			return response.json().then(function (data) {
				return {
					data: data,
					total: parseInt(response.headers.get('X-WP-Total') || '0', 10),
					pages: parseInt(response.headers.get('X-WP-TotalPages') || '1', 10),
				};
			});
		});
	};

	// ── Locale ───────────────────────────────────────────────
	/**
	 * The admin's locale as a tag the Intl APIs actually accept.
	 *
	 * fazConfig.locale is the WordPress user locale, and WordPress does not
	 * speak BCP 47: it writes 'de_DE' with an underscore, and ships variants
	 * such as 'pt_PT_ao90' whose last subtag is not a well-formed variant.
	 * toLocaleDateString() answers either with a RangeError — and a throw
	 * inside a .then() arrives at the .catch() written for the request, so
	 * the page reports that it could not load data it had already received.
	 * That is exactly how the consent log page broke in 1.30.0 (issue #284).
	 *
	 * Four pages had grown their own copy of this helper and two had dropped
	 * the conversion, so it lives here now, once. Degrade rather than throw:
	 * full tag, then language-region, then language, then undefined, which
	 * tells Intl to use the runtime default.
	 *
	 * @returns {string|undefined} A tag Intl accepts, or undefined.
	 */
	FAZ.locale = function () {
		var raw = (window.fazConfig && window.fazConfig.locale) ||
			(document.documentElement && document.documentElement.lang) || '';
		if (!raw || typeof raw !== 'string') return undefined;
		var tag = raw.replace(/_/g, '-');
		var parts = tag.split('-');
		var candidates = [tag];
		if (parts.length > 2) candidates.push(parts.slice(0, 2).join('-'));
		if (parts.length > 1) candidates.push(parts[0]);
		for (var i = 0; i < candidates.length; i++) {
			try {
				new Intl.DateTimeFormat(candidates[i]);
				return candidates[i];
			} catch (e) { /* try the next, shorter, candidate */ }
		}
		return undefined;
	};

	// ── Tabs ─────────────────────────────────────────────────
	FAZ.tabs = function (container) {
		if (typeof container === 'string') container = document.querySelector(container);
		if (!container) return;
		var tabBtns = container.querySelectorAll('.faz-tab');
		var panels = container.querySelectorAll('.faz-tab-panel');

		function activate(id) {
			tabBtns.forEach(function (b) {
				b.classList.toggle('active', b.dataset.tab === id);
			});
			panels.forEach(function (p) {
				p.classList.toggle('active', p.id === 'tab-' + id);
			});
		}

		tabBtns.forEach(function (btn) {
			btn.addEventListener('click', function () {
				activate(btn.dataset.tab);
			});
		});

		if (tabBtns.length && !container.querySelector('.faz-tab.active')) {
			activate(tabBtns[0].dataset.tab);
		}
	};

	// ── Toggle switches ──────────────────────────────────────
	FAZ.toggle = function (el, callback) {
		if (typeof el === 'string') el = document.querySelector(el);
		if (!el) return;
		var checkbox = el.querySelector('input[type="checkbox"]');
		if (!checkbox) return;
		checkbox.addEventListener('change', function () {
			if (callback) callback(checkbox.checked, el);
		});
	};

	FAZ.initToggles = function () {
		document.querySelectorAll('.faz-toggle[data-field]').forEach(function () {
			// individual pages bind their own save logic
		});
	};

	// ── Modal ────────────────────────────────────────────────
	FAZ.modal = function (options) {
		var opts = Object.assign({
			title: '',
			body: '',
			size: '',
			footer: null,
			onClose: null,
		}, options);

		var backdrop = document.createElement('div');
		backdrop.className = 'faz-modal-backdrop active';

		var modal = document.createElement('div');
		modal.className = 'faz-modal' + (opts.size ? ' faz-modal-' + opts.size : '');

		// Header
		var header = document.createElement('div');
		header.className = 'faz-modal-header';
		var h3 = document.createElement('h3');
		h3.textContent = opts.title;
		var closeBtn = document.createElement('button');
		closeBtn.className = 'faz-modal-close';
		closeBtn.textContent = '\u00D7';
		closeBtn.type = 'button';
		header.appendChild(h3);
		header.appendChild(closeBtn);
		modal.appendChild(header);

		// Body
		var body = document.createElement('div');
		body.className = 'faz-modal-body';
		if (typeof opts.body === 'string') {
			// Only used for trusted internal markup, never user input
			body.textContent = opts.body;
		} else if (opts.body instanceof HTMLElement) {
			body.appendChild(opts.body);
		}
		modal.appendChild(body);

		// Footer
		if (opts.footer) {
			var footer = document.createElement('div');
			footer.className = 'faz-modal-footer';
			if (typeof opts.footer === 'string') {
				footer.textContent = opts.footer;
			} else if (opts.footer instanceof HTMLElement) {
				footer.appendChild(opts.footer);
			}
			modal.appendChild(footer);
		}

		backdrop.appendChild(modal);

		var escHandler = function (e) {
			if (e.key === 'Escape') close();
		};

		function close() {
			backdrop.classList.remove('active');
			document.removeEventListener('keydown', escHandler);
			setTimeout(function () {
				if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
			}, 200);
			if (opts.onClose) opts.onClose();
		}

		closeBtn.addEventListener('click', close);
		backdrop.addEventListener('click', function (e) {
			if (e.target === backdrop) close();
		});
		document.addEventListener('keydown', escHandler);

		document.body.appendChild(backdrop);

		return {
			el: backdrop,
			modal: modal,
			body: body,
			close: close,
		};
	};

	// ── Confirm dialog ───────────────────────────────────────
	FAZ.confirm = function (msg) {
		return new Promise(function (resolve) {
			var footer = document.createElement('div');
			footer.style.cssText = 'display:flex;gap:8px;justify-content:flex-end;width:100%';

			var cancelBtn = document.createElement('button');
			cancelBtn.className = 'faz-btn faz-btn-outline';
			cancelBtn.textContent = 'Cancel';
			cancelBtn.type = 'button';

			var confirmBtn = document.createElement('button');
			confirmBtn.className = 'faz-btn faz-btn-danger';
			confirmBtn.textContent = 'Confirm';
			confirmBtn.type = 'button';

			footer.appendChild(cancelBtn);
			footer.appendChild(confirmBtn);

			var msgEl = document.createElement('p');
			msgEl.style.cssText = 'margin:0;font-size:14px;';
			msgEl.textContent = msg;

			var resolved = false;
			var m = FAZ.modal({
				title: 'Confirm',
				body: msgEl,
				size: 'sm',
				footer: footer,
				onClose: function () { if (!resolved) { resolved = true; resolve(false); } },
			});

			cancelBtn.addEventListener('click', function () { resolved = true; resolve(false); m.close(); });
			confirmBtn.addEventListener('click', function () { resolved = true; resolve(true); m.close(); });
		});
	};

	// ── Toast notifications ──────────────────────────────────
	var toastContainer;
	FAZ.notify = function (message, type) {
		type = type || 'success';
		if (!toastContainer) {
			toastContainer = document.createElement('div');
			toastContainer.className = 'faz-toast-container';
			// Polite live region so screen readers announce dynamically
			// injected toasts (e.g. auto-detect results) — WCAG 2.2 SC 4.1.3.
			toastContainer.setAttribute('role', 'status');
			toastContainer.setAttribute('aria-live', 'polite');
			toastContainer.setAttribute('aria-atomic', 'true');
			document.body.appendChild(toastContainer);
		}
		var toast = document.createElement('div');
		toast.className = 'faz-toast faz-toast-' + type;
		toast.textContent = message;
		toastContainer.appendChild(toast);
		// Warnings/errors carry actionable text and need longer to read than a
		// "Saved" confirmation — keep them on screen twice as long.
		var dismissAfter = (type === 'warning' || type === 'error') ? 6000 : 3000;
		setTimeout(function () {
			toast.style.opacity = '0';
			toast.style.transform = 'translateX(40px)';
			toast.style.transition = 'opacity 0.3s, transform 0.3s';
			setTimeout(function () {
				if (toast.parentNode) toast.parentNode.removeChild(toast);
			}, 300);
		}, dismissAfter);
	};

	// ── Color picker ─────────────────────────────────────────
	FAZ.colorPicker = function (wrap) {
		if (typeof wrap === 'string') wrap = document.querySelector(wrap);
		if (!wrap) return;
		var picker = wrap.querySelector('input[type="color"]');
		var text = wrap.querySelector('input[type="text"]');
		if (!picker || !text) return;

		picker.addEventListener('input', function () {
			text.value = picker.value;
		});
		text.addEventListener('change', function () {
			var v = text.value.trim();
			if (/^#[0-9a-fA-F]{6}$/.test(v)) {
				picker.value = v;
			}
		});
	};

	FAZ.initColorPickers = function () {
		document.querySelectorAll('.faz-input-color-wrap').forEach(FAZ.colorPicker);
		// Firefox: close native color picker popover on outside click (once)
		if (!FAZ._colorBlurBound) {
			FAZ._colorBlurBound = true;
			document.addEventListener('mousedown', function (e) {
				document.querySelectorAll('input[type="color"]').forEach(function (el) {
					if (el !== e.target) el.blur();
				});
			});
		}
	};

	// ── HTML escaping ────────────────────────────────────────
	var escDiv = document.createElement('div');
	FAZ.esc = function (str) {
		escDiv.textContent = str;
		return escDiv.innerHTML;
	};

	// ── Deep get/set for nested objects by dot-path ──────────
	FAZ.deepGet = function (obj, path, def) {
		// Read-only dot-path traversal via reduce (a pure walk, never a
		// mutating for-loop, so it cannot pollute a prototype).
		var cur = String(path).split('.').reduce(function (acc, key) {
			return (acc !== null && acc !== undefined && typeof acc === 'object') ? acc[key] : undefined;
		}, obj);
		return cur !== undefined ? cur : (def !== undefined ? def : '');
	};

	FAZ.deepSet = function (obj, path, value) {
		if (!obj || typeof obj !== 'object' || !path) return;
		// Paths come from trusted data-path attributes in admin HTML templates.
		// Reject any prototype-pollution segment up front as defense-in-depth.
		var keys = String(path).split('.');
		if (keys.some(function (k) { return k === '__proto__' || k === 'constructor' || k === 'prototype'; })) return;
		var lastKey = keys.pop();
		if (lastKey === undefined) return;
		// Traverse-or-create each parent via reduce (a pure walk); the only
		// write is the single assignment below.
		var parent = keys.reduce(function (cur, segment) {
			if (!Object.prototype.hasOwnProperty.call(cur, segment) || cur[segment] === null || typeof cur[segment] !== 'object') {
				cur[segment] = {};
			}
			return cur[segment];
		}, obj);
		parent[lastKey] = value;
	};

	// ── Serialize form to nested JSON using data-path ────────
	FAZ.serializeForm = function (container) {
		var data = {};
		container.querySelectorAll('[data-path]').forEach(function (el) {
			var conditionalWrap = el.closest('[data-show-if]');
			if (el.disabled || el.hidden || el.closest('.faz-hidden')) {
				return;
			}
			if (conditionalWrap && window.getComputedStyle(conditionalWrap).display === 'none') {
				return;
			}
			var path = el.dataset.path;
			var val;
			if (el.type === 'checkbox') {
				val = el.checked;
			} else if (el.type === 'number') {
				val = el.value === '' ? 0 : Number(el.value);
			} else {
				val = el.value;
			}
			FAZ.deepSet(data, path, val);
		});
		return data;
	};

	// ── Populate form from nested JSON using data-path ───────
	FAZ.populateForm = function (container, data) {
		container.querySelectorAll('[data-path]').forEach(function (el) {
			var val = FAZ.deepGet(data, el.dataset.path);
			if (el.type === 'checkbox') {
				el.checked = !!val;
			} else if (el.type === 'color') {
				el.value = val || '#000000';
				var text = el.parentElement && el.parentElement.querySelector('input[type="text"]');
				if (text) text.value = el.value;
			} else {
				el.value = val !== undefined && val !== null ? val : '';
			}
		});
	};

	// ── Loading states ───────────────────────────────────────
	FAZ.btnLoading = function (btn, loading, loadingLabel) {
		if (!btn) return;
		if (loading) {
			// Idempotency: only snapshot the original label the first time we
			// enter the loading state. A second btnLoading(btn, true) call
			// (e.g. a double-click or overlapping request) would otherwise
			// capture the spinner-replaced text and lose the real label.
			if (btn.getAttribute('aria-busy') !== 'true') {
				btn.dataset.origText = btn.textContent;
			}
			btn.disabled = true;
			btn.setAttribute('aria-busy', 'true');
			var spinner = document.createElement('span');
			spinner.className = 'faz-spinner';
			btn.textContent = '';
			btn.appendChild(spinner);
			// Default label is "Saving…"; callers performing a non-save
			// operation (e.g. a read-only scan) pass their own label so the
			// spinner copy matches the action. The no-label default is sourced
			// from a localized i18n key when present, falling back to English.
			var lbl = loadingLabel || (window.fazConfig && window.fazConfig.i18n && window.fazConfig.i18n.saving) || 'Saving…';
			btn.appendChild(document.createTextNode(' ' + lbl));
		} else {
			btn.disabled = false;
			btn.removeAttribute('aria-busy');
			btn.textContent = btn.dataset.origText || 'Save';
		}
	};

	// ── Ready / DOMContentLoaded ─────────────────────────────
	FAZ.ready = function (fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	};

	window.FAZ = FAZ;

	// Shared page-link picker. The input remains editable for external URLs.
	FAZ.pageSearch = function (input) {
		if (!input || input.dataset.pageSearchBound) return;
		input.dataset.pageSearchBound = '1';
		var card = input.closest('.faz-card');
		if (card) card.classList.add('faz-card-overflow-visible');
		var labels = (window.fazConfig && window.fazConfig.i18n && window.fazConfig.i18n.pageSearch) || {};
		var wrap = document.createElement('div');
		wrap.className = 'faz-page-search';
		input.parentNode.insertBefore(wrap, input);
		wrap.appendChild(input);
		var list = document.createElement('div');
		list.id = input.id + '-suggestions';
		list.className = 'faz-page-search-results';
		list.setAttribute('role', 'listbox');
		list.setAttribute('aria-label', labels.results || 'Matching pages');
		list.hidden = true;
		wrap.appendChild(list);
		var status = document.createElement('div');
		status.id = input.id + '-search-status';
		status.className = 'faz-help';
		status.setAttribute('role', 'status');
		wrap.appendChild(status);
		input.setAttribute('aria-describedby', ((input.getAttribute('aria-describedby') || '') + ' ' + status.id).trim());
		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('aria-controls', list.id);
		input.setAttribute('aria-expanded', 'false');
		input.setAttribute('autocomplete', 'off');
		var timer, generation = 0, active = -1, pages = [];
		function close() {
			generation++;
			clearTimeout(timer);
			list.hidden = true;
			active = -1;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
		}
		function choose(index) {
			if (!pages[index]) return;
			input.value = pages[index].url;
			close();
			status.textContent = '';
			input.dispatchEvent(new Event('input', { bubbles: true }));
			input.dispatchEvent(new Event('change', { bubbles: true }));
		}
		input.addEventListener('input', function () {
			close();
			status.textContent = '';
			var term = input.value.trim();
			if (term.length < 2 || /^https?:\/\//i.test(term) || term.charAt(0) === '/') return;
			var request = generation;
			timer = setTimeout(function () {
				status.textContent = labels.searching || 'Searching pages…';
				FAZ.get('settings/pages', { search: term }).then(function (results) {
					if (request !== generation) return;
					pages = Array.isArray(results) ? results.filter(function (page) { return /^https?:\/\//i.test(page.url || ''); }) : [];
					list.textContent = '';
					pages.forEach(function (page, index) {
						var option = document.createElement('button');
						option.type = 'button';
						option.tabIndex = -1;
						option.id = list.id + '-' + index;
						option.setAttribute('role', 'option');
						option.setAttribute('aria-selected', 'false');
						var title = document.createElement('strong');
						title.textContent = page.title || page.url;
						var url = document.createElement('span');
						url.textContent = page.url;
						option.appendChild(title);
						option.appendChild(url);
						option.addEventListener('mousedown', function (event) { event.preventDefault(); });
						option.addEventListener('click', function () { choose(index); });
						list.appendChild(option);
					});
					list.hidden = !pages.length;
					input.setAttribute('aria-expanded', pages.length ? 'true' : 'false');
					status.textContent = pages.length ? (labels.choose || 'Use the arrow keys and Enter to choose a page.') : (labels.empty || 'No published pages found. You can enter a URL manually.');
				}).catch(function () {
					if (request === generation) status.textContent = labels.failed || 'Page search is unavailable. You can enter a URL manually.';
				});
			}, 250);
		});
		input.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') { close(); status.textContent = ''; return; }
			if (list.hidden) return;
			if (event.key === 'Enter' && active >= 0) { event.preventDefault(); choose(active); return; }
			if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
			event.preventDefault();
			active = active < 0 ? (event.key === 'ArrowDown' ? 0 : pages.length - 1) : (active + (event.key === 'ArrowDown' ? 1 : -1) + pages.length) % pages.length;
			Array.prototype.forEach.call(list.children, function (option, index) { option.setAttribute('aria-selected', index === active ? 'true' : 'false'); });
			input.setAttribute('aria-activedescendant', list.children[active].id);
			if (list.children[active].scrollIntoView) list.children[active].scrollIntoView({ block: 'nearest' });
		});
		input.addEventListener('blur', function () { close(); status.textContent = ''; });
	};
	FAZ.ready(function () {
		document.querySelectorAll('[data-faz-page-search]').forEach(FAZ.pageSearch);
	});

})(window);
