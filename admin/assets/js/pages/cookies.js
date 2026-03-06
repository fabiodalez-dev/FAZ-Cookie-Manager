/**
 * FAZ Cookie Manager - Cookies Page JS
 */
(function () {
	'use strict';

	var categories = [];
	var cookies = [];
	var activeCat = 'all';   // category ID or 'all'
	var activeCatName = '';  // display name for heading
	var staleCookieNames = {};
	var staleCookieCount = 0;

	// Extract display string from a value that might be a multilingual object.
	function textVal(val) {
		if (!val) return '';
		if (typeof val === 'string') return val;
		if (typeof val === 'object') {
			return val.en || val[Object.keys(val)[0]] || '';
		}
		return String(val);
	}

	FAZ.ready(function () {
		loadCategories();
		loadCookies();
		document.getElementById('faz-add-cookie-btn').addEventListener('click', function () {
			openCookieModal();
		});
		// Scan dropdown toggle
		var scanBtn = document.getElementById('faz-scan-btn');
		var scanDropdown = document.getElementById('faz-scan-dropdown');
		scanBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			scanDropdown.classList.toggle('open');
		});
		scanDropdown.querySelectorAll('.faz-dropdown-item').forEach(function (item) {
			item.addEventListener('click', function (e) {
				e.stopPropagation();
				scanDropdown.classList.remove('open');
				var depth = parseInt(item.dataset.depth, 10);
				startScan(depth);
			});
		});

		// Auto-categorize dropdown toggle
		var acBtn = document.getElementById('faz-auto-cat-btn');
		var acDropdown = document.getElementById('faz-auto-cat-dropdown');
		acBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			acDropdown.classList.toggle('open');
		});
		acDropdown.querySelectorAll('.faz-dropdown-item').forEach(function (item) {
			item.addEventListener('click', function (e) {
				e.stopPropagation();
				acDropdown.classList.remove('open');
				autoCategorize(item.dataset.scope);
			});
		});
		document.addEventListener('click', function () {
			scanDropdown.classList.remove('open');
			acDropdown.classList.remove('open');
		});

		// Select-all checkbox.
		document.getElementById('faz-select-all-cookies').addEventListener('change', function () {
			var checked = this.checked;
			document.querySelectorAll('.faz-cookie-check').forEach(function (cb) { cb.checked = checked; });
			updateBulkBar();
		});

		// Bulk delete button.
		document.getElementById('faz-bulk-delete-btn').addEventListener('click', function () {
			var ids = [];
			document.querySelectorAll('.faz-cookie-check:checked').forEach(function (cb) { ids.push(parseInt(cb.value, 10)); });
			if (!ids.length) return;
			FAZ.confirm('Delete ' + ids.length + ' selected cookie(s)?').then(function (ok) {
				if (!ok) return;
				FAZ.post('cookies/bulk-delete', { ids: ids }).then(function (res) {
					var deletedCount = (res && typeof res.deleted === 'number') ? res.deleted : ids.length;
					FAZ.notify(deletedCount + ' cookies deleted');
					loadCookies();
					loadCategories();
				}).catch(function () {
					FAZ.notify('Bulk delete failed', 'error');
				});
			});
		});

		// Cookie Definitions: load status + wire Update button
		loadDefinitionsStatus();
		var updateDefsBtn = document.getElementById('faz-update-defs-btn');
		if (updateDefsBtn) {
			updateDefsBtn.addEventListener('click', updateDefinitions);
		}
	});

	function loadCategories() {
		FAZ.get('cookies/categories').then(function (data) {
			categories = Array.isArray(data) ? data : (data.items || []);
			renderCategories();
		}).catch(function (err) { console.error('FAZ: Failed to load categories', err); });
	}

	function loadCookies(done) {
		var params = {};
		if (activeCat !== 'all') params.category = activeCat;
		FAZ.get('cookies', params).then(function (data) {
			cookies = Array.isArray(data) ? data : (data.items || []);
			renderCookies();
			if (typeof done === 'function') done();
		}).catch(function () {
			cookies = [];
			renderCookies();
			if (typeof done === 'function') done();
		});
	}

	function getCookieId(cookie) {
		return cookie.id || cookie.cookie_id;
	}

	function isDiscoveredCookie(cookie) {
		return !!(cookie && (cookie.discovered === true || cookie.discovered === 1 || cookie.discovered === '1'));
	}

	function normalizeDomain(raw) {
		if (!raw) return '';
		return String(raw).trim().toLowerCase().replace(/^\.+/, '').replace(/:\d+$/, '');
	}

	function getStaleKey(cookie) {
		var name = (cookie && cookie.name) ? String(cookie.name).trim().toLowerCase() : '';
		if (!name) return '';
		return name + '|' + normalizeDomain(cookie.domain);
	}

	function getStaleKeyFromName(name, domain) {
		var normalizedName = name ? String(name).trim().toLowerCase() : '';
		if (!normalizedName) return '';
		return normalizedName + '|' + normalizeDomain(domain);
	}

	function buildCookieNameSet(list, discoveredOnly) {
		var set = {};
		(list || []).forEach(function (cookie) {
			var key = getStaleKey(cookie);
			if (!key) return;
			if (discoveredOnly && !isDiscoveredCookie(cookie)) return;
			set[key] = true;
		});
		return set;
	}

	function setStaleCookies(previousSet, currentSet) {
		staleCookieNames = {};
		staleCookieCount = 0;
		Object.keys(previousSet || {}).forEach(function (key) {
			if (!currentSet || !currentSet[key]) {
				staleCookieNames[key] = true;
				staleCookieCount++;
			}
		});
	}

	function snapshotDiscoveredCookies() {
		return FAZ.get('cookies').then(function (data) {
			var list = Array.isArray(data) ? data : (data.items || []);
			return buildCookieNameSet(list, true);
		}).catch(function () {
			return {};
		});
	}

	function updateStaleBar(visibleStaleCount) {
		var staleBar = document.getElementById('faz-stale-bar');
		if (!staleBar) return;
		if (staleCookieCount <= 0) {
			staleBar.style.display = 'none';
			staleBar.textContent = '';
			return;
		}
		staleBar.style.display = '';
		staleBar.textContent = '';
		var msg = document.createElement('span');
		msg.textContent = visibleStaleCount > 0
			? visibleStaleCount + ' cookie(s) not found in the latest scan are highlighted in red.'
			: staleCookieCount + ' cookie(s) not found in the latest scan (not visible in this filter).';
		staleBar.appendChild(msg);

		var deleteAllBtn = document.createElement('button');
		deleteAllBtn.type = 'button';
		deleteAllBtn.className = 'faz-btn faz-btn-sm faz-stale-delete-all';
		deleteAllBtn.textContent = 'Delete all stale';
		deleteAllBtn.addEventListener('click', deleteAllStaleCookies);
		staleBar.appendChild(deleteAllBtn);
	}

	function renderCategories() {
		var list = document.getElementById('faz-cat-list');
		list.textContent = '';

		// "All" item
		var totalCookies = 0;
		categories.forEach(function (c) {
			totalCookies += (c.cookie_list ? c.cookie_list.length : 0);
		});

		var allLi = document.createElement('li');
		var allBtn = document.createElement('button');
		allBtn.className = activeCat === 'all' ? 'active' : '';
		var allName = document.createElement('span');
		allName.textContent = 'All Cookies';
		allBtn.appendChild(allName);
		var allCount = document.createElement('span');
		allCount.className = 'faz-count';
		allCount.textContent = totalCookies;
		allBtn.appendChild(allCount);
		allBtn.addEventListener('click', function () { activeCat = 'all'; loadCookies(); renderCategories(); });
		allLi.appendChild(allBtn);
		list.appendChild(allLi);

		categories.forEach(function (cat) {
			var li = document.createElement('li');
			var btn = document.createElement('button');
			var catId = cat.id || cat.slug || '';
			btn.className = String(activeCat) === String(catId) ? 'active' : '';

			var nameSpan = document.createElement('span');
			var catName = textVal(cat.name) || textVal(cat.title) || cat.slug || '';
			nameSpan.textContent = catName;
			btn.appendChild(nameSpan);

			// Badge for hidden categories (visibility=0).
			if (cat.visibility !== undefined && !cat.visibility) {
				var badge = document.createElement('span');
				badge.className = 'faz-badge faz-badge-muted';
				badge.textContent = 'hidden';
				badge.title = 'Hidden from frontend';
				badge.style.cssText = 'font-size:10px;margin-left:6px;padding:1px 6px;border-radius:3px;background:#e2e8f0;color:#64748b;vertical-align:middle;';
				btn.appendChild(badge);
			}

			var cookieCount = cat.cookie_list ? cat.cookie_list.length : 0;
			var countSpan = document.createElement('span');
			countSpan.className = 'faz-count';
			countSpan.textContent = cookieCount;
			btn.appendChild(countSpan);

			btn.addEventListener('click', function () {
				activeCat = catId;
				activeCatName = textVal(cat.name) || 'Cookies';
				loadCookies();
				renderCategories();
				document.getElementById('faz-cookies-title').textContent = activeCatName;
			});
			li.appendChild(btn);
			list.appendChild(li);
		});
	}

	function renderCookies() {
		var tbody = document.getElementById('faz-cookies-tbody');
		tbody.textContent = '';
		var visibleStaleCount = 0;

		// Reset select-all and bulk bar on re-render.
		var selectAll = document.getElementById('faz-select-all-cookies');
		if (selectAll) selectAll.checked = false;
		updateBulkBar();

		if (!cookies.length) {
			var tr = document.createElement('tr');
			var td = document.createElement('td');
			td.colSpan = 6;
			td.className = 'faz-empty';
			var p = document.createElement('p');
			p.textContent = 'No cookies found.';
			td.appendChild(p);
			tr.appendChild(td);
			tbody.appendChild(tr);
			updateStaleBar(0);
			return;
		}

		cookies.forEach(function (cookie) {
			var tr = document.createElement('tr');
			var staleKey = getStaleKey(cookie);
			var isStale = !!(staleKey && staleCookieNames[staleKey]);
			if (isStale) {
				tr.classList.add('faz-cookie-stale');
				visibleStaleCount++;
			}

			var tdCheck = document.createElement('td');
			var cb = document.createElement('input');
			cb.type = 'checkbox';
			cb.className = 'faz-cookie-check';
			cb.value = getCookieId(cookie);
			cb.setAttribute('aria-label', 'Select cookie ' + (cookie.name || ''));
			cb.addEventListener('change', updateBulkBar);
			tdCheck.appendChild(cb);
			tr.appendChild(tdCheck);

			var tdName = document.createElement('td');
			var strong = document.createElement('strong');
			strong.textContent = cookie.name || '--';
			tdName.appendChild(strong);
			tr.appendChild(tdName);

			var tdDomain = document.createElement('td');
			tdDomain.textContent = cookie.domain || '--';
			tdDomain.style.fontSize = '12px';
			tr.appendChild(tdDomain);

			var tdDuration = document.createElement('td');
			tdDuration.textContent = textVal(cookie.duration) || '--';
			tdDuration.style.fontSize = '12px';
			tr.appendChild(tdDuration);

			var tdDesc = document.createElement('td');
			var desc = textVal(cookie.description);
			tdDesc.textContent = desc.length > 60 ? desc.substring(0, 60) + '...' : (desc || '--');
			tdDesc.title = desc;
			tdDesc.style.fontSize = '12px';
			tr.appendChild(tdDesc);

			var tdActions = document.createElement('td');
			tdActions.className = 'faz-actions';

			var editBtn = document.createElement('button');
			editBtn.className = 'faz-btn faz-btn-outline faz-btn-sm';
			editBtn.textContent = 'Edit';
			editBtn.addEventListener('click', function () { openCookieModal(cookie); });
			tdActions.appendChild(editBtn);

			var delBtn = document.createElement('button');
			delBtn.className = 'faz-btn faz-btn-outline faz-btn-sm';
			delBtn.textContent = 'Delete';
			delBtn.style.color = 'var(--faz-danger)';
			delBtn.addEventListener('click', function () { deleteCookie(cookie); });
			tdActions.appendChild(delBtn);

			if (isStale) {
				var staleBtn = document.createElement('button');
				staleBtn.className = 'faz-btn faz-btn-sm';
				staleBtn.textContent = 'Delete stale';
				staleBtn.style.background = '#fee2e2';
				staleBtn.style.color = '#991b1b';
				staleBtn.style.border = '1px solid #fecaca';
				staleBtn.addEventListener('click', function () {
					deleteStaleCookieQuick(cookie);
				});
				tdActions.appendChild(staleBtn);
			}

			tr.appendChild(tdActions);
			tbody.appendChild(tr);
		});
		updateStaleBar(visibleStaleCount);
	}

	function updateBulkBar() {
		var checked = document.querySelectorAll('.faz-cookie-check:checked');
		var total = document.querySelectorAll('.faz-cookie-check').length;
		var bar = document.getElementById('faz-bulk-bar');
		var selectAll = document.getElementById('faz-select-all-cookies');
		if (selectAll) {
			selectAll.checked = total > 0 && checked.length === total;
			selectAll.indeterminate = checked.length > 0 && checked.length < total;
		}
		if (checked.length > 0) {
			bar.style.display = 'flex';
			bar.querySelector('.faz-bulk-count').textContent = checked.length + ' selected';
		} else {
			bar.style.display = 'none';
		}
	}

	function openCookieModal(cookie) {
		var isEdit = !!cookie;
		var form = document.createElement('div');

		var fields = [
			{ label: 'Cookie Name', path: 'name', type: 'text' },
			{ label: 'Domain', path: 'domain', type: 'text' },
			{ label: 'Duration', path: 'duration', type: 'text', placeholder: 'e.g. 1 year' },
			{ label: 'Description', path: 'description', type: 'textarea' },
		];

		fields.forEach(function (f) {
			var group = document.createElement('div');
			group.className = 'faz-form-group';
			var label = document.createElement('label');
			label.textContent = f.label;
			group.appendChild(label);

			var input;
			if (f.type === 'textarea') {
				input = document.createElement('textarea');
				input.className = 'faz-textarea';
				input.rows = 3;
			} else {
				input = document.createElement('input');
				input.type = f.type;
				input.className = 'faz-input';
			}
			input.dataset.field = f.path;
			if (f.placeholder) input.placeholder = f.placeholder;
			if (isEdit && cookie[f.path]) input.value = textVal(cookie[f.path]);
			group.appendChild(input);
			form.appendChild(group);
		});

		// Category dropdown
		var catGroup = document.createElement('div');
		catGroup.className = 'faz-form-group';
		var catLabel = document.createElement('label');
		catLabel.textContent = 'Category';
		catGroup.appendChild(catLabel);
		var catSelect = document.createElement('select');
		catSelect.className = 'faz-select';
		catSelect.dataset.field = 'category';
		categories.forEach(function (c) {
			var opt = document.createElement('option');
			opt.value = c.id || '';
			opt.textContent = textVal(c.name) || textVal(c.title) || c.slug || '';
			if (isEdit && String(cookie.category) === String(opt.value)) opt.selected = true;
			catSelect.appendChild(opt);
		});
		catGroup.appendChild(catSelect);
		form.appendChild(catGroup);

		var footer = document.createElement('div');
		footer.style.cssText = 'display:flex;gap:8px;justify-content:flex-end;width:100%';
		var cancelBtn = document.createElement('button');
		cancelBtn.className = 'faz-btn faz-btn-outline';
		cancelBtn.textContent = 'Cancel';
		cancelBtn.type = 'button';
		var saveBtn = document.createElement('button');
		saveBtn.className = 'faz-btn faz-btn-primary';
		saveBtn.textContent = isEdit ? 'Update Cookie' : 'Add Cookie';
		saveBtn.type = 'button';
		footer.appendChild(cancelBtn);
		footer.appendChild(saveBtn);

		var m = FAZ.modal({
			title: isEdit ? 'Edit Cookie' : 'Add Cookie',
			body: form,
			footer: footer,
		});

		cancelBtn.addEventListener('click', function () { m.close(); });
		saveBtn.addEventListener('click', function () {
			var data = {};
			form.querySelectorAll('[data-field]').forEach(function (el) {
				data[el.dataset.field] = el.value;
			});

			// Wrap duration and description as multilingual objects
			if (typeof data.duration === 'string') {
				data.duration = { en: data.duration };
			}
			if (typeof data.description === 'string') {
				data.description = { en: data.description };
			}
			// Category must be integer
			if (data.category) {
				data.category = parseInt(data.category, 10) || 0;
			}

			FAZ.btnLoading(saveBtn, true);
			var promise = isEdit
				? FAZ.put('cookies/' + (cookie.id || cookie.cookie_id), data)
				: FAZ.post('cookies', data);

			promise.then(function () {
				m.close();
				FAZ.notify(isEdit ? 'Cookie updated' : 'Cookie added');
				loadCookies();
				loadCategories();
			}).catch(function () {
				FAZ.btnLoading(saveBtn, false);
				FAZ.notify('Failed to save cookie', 'error');
			});
		});
	}

	function deleteCookie(cookie) {
		FAZ.confirm('Delete cookie "' + (cookie.name || '') + '"?').then(function (ok) {
			if (!ok) return;
			FAZ.del('cookies/' + getCookieId(cookie)).then(function () {
				FAZ.notify('Cookie deleted');
				loadCookies();
				loadCategories();
			}).catch(function () {
				FAZ.notify('Failed to delete cookie', 'error');
			});
		});
	}

	function deleteStaleCookieQuick(cookie) {
		FAZ.del('cookies/' + getCookieId(cookie)).then(function () {
			var staleKey = getStaleKey(cookie);
			if (staleKey && staleCookieNames[staleKey]) {
				delete staleCookieNames[staleKey];
				staleCookieCount = Math.max(0, staleCookieCount - 1);
			}
			FAZ.notify('Stale cookie deleted');
			loadCookies();
			loadCategories();
		}).catch(function () {
			FAZ.notify('Failed to delete stale cookie', 'error');
		});
	}

	function deleteAllStaleCookies() {
		if (!staleCookieCount) return;
		FAZ.confirm('Delete all stale cookies not found in the latest scan?').then(function (ok) {
			if (!ok) return;
			FAZ.get('cookies').then(function (data) {
				var list = Array.isArray(data) ? data : (data.items || []);
				var ids = [];
				list.forEach(function (cookie) {
					var staleKey = getStaleKey(cookie);
					var id = getCookieId(cookie);
					if (staleKey && staleCookieNames[staleKey] && id) {
						ids.push(parseInt(id, 10));
					}
				});
				if (!ids.length) {
					FAZ.notify('No stale cookies to delete');
					return;
				}
				FAZ.post('cookies/bulk-delete', { ids: ids }).then(function (res) {
					var deletedCount = (res && typeof res.deleted === 'number') ? res.deleted : ids.length;
					staleCookieNames = {};
					staleCookieCount = 0;
					FAZ.notify(deletedCount + ' stale cookie(s) deleted');
					loadCookies();
					loadCategories();
				}).catch(function () {
					FAZ.notify('Failed to delete stale cookies', 'error');
				});
			}).catch(function () {
				FAZ.notify('Failed to load cookies for stale cleanup', 'error');
			});
		});
	}

	// ── Browser-Based Cookie Scanner ───────────────────────
	// Loads pages in hidden iframes to discover cookies set by JavaScript
	// (e.g. _ga, _fbp) that server-side scanning cannot detect.

	var IFRAME_LOAD_TIMEOUT = 6000;  // Max wait for iframe load (ms). Must exceed adaptive settle (700+800=1500ms).
	var CONCURRENCY = 3;             // Parallel iframes.
	var EARLY_STOP_THRESHOLD = 5;    // Stop after N consecutive pages with no new findings.
	var SAFE_SCAN_THRESHOLD = 1000;  // Deep/full scans use safer timings and disable early stop.

	/**
	 * Normalize a URL: strip hash, preserve query, ensure trailing slash.
	 * Query params are kept by appending `u.search` to the normalized URL.
	 */
	function normalizeUrl(url) {
		try {
			var u = new URL(url, window.location.origin);
			return u.origin + u.pathname.replace(/\/?$/, '/') + u.search;
		} catch (_unused) {
			return url;
		}
	}

	/**
	 * Deduplicate and normalize an array of URLs.
	 */
	function deduplicateUrls(urls) {
		var seen = {};
		var result = [];
		for (var i = 0; i < urls.length; i++) {
			var n = normalizeUrl(urls[i]);
			if (!seen[n]) {
				seen[n] = true;
				result.push(n);
			}
		}
		return result;
	}

	function getApiErrorStatus(err) {
		if (!err) return 0;
		if (typeof err.status === 'number') return err.status;
		if (err.data && typeof err.data.status === 'number') return err.data.status;
		return 0;
	}

	function buildScanApiErrorDetail(err) {
		var status = getApiErrorStatus(err);
		var parts = [];
		if (status === 401) {
			parts.push('Session expired. Refresh the page and try again.');
		} else if (status === 403) {
			parts.push('Nonce/permissions error. Refresh the page and verify admin access.');
		} else if (status === 409) {
			parts.push('Another scan is already in progress.');
		} else if (status === 413) {
			parts.push('Request too large. Reduce scan depth and retry.');
		} else if (status === 429) {
			parts.push('Too many requests. Wait a moment and retry.');
		} else if (status >= 500) {
			parts.push('Server error. Check PHP/web server logs.');
		} else if (status === 0) {
			parts.push('Network/CORS/proxy issue while calling REST API.');
		}
		if (err && err.code) {
			parts.push('Code: ' + err.code + '.');
		}
		if (err && err.message) {
			parts.push(err.message);
		}
		return parts.length ? ' ' + parts.join(' ') : '';
	}

	function buildScanDiagnosticsHint(diagnostics, foundItems) {
		if (!diagnostics || foundItems > 0) return '';
		var hints = [];
		if (diagnostics.crossOrigin > 0) {
			hints.push('URL origin/protocol mismatch between admin and scanned pages');
		}
		if (diagnostics.iframeInaccessible > 0) {
			hints.push('iframe access blocked (X-Frame-Options/CSP or cross-origin redirect)');
		}
		if (diagnostics.iframeTimeout > 0 || diagnostics.settleTimeout > 0) {
			hints.push('pages too slow or blocked during iframe load');
		}
		if (diagnostics.missingContainer > 0) {
			hints.push('scanner iframe container missing in page markup');
		}
		if (diagnostics.invalidUrl > 0) {
			hints.push('invalid URLs discovered');
		}
		return hints.length ? ' Possible blockers: ' + hints.join('; ') + '.' : '';
	}

	function startScan(maxPages) {
		var btn = document.getElementById('faz-scan-btn');
		var dropdown = document.getElementById('faz-scan-dropdown');
		FAZ.btnLoading(btn, true);
		btn.textContent = 'Scanning...';

		// Build progress UI.
		var progressWrap = document.createElement('div');
		progressWrap.className = 'faz-scan-progress-wrap';
		var progress = document.createElement('div');
		progress.className = 'faz-scan-progress';
		var bar = document.createElement('div');
		bar.className = 'faz-scan-bar';
		var statusEl = document.createElement('span');
		statusEl.className = 'faz-scan-status';
		statusEl.textContent = 'Discovering pages...';
		var pagesEl = document.createElement('div');
		pagesEl.className = 'faz-scan-pages';
		pagesEl.textContent = '0/0 pages';
		progress.appendChild(bar);
		progress.appendChild(statusEl);
		progressWrap.appendChild(progress);
		progressWrap.appendChild(pagesEl);
		var card = dropdown.closest ? dropdown.closest('.faz-card') : null;
		var cardHeader = card ? card.querySelector('.faz-card-header') : null;
		if (card && cardHeader && cardHeader.parentNode) {
			cardHeader.parentNode.insertBefore(progressWrap, cardHeader.nextSibling);
		} else {
			dropdown.parentNode.insertBefore(progressWrap, dropdown.nextSibling);
		}

		var parsedMaxPages = parseInt(maxPages, 10);
		var requestPages = 20;
		var isFullScan = false;
		if (isFinite(parsedMaxPages) && parsedMaxPages > 0) {
			requestPages = parsedMaxPages;
		} else if (parsedMaxPages === 0) {
			// "Full scan" option in the UI: request maximum server cap.
			requestPages = 2000;
			isFullScan = true;
		}
		var safeMode = isFullScan || requestPages >= SAFE_SCAN_THRESHOLD;
		var scanOptions = {
			enableEarlyStop: !safeMode,
			loadTimeoutMs: safeMode ? 10000 : IFRAME_LOAD_TIMEOUT,
			settleTimeoutMs: safeMode ? 2600 : 1700,
		};

		// Metrics.
		var scanMetrics = {
			discoverMs: 0, scanMs: 0, importMs: 0,
			pageTimes: [], urlsDiscovered: 0,
			cookiesFound: 0, scriptsFound: 0,
			earlyStopReason: null, pagesScanned: 0,
			incremental: false,
		};
		var discoverStart = Date.now();

		// Get stored fingerprint for optional incremental scanning.
		var storedFingerprint = '';
		try {
			storedFingerprint = localStorage.getItem('faz_scan_fingerprint') || '';
		} catch (e) {
			console.warn('[FAZ Scanner] Cannot read fingerprint from localStorage — incremental scanning disabled.', e.message);
		}
		var allowIncremental = !safeMode && !!storedFingerprint;

		snapshotDiscoveredCookies().then(function (previousDiscoveredSet) {
			// Step 1: Ask server for URLs to scan (with retry for transient failures).
			var discoverPayload = {
				max_pages: requestPages,
				fingerprint: (!safeMode && allowIncremental) ? storedFingerprint : '',
			};
			function discoverWithRetry(attempt) {
				return FAZ.post('scans/discover', discoverPayload).catch(function (err) {
					if (attempt < 2 && err && err.code === 'fetch_error') {
						var delay = attempt === 0 ? 1000 : 3000;
						statusEl.textContent = 'Server busy, retrying in ' + (delay / 1000) + 's...';
						console.warn('[FAZ Scanner] Discover attempt ' + (attempt + 1) + ' failed, retrying...', err.message);
						return new Promise(function (resolve) { setTimeout(resolve, delay); })
							.then(function () { return discoverWithRetry(attempt + 1); });
					}
					throw err;
				});
			}
			discoverWithRetry(0).then(function (result) {
				scanMetrics.discoverMs = Date.now() - discoverStart;
				scanMetrics.incremental = !!(allowIncremental && result.incremental);
				var urls = deduplicateUrls(result.urls || []);

				scanMetrics.urlsDiscovered = urls.length;

				if (!urls.length) {
					finishScan(btn, progressWrap, 'No pages found to scan.', true);
					return;
				}
				statusEl.textContent = 'Scanning 0/' + urls.length + ' pages...';
				pagesEl.textContent = '0/' + urls.length + ' pages';
				bar.style.width = '0%';

				var scanStart = Date.now();

				// Step 2: Scan URLs concurrently.
				scanUrlsConcurrent(urls, function (collectedCookies, collectedScripts, diagnostics) {
					scanMetrics.scanMs = Date.now() - scanStart;
					scanMetrics.cookiesFound = collectedCookies.length;
					scanMetrics.scriptsFound = collectedScripts.length;
					bar.style.width = '100%';
					statusEl.textContent = 'Saving results...';

					var importStart = Date.now();

					// Step 3: Send results to server (strip pageTimes to avoid bloating payload).
					var metricsToSend = {
						discoverMs: scanMetrics.discoverMs,
						scanMs: scanMetrics.scanMs,
						urlsDiscovered: scanMetrics.urlsDiscovered,
						cookiesFound: scanMetrics.cookiesFound,
						scriptsFound: scanMetrics.scriptsFound,
						earlyStopReason: scanMetrics.earlyStopReason,
						pagesScanned: scanMetrics.pagesScanned,
						incremental: scanMetrics.incremental,
					};
						FAZ.post('scans/import', {
							cookies: collectedCookies,
							pages_scanned: scanMetrics.pagesScanned,
							scripts: collectedScripts,
							metrics: metricsToSend,
						}).then(function (res) {
							scanMetrics.importMs = Date.now() - importStart;
							// Persist fingerprint only after successful import.
							try {
								if (result.fingerprint) localStorage.setItem('faz_scan_fingerprint', result.fingerprint);
							} catch (e) {
								console.warn('[FAZ Scanner] Cannot persist fingerprint — next scan will be full.', e.message);
							}
							var total = res.total_cookies || collectedCookies.length;
							var currentDetectedSet = buildCookieNameSet(collectedCookies, false);
							if (res && Array.isArray(res.cookie_names) && res.cookie_names.length) {
								res.cookie_names.forEach(function (name) {
									var prefix = name ? String(name).trim().toLowerCase() + '|' : '';
									if (!prefix) return;
									// Server-inferred names lack domain; match any previously-known key by name prefix.
									Object.keys(previousDiscoveredSet).forEach(function (key) {
										if (key.indexOf(prefix) === 0) {
											currentDetectedSet[key] = true;
										}
									});
								});
							}
							if (scanMetrics.incremental) {
								// Incremental scan covers only a subset; avoid false stale flags.
								staleCookieNames = {};
								staleCookieCount = 0;
							} else {
								setStaleCookies(previousDiscoveredSet, currentDetectedSet);
							}

						var msg = 'Scan complete \u2014 ' + total + ' cookies found on ' + scanMetrics.pagesScanned + ' pages';
						if (scanMetrics.earlyStopReason) {
							msg += ' (early stop: ' + scanMetrics.earlyStopReason + ')';
						}
						if (staleCookieCount > 0) {
							msg += ' | ' + staleCookieCount + ' stale cookie(s) highlighted';
						}
						msg += buildScanDiagnosticsHint(diagnostics, total);
						console.log('[FAZ Scanner] Metrics:', scanMetrics);
						if (diagnostics && diagnostics.totalIssues > 0) {
							console.warn('[FAZ Scanner] Diagnostics:', diagnostics);
						}
						finishScan(btn, progressWrap, msg);
						loadCookies(function () {
							loadCategories();
						});
					}).catch(function (err) {
						console.error('[FAZ Scanner] Import failed:', err);
						var detail = buildScanApiErrorDetail(err);
						finishScan(btn, progressWrap, 'Scan finished but failed to save results.' + detail, true);
					});
				}, bar, statusEl, pagesEl, scanMetrics, scanOptions);
			}).catch(function (err) {
				console.error('[FAZ Scanner] Discover failed:', err);
				var detail = buildScanApiErrorDetail(err);
				finishScan(btn, progressWrap, 'Failed to discover pages.' + detail, true);
			});
		});
	}

	function finishScan(btn, progress, message, isError) {
		FAZ.btnLoading(btn, false);
		btn.textContent = 'Scan Site \u25BE';
		if (progress.parentNode) progress.parentNode.removeChild(progress);
		FAZ.notify(message, isError ? 'error' : 'success');
	}

	/**
	 * Scan URLs concurrently with a pool of iframes.
	 *
	 * @param {string[]}  urls        URLs to scan.
	 * @param {Function}  done        Callback(cookies, scripts) when all done.
	 * @param {Element}   bar         Progress bar element.
	 * @param {Element}   statusEl    Status text element.
	 * @param {Element}   pagesEl     Pages counter element.
	 * @param {object}    metrics     Metrics object to populate.
	 */
	function scanUrlsConcurrent(urls, done, bar, statusEl, pagesEl, metrics, options) {
		options = options || {};
		var enableEarlyStop = options.enableEarlyStop !== false;
		var loadTimeoutMs = (typeof options.loadTimeoutMs === 'number' && options.loadTimeoutMs > 0) ? options.loadTimeoutMs : IFRAME_LOAD_TIMEOUT;
		var settleTimeoutMs = (typeof options.settleTimeoutMs === 'number' && options.settleTimeoutMs > 0) ? options.settleTimeoutMs : 1700;
		var collectedCookies = [];
		var collectedScripts = [];
		var diagnostics = {
			invalidUrl: 0,
			crossOrigin: 0,
			missingContainer: 0,
			iframeInaccessible: 0,
			iframeTimeout: 0,
			settleTimeout: 0,
			totalIssues: 0,
		};
		var cookieSet = {};    // O(1) dedup for cookie names.
		var scriptSet = {};    // O(1) dedup for script URLs.
		var nextIndex = 0;     // Next URL to dispatch.
		var completed = 0;     // URLs finished.
		var active = 0;        // Currently scanning.
		var stopped = false;   // Early stop flag.
		var noNewCount = 0;    // Consecutive pages with no new findings.
		var total = urls.length;
		var totalPageTime = 0; // Running sum for ETA calculation.

		/**
		 * Add an item to an array if not already in the dedup set.
		 * Returns true if the item was new.
		 */
		function addUnique(set, arr, key, item) {
			if (set[key]) return false;
			set[key] = true;
			arr.push(item);
			return true;
		}

		function updateProgress() {
			var pct = Math.round((completed / total) * 100);
			bar.style.width = pct + '%';
			if (pagesEl) pagesEl.textContent = completed + '/' + total + ' pages';
			var eta = '';
			if (completed > 0) {
				var etaMs = Math.round(((total - completed) * totalPageTime / completed) / CONCURRENCY);
				if (etaMs > 1000) {
					eta = ' (~' + Math.ceil(etaMs / 1000) + 's left)';
				}
			}
			statusEl.textContent = completed + '/' + total + ' pages | ' +
				collectedCookies.length + ' cookies | ' + collectedScripts.length + ' scripts' + eta;
		}

		function dispatch() {
			while (active < CONCURRENCY && nextIndex < total && !stopped) {
				var idx = nextIndex;
				nextIndex++;
				active++;
				scanOne(idx);
			}
			if (active === 0 && (nextIndex >= total || stopped)) {
				metrics.pagesScanned = completed;
				// Clean up any orphaned iframes.
				try { document.getElementById('faz-scan-frame').textContent = ''; } catch (e) {}
				done(collectedCookies, collectedScripts, diagnostics);
			}
		}

		function scanOne(idx) {
			var cookiesBefore = parseBrowserCookies();
			var pageStart = Date.now();

			scanSingleUrl(urls[idx], function (pageResult) {
				active--;
				completed++;
				var elapsed = Date.now() - pageStart;
				metrics.pageTimes.push(elapsed);
				totalPageTime += elapsed;

				var foundNew = false;
				var issue = pageResult.issue || '';
				if (issue && Object.prototype.hasOwnProperty.call(diagnostics, issue)) {
					diagnostics[issue]++;
					diagnostics.totalIssues++;
				}

				// Add page-detected cookies.
				var pageCookies = pageResult.cookies || [];
				for (var i = 0; i < pageCookies.length; i++) {
					if (addUnique(cookieSet, collectedCookies, pageCookies[i].name, pageCookies[i])) {
						foundNew = true;
					}
				}

				// Diff cookies: find new ones set during this page load.
				var newCookies = diffCookies(cookiesBefore, parseBrowserCookies());
				for (var j = 0; j < newCookies.length; j++) {
					if (addUnique(cookieSet, collectedCookies, newCookies[j].name, newCookies[j])) {
						foundNew = true;
					}
				}

				// Collect scripts.
				var pageScripts = pageResult.scripts || [];
				for (var k = 0; k < pageScripts.length; k++) {
					if (addUnique(scriptSet, collectedScripts, pageScripts[k], pageScripts[k])) {
						foundNew = true;
					}
				}

				// Early stop check.
				noNewCount = foundNew ? 0 : noNewCount + 1;
				if (enableEarlyStop && noNewCount >= EARLY_STOP_THRESHOLD && completed >= EARLY_STOP_THRESHOLD) {
					stopped = true;
					metrics.earlyStopReason = noNewCount + ' consecutive pages with no new findings';
				}

				updateProgress();
				dispatch();
			}, {
				loadTimeoutMs: loadTimeoutMs,
				settleTimeoutMs: settleTimeoutMs,
			});
		}

		dispatch();
	}

	/**
	 * Load a single URL in a hidden iframe with adaptive timeout.
	 *
	 * @param {string}   url  The URL to scan.
	 * @param {Function} done Callback({cookies, scripts}).
	 */
	function scanSingleUrl(url, done, options) {
		options = options || {};
		var loadTimeoutMs = (typeof options.loadTimeoutMs === 'number' && options.loadTimeoutMs > 0) ? options.loadTimeoutMs : IFRAME_LOAD_TIMEOUT;
		var settleTimeoutMs = (typeof options.settleTimeoutMs === 'number' && options.settleTimeoutMs > 0) ? options.settleTimeoutMs : 1700;

		function emptyResult(issue) {
			return { cookies: [], scripts: [], issue: issue || '' };
		}
		var hadAccessError = false;

		// Validate URL: only allow http/https same-origin pages.
		var parsedUrl;
		try {
			parsedUrl = new URL(url, window.location.origin);
		} catch (_unused) {
			done(emptyResult('invalidUrl'));
			return;
		}

		var container = document.getElementById('faz-scan-frame');
		var currentUrl;
		try {
			currentUrl = new URL(window.location.href);
		} catch (_unused2) {
			currentUrl = window.location;
		}

		function normalizedHostPort(u) {
			var hostname = String(u.hostname || '').toLowerCase().replace(/^www\./, '');
			var port = u.port;
			if (!port) {
				port = (u.protocol === 'https:') ? '443' : '80';
			}
			return hostname + ':' + port;
		}

		var isHttpProtocol = (parsedUrl.protocol === 'http:' || parsedUrl.protocol === 'https:');
		var isSameOriginHttp = isHttpProtocol &&
			parsedUrl.protocol === currentUrl.protocol &&
			normalizedHostPort(parsedUrl) === normalizedHostPort(currentUrl);

		if (!isSameOriginHttp) {
			done(emptyResult('crossOrigin'));
			return;
		}
		if (!container) {
			done(emptyResult('missingContainer'));
			return;
		}

		var iframe = document.createElement('iframe');
		iframe.style.cssText = 'width:1px;height:1px;border:none;position:absolute;left:-9999px;';
		iframe.sandbox = 'allow-same-origin allow-scripts';
		iframe.src = 'about:blank';
		container.appendChild(iframe);

		var finished = false;
		var timer = null;
		var lastRead = null;

		function readIframe() {
			var result = { cookies: [], scripts: [], issue: '' };
			try {
				var doc = iframe.contentDocument || iframe.contentWindow.document;

				var iframeCookieStr = '';
				try { iframeCookieStr = doc.cookie || ''; } catch (e) { hadAccessError = true; }
				if (iframeCookieStr) {
					result.cookies = parseCookieString(iframeCookieStr, parsedUrl.hostname);
				}

				try {
					var scriptEls = doc.querySelectorAll('script[src]');
					scriptEls.forEach(function (s) {
						var src = s.getAttribute('src') || '';
						if (src) {
							try { src = new URL(src, parsedUrl.href).href; } catch (_u) {}
							result.scripts.push(src);
						}
					});
				} catch (e) { hadAccessError = true; }

				try {
					var iframeEls = doc.querySelectorAll('iframe[src]');
					iframeEls.forEach(function (f) {
						var src = f.getAttribute('src') || '';
						if (src) {
							try { src = new URL(src, parsedUrl.href).href; } catch (_u) {}
							result.scripts.push(src);
						}
					});
				} catch (e) { hadAccessError = true; }
			} catch (e) { hadAccessError = true; }
			if (hadAccessError && !result.cookies.length && !result.scripts.length) {
				result.issue = 'iframeInaccessible';
			}
			return result;
		}

		function finish(result) {
			if (finished) return;
			finished = true;
			if (timer) clearTimeout(timer);
			try { container.removeChild(iframe); } catch (e) {}
			var finalResult = result || readIframe();
			done(finalResult);
		}

		// Adaptive settle: read immediately, wait 700ms, recheck.
		// If stable, finish early. Otherwise wait 800ms more.
		iframe.addEventListener('load', function () {
			// Cancel the pre-load fallback timer — page loaded, settle phase starts.
			if (timer) { clearTimeout(timer); timer = null; }
			// Settle watchdog for slow pages/scripts.
			timer = setTimeout(function () {
				// Use last successful read instead of discarding results.
				if (lastRead) {
					lastRead.issue = lastRead.issue || 'settleTimeout';
					finish(lastRead);
				} else {
					finish(emptyResult('settleTimeout'));
				}
			}, settleTimeoutMs);

			var firstRead = readIframe();
			lastRead = firstRead;
			var firstCount = firstRead.cookies.length + firstRead.scripts.length;

			setTimeout(function () {
				if (finished) return;
				var secondRead = readIframe();
				lastRead = secondRead;
				var secondCount = secondRead.cookies.length + secondRead.scripts.length;

				if (secondCount === firstCount) {
					// Stable — finish early.
					finish(secondRead);
				} else {
					// Still changing — wait a bit more.
					setTimeout(function () {
						if (finished) return;
						lastRead = readIframe();
						finish(lastRead);
					}, 800);
				}
			}, 700);
		});

		// Timeout fallback in case load never fires (e.g. network error, 404).
		timer = setTimeout(function () { finish(emptyResult('iframeTimeout')); }, loadTimeoutMs);

		// Navigate the iframe.
		iframe.src = parsedUrl.href;
	}

	/**
	 * Parse a document.cookie string into an array of cookie objects.
	 */
	function parseCookieString(cookieStr, domain) {
		var result = [];
		if (!cookieStr) return result;
		var pairs = cookieStr.split(';');
		for (var i = 0; i < pairs.length; i++) {
			var pair = pairs[i].trim();
			if (!pair) continue;
			var eqPos = pair.indexOf('=');
			var name = eqPos > -1 ? pair.substring(0, eqPos).trim() : pair.trim();
			if (!name) continue;
			result.push({
				name: name,
				domain: domain,
				duration: 'session',
				description: '',
				category: 'uncategorized',
				source: 'browser',
			});
		}
		return result;
	}

	/**
	 * Parse the current browser's document.cookie into a name->value map.
	 */
	function parseBrowserCookies() {
		var map = {};
		var str = document.cookie || '';
		if (!str) return map;
		str.split(';').forEach(function (pair) {
			pair = pair.trim();
			var eq = pair.indexOf('=');
			if (eq > 0) {
				map[pair.substring(0, eq).trim()] = pair.substring(eq + 1).trim();
			}
		});
		return map;
	}

	/**
	 * Find cookies in `after` that weren't in `before`.
	 */
	function diffCookies(before, after, domain) {
		var result = [];
		domain = domain || location.hostname;
		for (var name in after) {
			if (!before.hasOwnProperty(name)) {
				result.push({
					name: name,
					domain: domain,
					duration: 'session',
					description: '',
					category: 'uncategorized',
					source: 'browser',
				});
			}
		}
		return result;
	}

	function autoCategorize(scope) {
		var btn = document.getElementById('faz-auto-cat-btn');
		FAZ.btnLoading(btn, true);
		var scopeAll = (scope === 'all');

		// Step 1: Fetch all cookies.
		FAZ.get('cookies').then(function (data) {
			var allCookies = Array.isArray(data) ? data : (data.items || []);

			var targetCookies;
			if (scopeAll) {
				targetCookies = allCookies;
			} else {
				// Find the uncategorized category ID.
				var uncatId = null;
				categories.forEach(function (c) {
					if (c.slug === 'uncategorized') uncatId = c.id;
				});
				targetCookies = allCookies.filter(function (c) {
					return !c.category || (uncatId && String(c.category) === String(uncatId));
				});
			}

			if (!targetCookies.length) {
				FAZ.btnLoading(btn, false);
				FAZ.notify(scopeAll ? 'No cookies to process' : 'No uncategorized cookies to process');
				return;
			}

			var names = targetCookies.map(function (c) { return c.name; });

			// Step 2: Scrape cookie info from cookie.is.
			return FAZ.post('cookies/scrape', { names: names }).then(function (results) {
				results = Array.isArray(results) ? results : [];

				// Build slug → category ID map.
				var catMap = {};
				categories.forEach(function (c) { catMap[c.slug] = c.id; });

				// Step 3: Update each cookie that got a real category.
				var updates = [];
				var categorized = 0;

				results.forEach(function (info) {
					if (!info.found || info.category === 'uncategorized') return;
					var targetCatId = catMap[info.category];
					if (!targetCatId) return;

					var cookie = targetCookies.find(function (c) { return c.name === info.name; });
					if (!cookie) return;

					categorized++;
					var updateData = { category: parseInt(targetCatId, 10) };
					if (info.description) {
						updateData.description = { en: info.description };
					}
					updates.push(FAZ.put('cookies/' + (cookie.id || cookie.cookie_id), updateData));
				});

				if (!updates.length) {
					FAZ.btnLoading(btn, false);
					FAZ.notify('No cookies could be auto-categorized');
					return;
				}

				return Promise.all(updates).then(function () {
					FAZ.btnLoading(btn, false);
					FAZ.notify('Auto-categorized ' + categorized + ' cookies');
					loadCookies();
					loadCategories();
				});
			});
		}).catch(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify('Auto-categorize failed', 'error');
		});
	}

	// ── Cookie Definitions ──────────────────────────────────
	function loadDefinitionsStatus() {
		var el = document.getElementById('faz-defs-status');
		if (!el) return;
		FAZ.get('cookies/definitions').then(function (meta) {
			if (!meta || !meta.has_definitions) {
				el.textContent = 'No definitions downloaded yet. Click "Update Definitions" to download.';
				return;
			}
			var count = meta.count || 0;
			var updated = meta.updated_at || '';
			el.textContent = count + ' cookie definitions loaded' + (updated ? ' - last updated: ' + updated : '');
		}).catch(function () {
			el.textContent = 'Could not load definitions status.';
		});
	}

	function updateDefinitions() {
		var btn = document.getElementById('faz-update-defs-btn');
		var el = document.getElementById('faz-defs-status');
		FAZ.btnLoading(btn, true);
		if (el) el.textContent = 'Downloading definitions from GitHub...';

		FAZ.post('cookies/definitions/update').then(function (result) {
			FAZ.btnLoading(btn, false);
			if (result && result.success) {
				FAZ.notify(result.message || 'Definitions updated');
				loadDefinitionsStatus();
			} else {
				FAZ.notify(result.message || 'Update failed', 'error');
				if (el) el.textContent = 'Update failed: ' + (result.message || 'unknown error');
			}
		}).catch(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify('Failed to update definitions', 'error');
			if (el) el.textContent = 'Update failed. Check your network connection.';
		});
	}

})();
