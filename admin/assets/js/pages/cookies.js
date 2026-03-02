/**
 * FAZ Cookie Manager — Cookies Page JS
 */
(function () {
	'use strict';

	var categories = [];
	var cookies = [];
	var activeCat = 'all';   // category ID or 'all'
	var activeCatName = '';  // display name for heading

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

	function loadCookies() {
		var params = {};
		if (activeCat !== 'all') params.category = activeCat;
		FAZ.get('cookies', params).then(function (data) {
			cookies = Array.isArray(data) ? data : (data.items || []);
			renderCookies();
		}).catch(function () {
			cookies = [];
			renderCookies();
		});
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
			return;
		}

		cookies.forEach(function (cookie) {
			var tr = document.createElement('tr');

			var tdCheck = document.createElement('td');
			var cb = document.createElement('input');
			cb.type = 'checkbox';
			cb.className = 'faz-cookie-check';
			cb.value = cookie.id || cookie.cookie_id;
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

			tr.appendChild(tdActions);
			tbody.appendChild(tr);
		});
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
			FAZ.del('cookies/' + (cookie.id || cookie.cookie_id)).then(function () {
				FAZ.notify('Cookie deleted');
				loadCookies();
				loadCategories();
			}).catch(function () {
				FAZ.notify('Failed to delete cookie', 'error');
			});
		});
	}

	// ── Browser-Based Cookie Scanner ───────────────────────
	// Loads pages in hidden iframes to discover cookies set by JavaScript
	// (e.g. _ga, _fbp) that server-side scanning cannot detect.

	var IFRAME_LOAD_TIMEOUT = 8000; // Max wait for iframe load (ms).
	var IFRAME_SETTLE_DELAY = 2000; // Wait after load for JS cookies to be set.

	function startScan(maxPages) {
		var btn = document.getElementById('faz-scan-btn');
		var dropdown = document.getElementById('faz-scan-dropdown');
		FAZ.btnLoading(btn, true);
		btn.textContent = 'Scanning...';

		// Build progress UI.
		var progress = document.createElement('div');
		progress.className = 'faz-scan-progress';
		var bar = document.createElement('div');
		bar.className = 'faz-scan-bar';
		var statusEl = document.createElement('span');
		statusEl.className = 'faz-scan-status';
		statusEl.textContent = 'Discovering pages...';
		progress.appendChild(bar);
		progress.appendChild(statusEl);
		dropdown.parentNode.insertBefore(progress, dropdown.nextSibling);

		var requestPages = maxPages > 0 ? maxPages : 99999;

		// Step 1: Ask server for URLs to scan.
		FAZ.post('scans/discover', { max_pages: requestPages }).then(function (result) {
			var urls = result.urls || [];
			if (!urls.length) {
				finishScan(btn, progress, 'No pages found to scan.', true);
				return;
			}
			statusEl.textContent = 'Scanning 0/' + urls.length + ' pages...';
			bar.style.width = '0%';

			// Step 2: Scan each URL sequentially via iframe.
			scanUrls(urls, 0, [], [], function (collectedCookies, collectedScripts) {
				bar.style.width = '100%';
				statusEl.textContent = 'Saving results...';

				// Step 3: Send results to server (include URLs for httpOnly header check).
				FAZ.post('scans/import', {
					cookies: collectedCookies,
					pages_scanned: urls.length,
					scripts: collectedScripts,
					urls: urls,
				}).then(function (res) {
					var total = res.total_cookies || collectedCookies.length;
					finishScan(btn, progress, 'Scan complete \u2014 ' + total + ' cookies found on ' + urls.length + ' pages');
					loadCookies();
					loadCategories();
				}).catch(function () {
					finishScan(btn, progress, 'Scan finished but failed to save results.', true);
				});
			}, bar, statusEl);
		}).catch(function () {
			finishScan(btn, progress, 'Failed to discover pages.', true);
		});
	}

	function finishScan(btn, progress, message, isError) {
		FAZ.btnLoading(btn, false);
		btn.textContent = 'Scan Site \u25BE';
		if (progress.parentNode) progress.parentNode.removeChild(progress);
		FAZ.notify(message, isError ? 'error' : 'success');
	}

	/**
	 * Sequentially scan URLs via hidden iframes.
	 *
	 * @param {string[]} urls           URLs to scan.
	 * @param {number}   index          Current index.
	 * @param {Array}    collectedCookies Accumulated cookie objects.
	 * @param {Array}    collectedScripts Accumulated script URLs.
	 * @param {Function} done           Callback(cookies, scripts) when all done.
	 * @param {Element}  bar            Progress bar element.
	 * @param {Element}  statusEl       Status text element.
	 */
	function scanUrls(urls, index, collectedCookies, collectedScripts, done, bar, statusEl) {
		if (index >= urls.length) {
			done(collectedCookies, collectedScripts);
			return;
		}

		var pct = Math.round(((index) / urls.length) * 100);
		bar.style.width = pct + '%';
		statusEl.textContent = 'Scanning ' + (index + 1) + '/' + urls.length + ' pages...';

		// Snapshot cookies before loading page.
		var cookiesBefore = parseBrowserCookies();

		scanSingleUrl(urls[index], function (pageResult) {
			// Diff cookies: find new ones set during this page load.
			var cookiesAfter = parseBrowserCookies();
			var newCookies = diffCookies(cookiesBefore, cookiesAfter);

			// Add page-detected cookies (from iframe document.cookie).
			if (pageResult.cookies) {
				pageResult.cookies.forEach(function (c) {
					if (!hasCookie(collectedCookies, c.name)) {
						collectedCookies.push(c);
					}
				});
			}
			// Add new cookies detected via browser diff.
			newCookies.forEach(function (c) {
				if (!hasCookie(collectedCookies, c.name)) {
					collectedCookies.push(c);
				}
			});
			// Collect scripts.
			if (pageResult.scripts) {
				pageResult.scripts.forEach(function (src) {
					if (collectedScripts.indexOf(src) === -1) {
						collectedScripts.push(src);
					}
				});
			}

			// Next URL.
			scanUrls(urls, index + 1, collectedCookies, collectedScripts, done, bar, statusEl);
		});
	}

	/**
	 * Load a single URL in a hidden iframe, read cookies and scripts.
	 *
	 * @param {string}   url  The URL to scan.
	 * @param {Function} done Callback({cookies, scripts}).
	 */
	function scanSingleUrl(url, done) {
		var container = document.getElementById('faz-scan-frame');
		container.textContent = ''; // Remove previous iframe.

		var iframe = document.createElement('iframe');
		iframe.style.cssText = 'width:1px;height:1px;border:none;';
		iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin');
		container.appendChild(iframe);

		var finished = false;
		var timer = null;

		function finish() {
			if (finished) return;
			finished = true;
			if (timer) clearTimeout(timer);

			var result = { cookies: [], scripts: [] };

			try {
				var doc = iframe.contentDocument || iframe.contentWindow.document;

				// Read document.cookie from the iframe.
				var iframeCookieStr = '';
				try { iframeCookieStr = doc.cookie || ''; } catch (e) { /* cross-origin */ }
				if (iframeCookieStr) {
					var domain = location.hostname;
					var parsed = parseCookieString(iframeCookieStr, domain);
					result.cookies = parsed;
				}

				// Collect <script src> URLs.
				try {
					var scriptEls = doc.querySelectorAll('script[src]');
					scriptEls.forEach(function (s) {
						var src = s.getAttribute('src') || '';
						if (src && result.scripts.indexOf(src) === -1) {
							result.scripts.push(src);
						}
					});
				} catch (e) { /* cross-origin */ }

				// Collect <iframe src> URLs (embedded content like YouTube).
				try {
					var iframeEls = doc.querySelectorAll('iframe[src]');
					iframeEls.forEach(function (f) {
						var src = f.getAttribute('src') || '';
						if (src && result.scripts.indexOf(src) === -1) {
							result.scripts.push(src);
						}
					});
				} catch (e) { /* cross-origin */ }
			} catch (e) {
				// Couldn't access iframe content — cross-origin or error.
			}

			// Clean up iframe.
			try { container.removeChild(iframe); } catch (e) {}

			done(result);
		}

		// On load: wait for JS cookies to settle, then read.
		iframe.addEventListener('load', function () {
			setTimeout(finish, IFRAME_SETTLE_DELAY);
		});

		// Timeout fallback in case load never fires.
		timer = setTimeout(finish, IFRAME_LOAD_TIMEOUT);

		// Navigate the iframe.
		iframe.src = url;
	}

	/**
	 * Parse a document.cookie string into an array of cookie objects.
	 *
	 * @param {string} cookieStr  The raw cookie string.
	 * @param {string} domain     The domain to assign.
	 * @return {Array} Array of {name, domain, duration, description, category}.
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
	 * Parse the current browser's document.cookie into a name→value map.
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
	function diffCookies(before, after) {
		var result = [];
		var domain = location.hostname;
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

	/**
	 * Check if a cookie name already exists in the collected array.
	 */
	function hasCookie(arr, name) {
		for (var i = 0; i < arr.length; i++) {
			if (arr[i].name === name) return true;
		}
		return false;
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
			el.textContent = count + ' cookie definitions loaded' + (updated ? ' — last updated: ' + updated : '');
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
