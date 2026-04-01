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
			var defLang = (window.fazConfig && fazConfig.languages && fazConfig.languages['default']) || '';
			if (defLang && typeof val[defLang] === 'string' && val[defLang] !== '') {
				return val[defLang];
			}
			if (typeof val.en === 'string' && val.en !== '') {
				return val.en;
			}
			for (var key in val) {
				if (Object.prototype.hasOwnProperty.call(val, key) && typeof val[key] === 'string' && val[key] !== '') {
					return val[key];
				}
			}
			return '';
		}
		return String(val);
	}

	FAZ.ready(function () {
		loadCategories(true);
		loadCookies();
		var saveCatsBtn = document.getElementById('faz-save-categories');
		if (saveCatsBtn) saveCatsBtn.addEventListener('click', saveCategoryEdits);

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

		// Custom Blocking Rules
		loadCustomRules();
		var addRuleBtn = document.getElementById('faz-add-rule');
		if (addRuleBtn) addRuleBtn.addEventListener('click', function () { addRuleRow('', ''); });
		var saveRulesBtn = document.getElementById('faz-save-rules-btn');
		if (saveRulesBtn) saveRulesBtn.addEventListener('click', saveCustomRules);

		// Blocker Templates
		loadBlockerTemplates();
	});

	function loadCategories(refreshEditor) {
		FAZ.get('cookies/categories').then(function (data) {
			categories = Array.isArray(data) ? data : (data.items || []);
			categoryEditorData = categories;
			renderCategories();
			if (refreshEditor) renderCategoryEditor();
		}).catch(function (err) { console.error('FAZ: Failed to load categories', err); });
	}

	// ── Category editor (name & description editing) ──────────────────
	var categoryEditorData = []; // raw category objects for the editor

	function getCategoryEditorLang() {
		return (window.fazConfig && fazConfig.languages && fazConfig.languages['default'])
			? fazConfig.languages['default']
			: 'en';
	}

	/**
	 * Strip <p> wrapper tags from a string but keep inner HTML (links, bold, etc.).
	 * Converts <p> boundaries to line breaks for textarea display.
	 */
	function stripParagraphTags(html) {
		if (!html || typeof html !== 'string') return html || '';
		return html
			.replace(/<\/p>\s*<p>/gi, '\n')  // </p><p> → newline
			.replace(/<\/?p[^>]*>/gi, '')     // remaining <p> and </p> → remove
			.trim();
	}

	function renderCategoryEditor() {
		var tbody = document.getElementById('faz-category-edit-rows');
		if (!tbody) return;
		tbody.innerHTML = '';
		if (!categoryEditorData || !categoryEditorData.length) return;

		var lang = getCategoryEditorLang();

		categoryEditorData.forEach(function (cat) {
			var tr = document.createElement('tr');
			tr.setAttribute('data-cat-id', cat.id);

			// Slug (read-only)
			var tdSlug = document.createElement('td');
			var code = document.createElement('code');
			code.textContent = cat.slug || '';
			tdSlug.appendChild(code);
			tr.appendChild(tdSlug);

			// Name (editable input)
			var tdName = document.createElement('td');
			var nameInput = document.createElement('input');
			nameInput.type = 'text';
			nameInput.className = 'faz-input faz-input-sm faz-cat-edit-name';
			var nameObj = cat.name;
			nameInput.value = (typeof nameObj === 'object' && nameObj !== null)
				? (nameObj[lang] || nameObj.en || Object.values(nameObj)[0] || '')
				: (nameObj || '');
			tdName.appendChild(nameInput);
			tr.appendChild(tdName);

			// Description (editable textarea)
			var tdDesc = document.createElement('td');
			var descInput = document.createElement('textarea');
			descInput.className = 'faz-textarea faz-cat-edit-desc';
			descInput.rows = 2;
			descInput.style.cssText = 'font-size:13px;min-height:50px;width:100%;';
			var descObj = cat.description;
			var rawDesc = (typeof descObj === 'object' && descObj !== null)
				? (descObj[lang] || descObj.en || Object.values(descObj)[0] || '')
				: (descObj || '');
			descInput.value = stripParagraphTags(rawDesc);
			tdDesc.appendChild(descInput);
			tr.appendChild(tdDesc);

			tbody.appendChild(tr);
		});
	}

	function saveCategoryEdits() {
		var rows = document.querySelectorAll('#faz-category-edit-rows tr[data-cat-id]');
		if (!rows.length) return;

		var lang = getCategoryEditorLang();
		var saveBtn = document.getElementById('faz-save-categories');
		if (saveBtn) saveBtn.disabled = true;

		var promises = [];

		rows.forEach(function (row) {
			var id = row.getAttribute('data-cat-id');
			var nameVal = row.querySelector('.faz-cat-edit-name').value;
			var descVal = row.querySelector('.faz-cat-edit-desc').value;

			// Find the original category data to preserve other language keys
			var original = null;
			for (var i = 0; i < categoryEditorData.length; i++) {
				if (String(categoryEditorData[i].id) === String(id)) {
					original = categoryEditorData[i];
					break;
				}
			}

			// Merge: copy all existing language keys, then update the current language
			var nameObj = {};
			if (original && typeof original.name === 'object' && original.name !== null) {
				Object.keys(original.name).forEach(function (k) { nameObj[k] = original.name[k]; });
			}
			nameObj[lang] = nameVal;

			var descObj = {};
			if (original && typeof original.description === 'object' && original.description !== null) {
				Object.keys(original.description).forEach(function (k) { descObj[k] = original.description[k]; });
			}
			descObj[lang] = descVal;

			promises.push(
				FAZ.put('cookies/categories/' + id, {
					name: nameObj,
					description: descObj
				})
			);
		});

		Promise.allSettled(promises).then(function (results) {
			var failed = results.filter(function (r) { return r.status === 'rejected'; }).length;
			if (failed === 0) {
				FAZ.notify('Categories saved.', 'success');
			} else {
				FAZ.notify((results.length - failed) + ' saved, ' + failed + ' failed.', 'error');
			}
			loadCategories(true);
			if (saveBtn) saveBtn.disabled = false;
		});
	}

	function loadCookies(done) {
		var params = {};
		if (activeCat !== 'all') params.category = activeCat;
		FAZ.get('cookies', params).then(function (data) {
			cookies = Array.isArray(data) ? data : (data.items || []);
			renderCookies();
			if (typeof done === 'function') done();
		}).catch(function (err) {
			console.error('[FAZ] loadCookies FAILED:', err);
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

			// Wrap duration and description as multilingual objects using the default language
			// while preserving existing translations on edit.
			var defLang = (window.fazConfig && fazConfig.languages && fazConfig.languages['default']) || 'en';
			if (typeof data.duration === 'string') {
				var durObj = (isEdit && cookie.duration && typeof cookie.duration === 'object' && !Array.isArray(cookie.duration))
					? Object.assign({}, cookie.duration)
					: {};
				durObj[defLang] = data.duration;
				data.duration = durObj;
			}
			if (typeof data.description === 'string') {
				var descObj = (isEdit && cookie.description && typeof cookie.description === 'object' && !Array.isArray(cookie.description))
					? Object.assign({}, cookie.description)
					: {};
				descObj[defLang] = data.description;
				data.description = descObj;
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

	var IFRAME_LOAD_TIMEOUT = 15000; // Max wait for iframe load (ms). Increased for sites with cache/optimization plugins.
	var CONCURRENCY = 2;             // Parallel iframes (reduced for slow hosts).
	var EARLY_STOP_THRESHOLD = 7;    // Stop after N consecutive pages with no new findings.
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
				var mainUrls = deduplicateUrls(result.urls || []);

				// WooCommerce priority URLs — prepend and exempt from early stop.
				var rawPriority = deduplicateUrls(result.priority_urls || []);
				var prioritySet = {};
				for (var p = 0; p < rawPriority.length; p++) {
					prioritySet[rawPriority[p]] = true;
				}

				// Build final URL list: priority URLs first, then main (deduped).
				var mainSeen = {};
				for (var m = 0; m < rawPriority.length; m++) {
					mainSeen[rawPriority[m]] = true;
				}
				var urls = rawPriority.slice();
				for (var u = 0; u < mainUrls.length; u++) {
					if (!mainSeen[mainUrls[u]]) {
						mainSeen[mainUrls[u]] = true;
						urls.push(mainUrls[u]);
					}
				}

				scanMetrics.urlsDiscovered = urls.length;
				scanOptions.priorityUrls = prioritySet;

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
					console.log('[FAZ Scanner] Scan complete:', {
						cookies: collectedCookies.length,
						scripts: collectedScripts.length,
						scriptUrls: collectedScripts.slice(0, 10),
						cookieNames: collectedCookies.map(function(c) { return c.name; }),
						diagnostics: diagnostics,
					});
					// Always run server-side scan on the HOMEPAGE to catch data-src /
					// litespeed deferred scripts. Uses site root, not urls[0] which
					// may be a WooCommerce page after priority URL prepending.
					if (urls.length > 0) {
						statusEl.textContent = 'Enriching with server scan...';
						var homepageUrl = result.home_url || urls[0];
						FAZ.post('scans/server-scan', { url: homepageUrl }).then(function (serverResult) {
							// Merge server-discovered scripts (deduped).
							var existingScripts = {};
							collectedScripts.forEach(function (s) { existingScripts[s] = true; });
							if (serverResult && Array.isArray(serverResult.scripts)) {
								serverResult.scripts.forEach(function (s) {
									if (!existingScripts[s]) {
										collectedScripts.push(s);
										existingScripts[s] = true;
									}
								});
							}
							// Merge server-discovered cookies (deduped by name).
							var existingCookies = {};
							collectedCookies.forEach(function (c) { existingCookies[(c.name || '').toLowerCase()] = true; });
							if (serverResult && Array.isArray(serverResult.cookies)) {
								serverResult.cookies.forEach(function (c) {
									if (c.name && !existingCookies[c.name.toLowerCase()]) {
										collectedCookies.push(c);
										existingCookies[c.name.toLowerCase()] = true;
									}
								});
							}
							scanMetrics.cookiesFound = collectedCookies.length;
							scanMetrics.scriptsFound = collectedScripts.length;
							console.log('[FAZ Scanner] After server merge:', {
								cookies: collectedCookies.length,
								scripts: collectedScripts.length,
							});
							doImport();
						}).catch(function () {
							console.warn('[FAZ Scanner] Server scan failed, using iframe results only');
							doImport();
						});
						return;
					}
					doImport();

					function doImport() {
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
					} // end doImport
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
		var priorityUrls = options.priorityUrls || {};  // URL hash map — exempt from early stop counter.
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

				// Early stop check — priority URLs (e.g. WooCommerce pages) are exempt.
				var isPriority = !!priorityUrls[urls[idx]];
				if (isPriority) {
					// Priority URL: reset counter if it found something, but never increment.
					if (foundNew) noNewCount = 0;
				} else {
					noNewCount = foundNew ? 0 : noNewCount + 1;
				}
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
					// Collect script URLs from src, data-src, data-litespeed-src
					// (covers LiteSpeed/WP Rocket/Autoptimize delay loaders).
					var scriptEls = doc.querySelectorAll('script[src], script[data-src], script[data-litespeed-src]');
					scriptEls.forEach(function (s) {
						var src = s.getAttribute('src') || s.getAttribute('data-src') || s.getAttribute('data-litespeed-src') || '';
						if (src) {
							try { src = new URL(src, parsedUrl.href).href; } catch (_u) {}
							result.scripts.push(src);
						}
					});
				} catch (e) { hadAccessError = true; }

				try {
					var iframeEls = doc.querySelectorAll('iframe[src], iframe[data-src]');
					iframeEls.forEach(function (f) {
						var src = f.getAttribute('src') || f.getAttribute('data-src') || '';
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

		// Navigate the iframe — append scan param to disable script blocking.
		var scanUrl = new URL(parsedUrl.href);
		scanUrl.searchParams.set('faz_scanning', '1');
		iframe.src = scanUrl.href;
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

				// Step 3: Build update queue (serialized to avoid 503 rate limiting).
				var updateQueue = [];
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
						var descLang = getCategoryEditorLang();
						var descObj = (cookie.description && typeof cookie.description === 'object' && !Array.isArray(cookie.description))
							? Object.assign({}, cookie.description)
							: {};
						descObj[descLang] = info.description;
						updateData.description = descObj;
					}
					updateQueue.push({ id: cookie.id || cookie.cookie_id, data: updateData, name: cookie.name });
				});

				if (!updateQueue.length) {
					FAZ.btnLoading(btn, false);
					FAZ.notify('No cookies could be auto-categorized');
					return;
				}

				// Execute updates sequentially (one at a time) to avoid 503 rate limiting.
				var completed = 0;
				var failed = 0;
				function processNext() {
					if (completed + failed >= updateQueue.length) {
						FAZ.btnLoading(btn, false);
						var msg = 'Auto-categorized ' + completed + '/' + categorized + ' cookies';
						if (failed > 0) msg += ' (' + failed + ' failed)';
						FAZ.notify(msg, failed > 0 ? 'error' : 'success');
						loadCookies();
						loadCategories();
						return;
					}
					var item = updateQueue[completed + failed];
					FAZ.put('cookies/' + item.id, item.data).then(function () {
						completed++;
						console.log('[FAZ Auto-categorize] Updated "' + item.name + '" (' + completed + '/' + updateQueue.length + ')');
						processNext();
					}).catch(function (err) {
						failed++;
						console.error('[FAZ Auto-categorize] Failed "' + item.name + '":', err);
						processNext();
					});
				}
				processNext();
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

	/* ── Custom Blocking Rules ────────────────────────── */

	var ruleCategories = ['analytics', 'marketing', 'functional', 'performance'];

	function loadCustomRules() {
		FAZ.get('settings').then(function (data) {
			var rules = (data.script_blocking && Array.isArray(data.script_blocking.custom_rules))
				? data.script_blocking.custom_rules
				: [];
			var tbody = document.getElementById('faz-custom-rules-body');
			if (!tbody) return;
			while (tbody.firstChild) tbody.removeChild(tbody.firstChild);
			rules.forEach(function (r) {
				addRuleRow(r.pattern || '', r.category || '');
			});
		});
	}

	function addRuleRow(pattern, category) {
		var tbody = document.getElementById('faz-custom-rules-body');
		if (!tbody) return;
		var tr = document.createElement('tr');

		var tdPattern = document.createElement('td');
		var input = document.createElement('input');
		input.type = 'text';
		input.className = 'faz-input';
		input.placeholder = 'e.g. custom-tracker.com/script.js';
		input.value = pattern;
		input.setAttribute('data-rule', 'pattern');
		input.style.width = '100%';
		tdPattern.appendChild(input);

		var tdCategory = document.createElement('td');
		var select = document.createElement('select');
		select.className = 'faz-input';
		select.setAttribute('data-rule', 'category');
		select.style.width = '100%';
		var emptyOpt = document.createElement('option');
		emptyOpt.value = '';
		emptyOpt.textContent = '\u2014 Select \u2014';
		select.appendChild(emptyOpt);
		ruleCategories.forEach(function (cat) {
			var opt = document.createElement('option');
			opt.value = cat;
			opt.textContent = cat.charAt(0).toUpperCase() + cat.slice(1);
			if (cat === category) opt.selected = true;
			select.appendChild(opt);
		});
		tdCategory.appendChild(select);

		var tdActions = document.createElement('td');
		tdActions.style.textAlign = 'center';
		var removeBtn = document.createElement('button');
		removeBtn.type = 'button';
		removeBtn.className = 'faz-btn faz-btn-danger faz-btn-sm';
		removeBtn.textContent = 'Remove';
		removeBtn.addEventListener('click', function () { tr.remove(); });
		tdActions.appendChild(removeBtn);

		tr.appendChild(tdPattern);
		tr.appendChild(tdCategory);
		tr.appendChild(tdActions);
		tbody.appendChild(tr);
	}

	function collectCustomRules() {
		var tbody = document.getElementById('faz-custom-rules-body');
		if (!tbody) return { rules: [], invalid: 0 };
		var rules = [];
		var invalid = 0;
		tbody.querySelectorAll('tr').forEach(function (tr) {
			var patternInput = tr.querySelector('[data-rule="pattern"]');
			var categorySelect = tr.querySelector('[data-rule="category"]');
			var pattern = patternInput ? patternInput.value.trim() : '';
			var category = categorySelect ? categorySelect.value : '';
			if (!pattern && !category) return; // empty row, skip
			if (!pattern || !category) {
				invalid++;
				return;
			}
			rules.push({ pattern: pattern, category: category });
		});
		return { rules: rules, invalid: invalid };
	}

	function saveCustomRules() {
		var btn = document.getElementById('faz-save-rules-btn');
		var collected = collectCustomRules();
		if (collected.invalid > 0) {
			FAZ.notify(collected.invalid + ' rule(s) incomplete — fill in both pattern and category', 'error');
			return;
		}
		FAZ.btnLoading(btn, true);
		FAZ.get('settings').then(function (current) {
			current.script_blocking = current.script_blocking || {};
			current.script_blocking.custom_rules = collected.rules;
			return FAZ.post('settings', current);
		}).then(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify('Custom rules saved');
		}).catch(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify('Failed to save custom rules', 'error');
		});
	}

	/* ── Blocker Templates ────────────────────────────── */

	function loadBlockerTemplates() {
		FAZ.get('blocker-templates').then(function (templates) {
			var container = document.getElementById('faz-blocker-templates');
			if (!container) return;

			// Clear loading text safely
			while (container.firstChild) container.removeChild(container.firstChild);

			if (!templates || !templates.length) {
				var emptyMsg = document.createElement('p');
				emptyMsg.style.color = 'var(--faz-text-muted)';
				emptyMsg.textContent = 'No templates available.';
				container.appendChild(emptyMsg);
				return;
			}

			templates.forEach(function (tpl) {
				var card = document.createElement('button');
				card.type = 'button';
				card.className = 'faz-template-card';

				var name = document.createElement('div');
				name.className = 'faz-template-card-name';
				name.textContent = tpl.name;
				card.appendChild(name);

				var desc = document.createElement('div');
				desc.className = 'faz-template-card-desc';
				desc.textContent = tpl.description;
				card.appendChild(desc);

				var badge = document.createElement('span');
				badge.className = 'faz-template-card-badge';
				badge.textContent = tpl.category;
				card.appendChild(badge);

				card.addEventListener('click', function () {
					var patterns = Array.isArray(tpl.patterns) ? tpl.patterns : [];
					if (!patterns.length && !(Array.isArray(tpl.cookies) && tpl.cookies.length)) {
						FAZ.notify('No patterns or cookies in template.', 'error');
						return;
					}
					var added = 0;
					patterns.forEach(function (pattern) {
						addRuleRow(pattern, tpl.category);
						added++;
					});
					if (added) {
						FAZ.notify('Added ' + added + ' rules from ' + tpl.name, 'success');
					}

					// Also create cookies from the template if they don't already exist
					var tplCookies = Array.isArray(tpl.cookies) ? tpl.cookies : [];
					if (!tplCookies.length) return;

					// Resolve category ID from slug
					var catId = null;
					categories.forEach(function (c) {
						if (c.slug === tpl.category) catId = c.id;
					});
					if (!catId) {
						FAZ.notify('Category "' + tpl.category + '" not found — cookies not added', 'error');
						return;
					}

					// Fetch all cookies to check for duplicates (global `cookies` may be filtered)
					FAZ.get('cookies').then(function (data) {
						var allCookies = Array.isArray(data) ? data : (data.items || []);
						var existingNames = {};
						allCookies.forEach(function (c) {
							if (c.name) existingNames[String(c.name).toLowerCase()] = true;
						});

						var lang = getCategoryEditorLang();
						var creates = [];
						tplCookies.forEach(function (cookieName) {
							if (existingNames[String(cookieName).toLowerCase()]) return;
							var descObj = {};
							descObj[lang] = '';
							var durObj = {};
							durObj[lang] = '';
							creates.push(FAZ.post('cookies', {
								name: cookieName,
								domain: '',
								duration: durObj,
								description: descObj,
								category: parseInt(catId, 10)
							}));
						});

						if (!creates.length) {
							FAZ.notify('All cookies from ' + tpl.name + ' already exist', 'info');
							return;
						}

						return Promise.all(creates).then(function () {
							FAZ.notify(creates.length + ' cookie(s) added from ' + tpl.name, 'success');
							loadCookies();
							loadCategories();
						});
					}).catch(function () {
						FAZ.notify('Failed to create cookies from template', 'error');
					});
				});

				container.appendChild(card);
			});
		}).catch(function () {
			var container = document.getElementById('faz-blocker-templates');
			if (container) {
				while (container.firstChild) container.removeChild(container.firstChild);
				var errMsg = document.createElement('p');
				errMsg.style.color = 'var(--faz-danger, red)';
				errMsg.textContent = 'Failed to load templates.';
				container.appendChild(errMsg);
			}
		});
	}

})();
