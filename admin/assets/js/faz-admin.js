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
			document.body.appendChild(toastContainer);
		}
		var toast = document.createElement('div');
		toast.className = 'faz-toast faz-toast-' + type;
		toast.textContent = message;
		toastContainer.appendChild(toast);
		setTimeout(function () {
			toast.style.opacity = '0';
			toast.style.transform = 'translateX(40px)';
			toast.style.transition = 'opacity 0.3s, transform 0.3s';
			setTimeout(function () {
				if (toast.parentNode) toast.parentNode.removeChild(toast);
			}, 300);
		}, 3000);
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
		var keys = path.split('.');
		var cur = obj;
		for (var i = 0; i < keys.length; i++) {
			if (cur === null || cur === undefined || typeof cur !== 'object') return def;
			cur = cur[keys[i]];
		}
		return cur !== undefined ? cur : (def !== undefined ? def : '');
	};

	FAZ.deepSet = function (obj, path, value) {
		if (!obj || typeof obj !== 'object' || !path) return;
		// Paths come from trusted data-path attributes in admin HTML templates.
		// Reject any path containing prototype-pollution keys as a defense-in-depth measure.
		if (/(?:^|\.)(__proto__|constructor|prototype)(?:\.|$)/.test(path)) return;
		var keys = path.split('.');
		var cur = obj;
		for (var i = 0; i < keys.length - 1; i++) {
			var segment = keys[i];
			if (!Object.prototype.hasOwnProperty.call(cur, segment) || cur[segment] === null || typeof cur[segment] !== 'object') {
				cur[segment] = {};
			}
			cur = cur[segment];
		}
		cur[keys[keys.length - 1]] = value;
	};

	// ── Serialize form to nested JSON using data-path ────────
	FAZ.serializeForm = function (container) {
		var data = {};
		container.querySelectorAll('[data-path]').forEach(function (el) {
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
	FAZ.btnLoading = function (btn, loading) {
		if (loading) {
			btn.dataset.origText = btn.textContent;
			btn.disabled = true;
			var spinner = document.createElement('span');
			spinner.className = 'faz-spinner';
			btn.textContent = '';
			btn.appendChild(spinner);
			btn.appendChild(document.createTextNode(' Saving...'));
		} else {
			btn.disabled = false;
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

})(window);
