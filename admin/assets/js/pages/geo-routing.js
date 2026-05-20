/**
 * Admin JS — Geo-routing page.
 *
 * Spec: specs/001-geo-routing-next/spec.md FR-05
 * Tasks: T088 + T089 (P6 Admin UI)
 *
 * Uses DOM construction (createElement + textContent + appendChild) for
 * all dynamic content — no innerHTML for user-supplied / API-supplied
 * strings. Safe by construction against XSS.
 */
(function() {
	'use strict';

	var root = document.getElementById('faz-geo-routing-app');
	if (!root) { return; }

	var REST_URL   = root.dataset.fazRestUrl || '';
	var REST_NONCE = root.dataset.fazRestNonce || '';

	// ---------- i18n + locale helpers ----------
	// Strings live in `fazConfig.i18n.geo.*` (see admin/class-admin.php).
	// Fallbacks ship the English default so the page degrades gracefully
	// if the locale array is incomplete (e.g., during a partial cache
	// invalidation after a plugin update).
	var FAZ_I18N = (window.fazConfig && window.fazConfig.i18n && window.fazConfig.i18n.geo) || {};
	function t(key, fallback) {
		return (FAZ_I18N && FAZ_I18N[key]) || fallback;
	}
	function getLocale() {
		// `fazConfig.locale` is the WP user_locale (e.g. 'it_IT'); convert
		// to a BCP-47 tag JS understands ('it-IT'). Fallback to the
		// document's lang attribute, then to undefined (lets the JS
		// engine pick the runtime default).
		var loc = (window.fazConfig && window.fazConfig.locale) || document.documentElement.lang;
		return loc ? String(loc).replace(/_/g, '-') : undefined;
	}
	function sprintf1(template, value) {
		// Trivial single-substitution sprintf-alike for %s / %d / %1$s.
		return String(template)
			.replace(/%(\d+\$)?[sd]/, String(value));
	}

	// ---------- DOM helpers (XSS-safe by construction) ----------

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function(k) {
				if (k === 'class') {
					node.className = attrs[k];
				} else if (k === 'text') {
					node.textContent = attrs[k];
				} else if (k.indexOf('data-') === 0 || k === 'role' || k === 'type' || k === 'name' || k === 'value' || k === 'placeholder' || k === 'maxlength' || k === 'style' || k === 'checked' || k === 'disabled' || k === 'required' || k === 'id' || k === 'for' || k === 'autocomplete' || k === 'aria-selected' || k === 'aria-live') {
					if (k === 'checked' || k === 'disabled' || k === 'required') {
						if (attrs[k]) { node.setAttribute(k, k); }
					} else {
						node.setAttribute(k, String(attrs[k]));
					}
				} else if (k.indexOf('on') === 0 && typeof attrs[k] === 'function') {
					node.addEventListener(k.substring(2), attrs[k]);
				}
			});
		}
		if (children) {
			(Array.isArray(children) ? children : [children]).forEach(function(c) {
				if (c === null || c === undefined) { return; }
				if (typeof c === 'string' || typeof c === 'number') {
					node.appendChild(document.createTextNode(String(c)));
				} else {
					node.appendChild(c);
				}
			});
		}
		return node;
	}

	function clear(node) {
		while (node.firstChild) { node.removeChild(node.firstChild); }
	}

	// Prefer the project AJAX helpers (FAZ.get/post/put/del) when
	// available — they wrap wp.apiFetch which centralises nonce + base
	// URL handling so every admin page shares the same contract. Fall
	// back to raw fetch for the edge case where faz-admin.js hasn't
	// loaded yet (e.g. async load race in the WP admin head).
	//
	// All paths passed in are unprefixed (e.g. 'preview', 'overrides/IT');
	// FAZ.api() prefixes 'faz/v1/' itself, the raw-fetch path uses the
	// fully-qualified REST_URL injected by class-admin.php.
	function api(method, path, body) {
		var FAZ = window.FAZ;
		var verb = String(method || 'GET').toUpperCase();
		if (FAZ && typeof FAZ.api === 'function') {
			switch (verb) {
				case 'GET':    return FAZ.get(path);
				case 'POST':   return FAZ.post(path, body || {});
				case 'PUT':    return FAZ.put(path, body || {});
				case 'DELETE': return FAZ.del(path);
			}
		}
		return fetch(REST_URL + path, {
			method:      verb,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce':   REST_NONCE,
				'Content-Type': 'application/json'
			},
			body: body ? JSON.stringify(body) : undefined
		}).then(function(r) {
			if (!r.ok) {
				return r.json().then(function(j) { throw new Error(j.message || ('HTTP ' + r.status)); });
			}
			return r.json();
		});
	}

	function showError(container, err) {
		clear(container);
		container.appendChild(el('div', { class: 'faz-geo-error', text: err.message || 'Error' }));
	}

	// ---------- Tabs ----------

	root.querySelectorAll('.faz-geo-tab').forEach(function(btn) {
		btn.addEventListener('click', function() {
			root.querySelectorAll('.faz-geo-tab').forEach(function(b) {
				b.classList.remove('active');
				b.setAttribute('aria-selected', 'false');
			});
			btn.classList.add('active');
			btn.setAttribute('aria-selected', 'true');
			root.querySelectorAll('.faz-geo-panel').forEach(function(p) {
				p.classList.add('hidden');
			});
			var target = document.getElementById('faz-geo-panel-' + btn.dataset.target);
			if (target) { target.classList.remove('hidden'); }
			loadPanel(btn.dataset.target);
		});
	});

	// ---------- Panel loaders ----------

	// Track per-panel load state so we don't refetch on every tab click.
	// `loading` is set on dispatch, `loaded` is set ONLY after the
	// corresponding loader's promise resolves successfully. If the API
	// call fails, both flags get cleared so the next tab click retries
	// (the previous implementation marked `loaded=true` synchronously,
	// permanently locking out retry after a network blip).
	var loaded  = {};
	var loading = {};
	function loadPanel(name) {
		if (loaded[name] || loading[name]) { return; }
		loading[name] = true;
		var p = null;
		switch (name) {
			case 'status':    p = loadStatus(); break;
			case 'coverage':  p = loadCoverage(); break;
			case 'overrides': p = loadOverrides(); break;
			case 'preview':   p = bindPreview(); break;
			case 'ipinfo':    p = loadIpinfo(); break;
			case 'pipl':      p = loadPipl(); break;
		}
		// Loaders return a promise (typed-string `void` is fine via
		// Promise.resolve coercion). bindPreview() is synchronous and
		// returns undefined → treat as resolved.
		Promise.resolve(p).then(
			function() { loaded[name]  = true;  loading[name] = false; },
			function() { loaded[name]  = false; loading[name] = false; /* allow retry */ }
		);
	}

	function loadStatus() {
		var container = document.getElementById('faz-geo-status-content');
		return api('GET', 'status').then(function(data) {
			clear(container);
			var tbody = el('tbody');
			tbody.appendChild(el('tr', null, [
				el('td', null, el('strong', { text: 'Catalog rulesets' })),
				el('td', { text: String(data.catalog.rulesets_count) })
			]));
			tbody.appendChild(el('tr', null, [
				el('td', null, el('strong', { text: 'Fallback ruleset' })),
				el('td', null, el('code', { text: data.catalog.fallback_id }))
			]));
			tbody.appendChild(el('tr', null, [
				el('td', null, el('strong', { text: 'ipinfo opt-in' })),
				el('td', { text: data.ipinfo.optin ? '✅ active' : '⚪ disabled' })
			]));
			tbody.appendChild(el('tr', null, [
				el('td', null, el('strong', { text: 'ipinfo API key' })),
				el('td', { text: data.ipinfo.key_present ? '✅ configured' : '⚪ not set' })
			]));
			tbody.appendChild(el('tr', null, [
				el('td', null, el('strong', { text: 'Schema migration v2' })),
				el('td', { text: data.migration.complete ? '✅ complete' : '⚠️ incomplete' })
			]));
			if (data.migration.pending_columns && data.migration.pending_columns.length) {
				tbody.appendChild(el('tr', null, [
					el('td', null, el('strong', { text: 'Pending columns' })),
					el('td', { text: data.migration.pending_columns.join(', ') })
				]));
			}
			if (data.migration.disabled_reason) {
				tbody.appendChild(el('tr', null, [
					el('td', null, el('strong', { text: 'Migration disabled' })),
					el('td', { text: data.migration.disabled_reason })
				]));
			}
			container.appendChild(el('table', { class: 'widefat striped' }, tbody));
		}).catch(function(err) { showError(container, err); throw err; });
	}

	function loadCoverage() {
		var container = document.getElementById('faz-geo-coverage-content');
		return api('GET', 'rulesets').then(function(data) {
			clear(container);
			var thead = el('thead', null, el('tr', null, [
				el('th', { text: 'Ruleset ID' }),
				el('th', { text: 'Display name' }),
				el('th', { text: 'Model' }),
				el('th', { text: 'Applies to' }),
				el('th', { text: 'Version' })
			]));
			var tbody = el('tbody');
			data.rulesets.forEach(function(r) {
				var countries = (r.applies_to.countries || []).join(', ');
				var regions   = (r.applies_to.regions || []).join(', ');
				var applies   = countries + (regions ? ' (' + regions + ')' : '');
				tbody.appendChild(el('tr', null, [
					el('td', null, el('code', { text: r.id })),
					el('td', { text: r.display_name }),
					el('td', null, el('span', { class: 'faz-geo-badge faz-geo-badge--specific', text: r.model })),
					el('td', { text: applies }),
					el('td', { text: r.version })
				]));
			});
			container.appendChild(el('table', { class: 'widefat striped faz-geo-coverage-table' }, [thead, tbody]));
		}).catch(function(err) { showError(container, err); throw err; });
	}

	function loadOverrides() {
		var container = document.getElementById('faz-geo-overrides-content');
		return api('GET', 'overrides').then(function(data) {
			clear(container);
			var overrides = data.overrides || {};
			var keys = Object.keys(overrides);
			var countTpl = keys.length === 1
				? t('overridesConfiguredSingular', '%d override configured.')
				: t('overridesConfiguredPlural', '%d overrides configured.');
			container.appendChild(el('p', { text: sprintf1(countTpl, keys.length) }));

			if (keys.length) {
				var thead = el('thead', null, el('tr', null, [
					el('th', { text: t('country', 'Country') }),
					el('th', { text: t('rulesetOverride', 'Ruleset override') }),
					el('th', { text: t('deltaFields', 'Delta fields') }),
					el('th', { text: t('action', 'Action') })
				]));
				var tbody = el('tbody');
				keys.forEach(function(cc) {
					var ov = overrides[cc] || {};
					var deltaCount = ov.delta ? Object.keys(ov.delta).length : 0;
					var ridCell = ov.ruleset_id
						? el('code', { text: ov.ruleset_id })
						: el('em', { text: t('autoDetect', '(auto-detect)') });
					var deleteBtn = el('button', {
						class: 'button button-link-delete',
						'data-country': cc,
						onclick: function() {
							if (!confirm(t('confirmDelete', 'Remove this override?') + ' (' + cc + ')')) { return; }
							api('DELETE', 'overrides/' + cc).then(function() {
								loaded.overrides = false;
								loadOverrides();
							}).catch(function(err) { alert(sprintf1(t('errorPrefix', 'Error: %s'), err.message)); });
						},
						text: t('delete', 'Delete')
					});
					tbody.appendChild(el('tr', null, [
						el('td', null, el('strong', { text: cc })),
						el('td', null, ridCell),
						el('td', { text: deltaCount + '' }),
						el('td', null, deleteBtn)
					]));
				});
				container.appendChild(el('table', { class: 'widefat striped' }, [thead, tbody]));
			} else {
				container.appendChild(el('p', { class: 'description', text: t('noOverrides', 'No per-country overrides configured. The plugin auto-detects rule-set from country and US state.') }));
			}

			// Add form
			container.appendChild(el('h3', { style: 'margin-top:24px', text: t('addOverride', 'Add override') }));
			var ovCountry = el('input', { type: 'text', id: 'ov-country', maxlength: '2', style: 'text-transform:uppercase;width:6em', required: true });
			var ovRid     = el('input', { type: 'text', id: 'ov-ruleset-id', placeholder: 'e.g. gdpr-italy' });
			var ovDelta   = el('textarea', { id: 'ov-delta', placeholder: '{"signals.cmv2.functionality_storage":"denied"}' });
			ovDelta.rows = 4; ovDelta.cols = 60;

			var form = el('form', {
				id: 'faz-geo-add-override',
				onsubmit: function(e) {
					e.preventDefault();
					var cc = ovCountry.value.trim().toUpperCase();
					var rid = ovRid.value.trim();
					var deltaRaw = ovDelta.value.trim();
					var delta = {};
					if (deltaRaw) {
						try { delta = JSON.parse(deltaRaw); }
						catch (err) { alert('Invalid delta JSON'); return; }
					}
					var current = overrides || {};
					current[cc] = { ruleset_id: rid || null, delta: delta };
					api('POST', 'overrides', { overrides: current }).then(function() {
						loaded.overrides = false;
						loadOverrides();
					}).catch(function(err) { alert(sprintf1(t('errorPrefix', 'Error: %s'), err.message)); });
				}
			}, [
				el('p', null, el('label', null, [
					'Country (ISO 3166-1 alpha-2)', el('br'), ovCountry
				])),
				el('p', null, el('label', null, [
					'Ruleset override (leave blank for auto)', el('br'), ovRid
				])),
				el('p', null, el('label', null, [
					'Delta JSON (object, optional)', el('br'), ovDelta
				])),
				el('p', null, el('button', { type: 'submit', class: 'button button-primary', text: t('save', 'Save') }))
			]);
			container.appendChild(form);
		}).catch(function(err) { showError(container, err); throw err; });
	}

	function bindPreview() {
		var form = document.getElementById('faz-geo-preview-form');
		var result = document.getElementById('faz-geo-preview-result');
		if (!form) { return; }
		form.addEventListener('submit', function(e) {
			e.preventDefault();
			var country = document.getElementById('faz-geo-preview-country').value.trim().toUpperCase();
			var region  = document.getElementById('faz-geo-preview-region').value.trim().toUpperCase();
			var vpn     = document.getElementById('faz-geo-preview-vpn').checked;
			api('POST', 'preview', { country: country, region: region, vpn: vpn }).then(function(data) {
				clear(result);
				var card = el('div', { class: 'faz-geo-preview-card' });
				card.appendChild(el('p', null, [
					el('strong', { text: t('resolvedRuleset', 'Resolved ruleset') + ': ' }),
					el('code', { text: data.ruleset_id })
				]));
				if (data.ruleset) {
					card.appendChild(el('p', { text: data.ruleset.display_name + ' (v' + data.ruleset.version + ')' }));
					var summary = el('summary', { text: t('fullRulesetJson', 'Full ruleset JSON') });
					var pre = el('pre', { text: JSON.stringify(data.ruleset, null, 2) });
					var details = el('details', null, [summary, pre]);
					card.appendChild(details);
				}
				result.appendChild(card);
			}).catch(function(err) { showError(result, err); });
		});
	}

	function loadIpinfo() {
		var container = document.getElementById('faz-geo-ipinfo-content');
		return api('GET', 'ipinfo-settings').then(function(data) {
			clear(container);
			var optinCb = el('input', { type: 'checkbox', id: 'ipinfo-optin' });
			if (data.optin) { optinCb.checked = true; }
			var keyInput = el('input', { type: 'password', id: 'ipinfo-api-key', autocomplete: 'off', placeholder: t('apiKeyPlaceholder', 'token from ipinfo.io/account/token') });
			var attestCb = el('input', { type: 'checkbox', id: 'ipinfo-attestation' });

			var keyLabelChildren = [ t('apiKeyLabel', 'API key') ];
			if (data.key_present) {
				keyLabelChildren.push(' ');
				keyLabelChildren.push(el('em', { text: t('apiKeyStored', '(stored — leave blank to keep)') }));
			}
			keyLabelChildren.push(el('br'));
			keyLabelChildren.push(keyInput);

			var form = el('form', {
				id: 'faz-geo-ipinfo-form',
				onsubmit: function(e) {
					e.preventDefault();
					var body = { optin: optinCb.checked, attestation_dpf_scc: attestCb.checked };
					if (keyInput.value) { body.api_key = keyInput.value; }
					api('POST', 'ipinfo-settings', body).then(function() {
						var msg = el('div', { class: 'faz-geo-success', text: t('settingsSaved', 'Settings saved.') });
						container.insertBefore(msg, container.firstChild);
						setTimeout(function() { if (msg.parentNode) { msg.parentNode.removeChild(msg); } }, 3000);
					}).catch(function(err) { alert(sprintf1(t('errorPrefix', 'Error: %s'), err.message)); });
				}
			}, [
				el('p', null, el('label', null, [
					optinCb, ' ' + t('enableIpinfo', 'Enable ipinfo.io VPN detection')
				])),
				el('p', null, el('label', null, keyLabelChildren)),
				el('p', null, el('label', null, [
					attestCb, ' ' + t('attestDpfScc', 'I attest to having a DPF / SCC / DPA agreement with ipinfo.io for cross-border data transfer of EU/UK visitor IPs (required for opt-in)')
				])),
				el('p', null, el('button', { type: 'submit', class: 'button button-primary', text: t('save', 'Save') }))
			]);
			container.appendChild(form);
		}).catch(function(err) { showError(container, err); throw err; });
	}

	function loadPipl() {
		var container = document.getElementById('faz-geo-pipl-content');
		return api('GET', 'pipl-attestation').then(function(data) {
			clear(container);
			var attestedCb = el('input', { type: 'checkbox', id: 'pipl-attested' });
			if (data.attested) { attestedCb.checked = true; }

			var children = [
				el('p', null, el('label', null, [
					attestedCb, ' ' + t('piplAttestText', 'I attest to having a Standard Contract (PIPL Art. 38) or CAC security assessment (Art. 40) for cross-border data transfers OF data subject to PIPL, OR to not process any data that requires such mechanisms.')
				]))
			];
			if (data.attested && data.timestamp) {
				// Use the configured locale so non-English admins see a
				// timestamp in their format (e.g. it-IT "20/05/2026, 11:23").
				var dt = new Date(data.timestamp * 1000);
				var locale = getLocale();
				var dateStr;
				try {
					dateStr = locale ? dt.toLocaleString(locale) : dt.toLocaleString();
				} catch (e) {
					// Bad locale tag (e.g. 'x_FAKE') — runtime throws RangeError.
					dateStr = dt.toLocaleString();
				}
				var tmpl = t('piplAttestedAt', 'Attested at %1$s by user ID %2$s');
				// Two substitutions — sprintf1 only handles one, so we
				// chain: replace %1$s then %2$s.
				var msg = String(tmpl)
					.replace(/%1\$s/, dateStr)
					.replace(/%2\$s/, String(data.user_id));
				children.push(el('p', null, el('em', { text: msg })));
			}
			children.push(el('p', null, el('button', { type: 'submit', class: 'button button-primary', text: t('save', 'Save') })));

			var form = el('form', {
				id: 'faz-geo-pipl-form',
				onsubmit: function(e) {
					e.preventDefault();
					api('POST', 'pipl-attestation', { attested: attestedCb.checked }).then(function() {
						loaded.pipl = false;
						loadPipl();
					}).catch(function(err) { alert(sprintf1(t('errorPrefix', 'Error: %s'), err.message)); });
				}
			}, children);
			container.appendChild(form);
		}).catch(function(err) { showError(container, err); throw err; });
	}

	// Initial load
	loadPanel('status');
})();
