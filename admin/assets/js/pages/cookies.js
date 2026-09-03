/**
 * FAZ Cookie Manager - Cookies Page JS
 */
(function () {
	'use strict';

	// Signal that this file loaded and ran, for the view's blocked-script
	// watchdog (cookies.js can be blocked by ad blockers / browser shields that
	// match its "cookie" filename, leaving the page silently inert).
	window.fazCookiesBooted = true;

	// i18n helper — looks up fazConfig.i18n.<key> with dot-notation, falls back to provided string.
	function __(key, fallback) {
		var parts = key.split('.');
		var obj = (window.fazConfig && window.fazConfig.i18n) || {};
		for (var i = 0; i < parts.length; i++) {
			if (!obj || typeof obj !== 'object') { return fallback; }
			obj = obj[parts[i]];
		}
		return typeof obj === 'string' ? obj : fallback;
	}

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
		updateRestoreBar();
		updateVisitorCheckBar();
		// A capture session opened before this page load (another tab, or a
		// crawl a reload killed) is invisible without asking the server.
		refreshActiveScanSession();
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
		// Add Service dropdown — manual service registration (#161).
		var addSvcBtn = document.getElementById('faz-add-service-btn');
		var addSvcDropdown = document.getElementById('faz-add-service-dropdown');
		var svcSelect = document.getElementById('faz-service-select');
		var registerSvcBtn = document.getElementById('faz-register-service-btn');
		var catalogueLoaded = false;
		var catalogueRequest = null;
		function loadCatalogueServices() {
			// In-flight guard: a single request at a time. Toggling the menu
			// quickly must not fire concurrent GETs nor let a late failure
			// overwrite an already-loaded list.
			if (catalogueRequest) return catalogueRequest;
			catalogueRequest = FAZ.get('cookies/catalogue-services').then(function (data) {
				var services = (data && data.services) || [];
				svcSelect.innerHTML = '';
				var placeholder = document.createElement('option');
				placeholder.value = '';
				placeholder.textContent = __('cookies.selectService', 'Select a service…');
				svcSelect.appendChild(placeholder);
				services.forEach(function (s) {
					var opt = document.createElement('option');
					opt.value = s.id;
					opt.textContent = s.label + (s.registered ? ' ✓' : '');
					opt.disabled = !!s.registered;
					svcSelect.appendChild(opt);
				});
				catalogueLoaded = true;
			}).catch(function () {
				svcSelect.innerHTML = '';
				var failOpt = document.createElement('option');
				failOpt.value = '';
				failOpt.textContent = __('cookies.servicesLoadFailed', 'Could not load services');
				svcSelect.appendChild(failOpt);
			}).then(function () {
				catalogueRequest = null;
			});
			return catalogueRequest;
		}
		if (addSvcBtn && addSvcDropdown && svcSelect && registerSvcBtn) {
			addSvcBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				addSvcDropdown.classList.toggle('open');
				if (addSvcDropdown.classList.contains('open') && !catalogueLoaded) {
					loadCatalogueServices();
				}
			});
			var svcMenu = addSvcDropdown.querySelector('.faz-dropdown-menu');
			if (svcMenu) svcMenu.addEventListener('click', function (e) { e.stopPropagation(); });
			registerSvcBtn.addEventListener('click', function () {
				var sid = svcSelect.value;
				if (!sid) return;
				registerSvcBtn.disabled = true;
				FAZ.post('cookies/register-service', { service_id: sid }).then(function (res) {
					var added = (res && typeof res.added === 'number') ? res.added : 0;
					var label = (res && res.service && res.service.label) || sid;
					// One i18n key for the whole sentence so translators can reorder
					// the service label, count and text and handle plural forms.
					FAZ.notify(
						__('cookies.serviceRegistered', '%1$s: %2$d cookie(s) registered')
							.replace('%1$s', function () { return label; })
							.replace('%2$d', function () { return String(added); }),
						'success'
					);
					addSvcDropdown.classList.remove('open');
					catalogueLoaded = false;
					loadCookies();
					loadCategories();
				}).catch(function () {
					FAZ.notify(__('cookies.registerFailed', 'Could not register service.'), 'error');
				}).then(function () {
					registerSvcBtn.disabled = false;
				});
			});
		}

		document.addEventListener('click', function () {
			scanDropdown.classList.remove('open');
			acDropdown.classList.remove('open');
			if (addSvcDropdown) addSvcDropdown.classList.remove('open');
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
			FAZ.confirm(__('cookies.bulkDeleteConfirm', 'Delete selected cookie(s)?') + ' (' + ids.length + ')').then(function (ok) {
				if (!ok) return;
				FAZ.post('cookies/bulk-delete', { ids: ids }).then(function (res) {
					var deletedCount = (res && typeof res.deleted === 'number') ? res.deleted : ids.length;
					FAZ.notify(deletedCount + ' ' + __('cookies.cookieDeleted', 'Cookie deleted.'));
					loadCookies();
					loadCategories();
					// `restorable` is what makes the undo affordance appear; the
					// bar reads the bin itself so it survives a reload.
					updateRestoreBar();
				}).catch(function (err) {
					// A refused purge is not a generic failure: the server
					// aborted because it could not save the undo snapshot, and
					// the rows are still there. Saying "delete failed" would
					// leave the admin unsure whether some rows went.
					var snapshotFailed = !!(err && err.code === 'faz_recycle_bin_write_failed');
					FAZ.notify(
						snapshotFailed
							? __('cookies.bulkDeleteSnapshotFailed', 'Nothing was deleted: the undo snapshot could not be saved, so the cookies were left in place.')
							: __('cookies.bulkDeleteFailed', 'Bulk delete failed.'),
						'error'
					);
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

			// Sale / Sharing flags (CCPA/CPRA). The column is hidden entirely on
			// pure-GDPR sites (no active banner with a Do-Not-Sell surface —
			// data-show-ccpa="0" from the PHP view): the flags would drive
			// nothing visitor-facing there. Skipping the cell also means
			// saveCategoryEdits() never sends the flags, so stored values are
			// preserved for a later law switch.
			var catTable = document.getElementById('faz-category-edit-table');
			if (catTable && catTable.getAttribute('data-show-ccpa') === '0') {
				tbody.appendChild(tr);
				return;
			}
			// The "necessary" category is never a sale or a share (it is exempt
			// from the opt-out by definition), so we don't offer its toggles.
			var tdSaleShare = document.createElement('td');
			if (cat.slug === 'necessary') {
				var naSpan = document.createElement('span');
				naSpan.style.color = 'var(--faz-text-muted)';
				naSpan.textContent = __('cookies.notApplicable', '—');
				tdSaleShare.appendChild(naSpan);
			} else {
				var mkToggle = function (kind, label, checked) {
					var wrap = document.createElement('label');
					wrap.style.cssText = 'display:flex;align-items:center;gap:4px;font-size:12px;white-space:nowrap;';
					var cb = document.createElement('input');
					cb.type = 'checkbox';
					cb.className = 'faz-cat-edit-' + kind;
					cb.checked = !!checked;
					wrap.appendChild(cb);
					wrap.appendChild(document.createTextNode(label));
					return wrap;
				};
				// Default to opt-out-able (true) when the flag is absent, matching
				// the schema default.
				var sellOn = (cat.sell_personal_data === undefined) ? true : !!cat.sell_personal_data;
				var shareOn = (cat.share_personal_data === undefined) ? true : !!cat.share_personal_data;
				tdSaleShare.appendChild(mkToggle('sell', __('cookies.sell', 'Sell'), sellOn));
				tdSaleShare.appendChild(mkToggle('share', __('cookies.share', 'Share'), shareOn));
			}
			tr.appendChild(tdSaleShare);

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
			var sellCb = row.querySelector('.faz-cat-edit-sell');
			var shareCb = row.querySelector('.faz-cat-edit-share');

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

			var payload = {
				name: nameObj,
				description: descObj
			};
			// Only send the sale/sharing flags for rows that expose the toggles
			// (every category except "necessary"), so the necessary row's PUT
			// never carries them.
			if (sellCb) {
				payload.sell_personal_data = sellCb.checked;
			}
			if (shareCb) {
				payload.share_personal_data = shareCb.checked;
			}

			promises.push(
				FAZ.put('cookies/categories/' + id, payload)
			);
		});

		Promise.allSettled(promises).then(function (results) {
			var failed = results.filter(function (r) { return r.status === 'rejected'; }).length;
			if (failed === 0) {
				FAZ.notify(__('cookies.categoriesSaved', 'Categories saved.'), 'success');
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

	/**
	 * Mark the entries this scan may offer to delete.
	 *
	 * TWO conditions, both required. The single-scan diff says the catalogue
	 * knew a cookie the run did not observe; the server's earned-deletable list
	 * says it has now been missing from MISSED_SCANS_THRESHOLD consecutive
	 * COMPLETE scans. One missed scan proves nothing — a site that delays its
	 * JavaScript until interaction never fires its trackers inside a passive
	 * iframe, and flow-only cookies (checkout, login) are never reached at all,
	 * yet both are set for every real visitor.
	 *
	 * `earnedSet` absent (an older server, or a response that lost the field) is
	 * treated as "nothing has earned deletion". Failing closed is the only safe
	 * direction here: the action this feeds deletes rows from the site's public
	 * cookie declaration.
	 */
	function setStaleCookies(previousSet, currentSet, earnedSet) {
		staleCookieNames = {};
		staleCookieCount = 0;
		Object.keys(previousSet || {}).forEach(function (key) {
			if (currentSet && currentSet[key]) { return; }
			if (!earnedSet || !earnedSet[key]) { return; }
			staleCookieNames[key] = true;
			staleCookieCount++;
		});
	}

	/**
	 * Server-side consecutive-miss tally, re-keyed into this page's key form.
	 *
	 * The server already emits Controller::canonical_key() form, which is the
	 * same shape getStaleKey() builds. Re-deriving it here rather than trusting
	 * the string keeps the two ends from silently drifting apart: a mismatch
	 * would not throw, it would just intersect to nothing and leave a stale bar
	 * that never appears — inert while looking wired.
	 */
	function getEarnedDeletableSet(result) {
		var earned = {};
		var keys = result && result.importResult && result.importResult.deletable_stale_keys;
		if (!Array.isArray(keys)) { return earned; }
		keys.forEach(function (raw) {
			var parts = String(raw).split('|');
			var name = parts.shift();
			var key = getStaleKeyFromName(name, parts.join('|'));
			if (key) { earned[key] = true; }
		});
		return earned;
	}

	function resetStaleCookies() {
		staleCookieNames = {};
		staleCookieCount = 0;
	}

	function scanCoverageIsComplete(result, maxPages) {
		var diagnostics = result && result.diagnostics;
		return !!result
			&& maxPages === 0
			&& result.incremental === false
			&& !result.earlyStopReason
			// A cancelled crawl is a NEW way to be incomplete. Without this term
			// a run stopped after forty pages would count as full coverage and
			// offer to delete every cookie it never reached.
			&& !result.stoppedReason
			&& diagnostics
			&& typeof diagnostics.totalIssues === 'number'
			&& isFinite(diagnostics.totalIssues)
			&& diagnostics.totalIssues === 0;
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
		deleteAllBtn.textContent = __('cookies.deleteAllStale', 'Delete all stale');
		deleteAllBtn.addEventListener('click', deleteAllStaleCookies);
		staleBar.appendChild(deleteAllBtn);
	}

	/**
	 * Names a scan saw in the browser jar but could not attribute to any page.
	 *
	 * The scan runs in the administrator's own browser, so a same-origin iframe
	 * exposes every cookie that browser already held for the domain — including
	 * wp-admin-only ones a visitor never receives. The engine holds those apart
	 * and the import route reports them back as `jar_only_cookies` without ever
	 * writing them to the declaration.
	 *
	 * Prefer the server's list: it is the authoritative record of what was
	 * withheld, after the same sanitising and de-duplication the import applied.
	 * Fall back to the engine's own array so an older or partial import response
	 * still discloses something rather than silently nothing.
	 *
	 * @param {Object} res Resolved scan run.
	 * @return {string[]} Unique cookie names, never null.
	 */
	function jarOnlyNames(res) {
		var fromServer = res && res.importResult && res.importResult.jar_only_cookies;
		var source = Array.isArray(fromServer)
			? fromServer
			: (Array.isArray(res && res.jarCookies) ? res.jarCookies : []);
		var seen = {};
		var names = [];
		source.forEach(function (entry) {
			var name = (typeof entry === 'string')
				? entry
				: ((entry && entry.name) ? String(entry.name) : '');
			name = name.trim();
			// Object.create(null) is not available on the ES5 floor this file
			// targets, so guard the prototype keys explicitly.
			if (!name || Object.prototype.hasOwnProperty.call(seen, name)) { return; }
			seen[name] = true;
			names.push(name);
		});
		return names;
	}

	/**
	 * Disclose the withheld jar cookies after a scan.
	 *
	 * Withholding them is correct and is pinned by tests. Saying nothing about
	 * it was not: the import route's own comment says "surfacing the count is
	 * the point… an admin who recognises a name as a real site cookie can add it
	 * by hand", and until now no product surface read either field. A scan could
	 * therefore drop a genuine first-party cookie from the declaration and
	 * report a clean run.
	 *
	 * Collapsed by default — the count is the headline, the names are for the
	 * administrator who wants to check them.
	 *
	 * @param {Object} res Resolved scan run.
	 */
	function updateJarOnlyBar(res) {
		var bar = document.getElementById('faz-jar-bar');
		if (!bar) { return; }
		bar.textContent = '';
		var names = jarOnlyNames(res);
		if (!names.length) {
			bar.style.display = 'none';
			return;
		}
		bar.style.display = '';

		var msg = document.createElement('span');
		msg.textContent = __('cookies.jarOnlyHint', '%d cookie(s) were already in your browser when the scan started, so they could not be attributed to any page and were not imported.')
			.replace('%d', function () { return String(names.length); });
		bar.appendChild(msg);

		var details = document.createElement('details');
		details.className = 'faz-jar-details';
		var summary = document.createElement('summary');
		summary.textContent = __('cookies.jarOnlyToggle', 'Show the names');
		details.appendChild(summary);

		var explain = document.createElement('p');
		explain.textContent = __('cookies.jarOnlyExplain', 'If you recognise one as a cookie your site really sets, add it manually with Add Cookie.');
		details.appendChild(explain);

		var list = document.createElement('ul');
		names.forEach(function (name) {
			var li = document.createElement('li');
			// textContent, never innerHTML: these names come from a scanned
			// page's cookie jar and are attacker-influenceable content.
			li.textContent = name;
			list.appendChild(li);
		});
		details.appendChild(list);
		bar.appendChild(details);
	}

	/**
	 * Disclose the outcome of the anonymous server-side visitor check.
	 *
	 * After every browser scan the server replays the visited URLs without a
	 * login and diffs its Set-Cookie observations against the logged-in pass.
	 * Three buckets come back: cookies only the anonymous pass received
	 * (declared, with this strip as their provenance), jar-bucket names the
	 * anonymous pass confirmed as real site cookies (promoted to declared),
	 * and names that stay admin-only (reported, never declared).
	 *
	 * Read on page load: the replay finishes in the background minutes after
	 * the import response, so the strip describes the latest COMPLETED check.
	 * Coverage is headers-only and the strip must say so — the copy must never
	 * claim the visitor's view of the site has been fully checked.
	 */
	function updateVisitorCheckBar() {
		var bar = document.getElementById('faz-visitor-check-bar');
		if (!bar) { return; }
		FAZ.get('scans/info').then(function (info) {
			var check = info && info.visitor_check;
			bar.textContent = '';
			if (!check) {
				bar.style.display = 'none';
				return;
			}
			var visitorOnly = Array.isArray(check.visitor_only) ? check.visitor_only : [];
			var promoted = Array.isArray(check.jar_promoted) ? check.jar_promoted : [];
			var adminOnly = Array.isArray(check.admin_only) ? check.admin_only : [];
			bar.style.display = '';

			var title = document.createElement('strong');
			title.textContent = __('cookies.visitorCheckTitle', 'Server-side visitor check (headers only) — scan #%s:')
				.replace('%s', function () { return String(check.scan_id || ''); });
			bar.appendChild(title);

			var list = document.createElement('ul');
			function addBucket(names, key, fallback) {
				if (!names.length) { return; }
				var li = document.createElement('li');
				// textContent, never innerHTML: cookie names are content a
				// scanned page (or a third party it embeds) controls.
				li.textContent = __(key, fallback)
					.replace('%1$d', function () { return String(names.length); })
					.replace('%2$s', function () { return names.join(', '); });
				list.appendChild(li);
			}
			addBucket(visitorOnly, 'cookies.visitorCheckVisitorOnly', '%1$d cookie(s) were set for the anonymous check but never seen by the logged-in browser scan, and were added to the declaration: %2$s');
			addBucket(promoted, 'cookies.visitorCheckPromoted', '%1$d cookie(s) the scan could not attribute were confirmed by the anonymous check — the site sets them for visitors, so they are declared: %2$s');
			addBucket(adminOnly, 'cookies.visitorCheckAdminOnly', '%1$d cookie(s) were seen only in your browser and not confirmed for visitors; they stay reported, not declared: %2$s');
			if (list.childNodes.length) {
				bar.appendChild(list);
			} else {
				var none = document.createElement('p');
				none.textContent = __('cookies.visitorCheckNoDiff', 'The anonymous check found no cookie differences against the browser scan.');
				bar.appendChild(none);
			}

			var disclaimer = document.createElement('p');
			disclaimer.className = 'faz-help';
			disclaimer.textContent = __('cookies.visitorCheckDisclaimer', 'This check re-fetches the scanned pages without a login and compares Set-Cookie headers only. It cannot see cookies that JavaScript sets for anonymous visitors, nor cookies set only after an interaction (for example an add-to-cart request), so a clean result does not mean the visitor view is fully verified.');
			bar.appendChild(disclaimer);
		}).catch(function () {
			bar.style.display = 'none';
		});
	}

	/**
	 * Show an undo affordance while a bulk delete is still reversible.
	 *
	 * Every bulk delete snapshots the rows it removes into a bounded recycle bin
	 * before touching them, but until now nothing in the product could reach the
	 * restore route: the reversibility the code documents existed only for
	 * someone hand-crafting an authenticated REST POST.
	 *
	 * Read from the server on page load rather than shown only in the seconds
	 * after the delete. The bin persists across reloads, and an administrator
	 * notices a wrong purge after navigating away — precisely when a toast is
	 * already gone. FAZ.notify() is deliberately not used for the same reason:
	 * it is text-only and auto-dismisses in three seconds, which is the wrong
	 * lifetime for a recovery action.
	 */
	function updateRestoreBar() {
		var bar = document.getElementById('faz-restore-bar');
		if (!bar) { return; }
		FAZ.get('cookies/deleted-batches').then(function (data) {
			var batches = (data && Array.isArray(data.batches)) ? data.batches : [];
			bar.textContent = '';
			if (!batches.length) {
				bar.style.display = 'none';
				return;
			}
			bar.style.display = '';

			var newest = batches[0] || {};
			var count = (typeof newest.count === 'number') ? newest.count : 0;
			// The bin is bounded by batch count, not by age, so "recently
			// deleted" was a claim nothing checked: a purge from eight months
			// ago read exactly like one from eight seconds ago, and Undo would
			// put back rows whose categories and policy text have since moved
			// on. The server sends the age already formatted and translated.
			var age = (typeof newest.deleted_at_human === 'string') ? newest.deleted_at_human : '';
			var msg = document.createElement('span');
			msg.textContent = age
				/* translators: 1: number of cookies, 2: human-readable age such as "3 hours". */
				? __('cookies.restoreDeletedHintAged', '%1$d deleted cookie(s) can still be restored (deleted %2$s ago).')
					.replace('%1$d', function () { return String(count); })
					.replace('%2$s', function () { return age; })
				: __('cookies.restoreDeletedHint', '%d recently deleted cookie(s) can still be restored.')
					.replace('%d', function () { return String(count); });
			bar.appendChild(msg);

			var restoreBtn = document.createElement('button');
			restoreBtn.type = 'button';
			restoreBtn.className = 'faz-btn faz-btn-sm faz-restore-deleted';
			restoreBtn.textContent = __('cookies.restoreDeleted', 'Undo delete');
			restoreBtn.addEventListener('click', function () {
				restoreBtn.disabled = true;
				FAZ.post('cookies/restore-deleted', {}).then(function (res) {
					var restored = (res && typeof res.restored === 'number') ? res.restored : 0;
					var skipped = (res && typeof res.skipped === 'number') ? res.skipped : 0;
					// A zero restore is not a success. It happens when every row
					// in the batch is already live again — the scanner
					// re-discovered it, or someone re-added it by hand — and
					// reporting that as a green "0 cookie(s) restored." told the
					// admin nothing about why, next to a bar still offering the
					// undo. Name the reason and drop the success tone.
					if (0 === restored) {
						FAZ.notify(
							skipped > 0
								? __('cookies.restoreAllPresent', 'Nothing to restore: those cookies are already in the list again.')
								: __('cookies.restoreNoneRestored', 'No cookies were restored.'),
							'info'
						);
					} else if (skipped > 0) {
						FAZ.notify(
							__('cookies.restoreSucceededPartial', '%1$d cookie(s) restored. %2$d were already in the list.')
								.replace('%1$d', String(restored))
								.replace('%2$d', String(skipped)),
							'success'
						);
					} else {
						FAZ.notify(__('cookies.restoreSucceeded', '%d cookie(s) restored.')
							.replace('%d', function () { return String(restored); }), 'success');
					}
					loadCookies();
					loadCategories();
				}).catch(function (err) {
					// An empty bin answers 404 faz_nothing_to_restore. That is a
					// state, not a fault — say so plainly instead of surfacing a
					// raw REST error.
					//
					// A 403 faz_restore_requires_unfiltered_html carries a
					// specific, carefully written explanation: the batch holds
					// blocker scripts this account may not save, so it was left
					// intact for an administrator. Discarding that for a generic
					// failure message left the restorer with no idea their data
					// was safe, or what to do next — so it is surfaced verbatim.
					var code = err && err.code;
					var emptied = 'faz_nothing_to_restore' === code;
					var needsCap = 'faz_restore_requires_unfiltered_html' === code;
					var serverMessage = (err && typeof err.message === 'string' && err.message) ? err.message : '';
					FAZ.notify(
						emptied
							? __('cookies.nothingToRestore', 'There is nothing left to restore.')
							: (needsCap && serverMessage
								? serverMessage
								: __('cookies.restoreFailed', 'Could not restore the deleted cookies.')),
						emptied ? 'info' : (needsCap ? 'warning' : 'error')
					);
				}).then(function () {
					restoreBtn.disabled = false;
					updateRestoreBar();
				});
			});
			bar.appendChild(restoreBtn);
		}).catch(function () {
			// Best effort: a failed read just means no undo affordance, never a
			// broken page.
			bar.textContent = '';
			bar.style.display = 'none';
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
		allName.textContent = __('cookies.allCookies', 'All Cookies');
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
				badge.textContent = __('cookies.hidden', 'hidden');
				badge.title = __('cookies.hiddenFromFrontend', 'Hidden from frontend');
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
			p.textContent = __('cookies.noCookiesFound', 'No cookies found.');
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
			// Flag cookies that carry a third-country (Schrems II) transfer
			// disclosure so admins can spot them at a glance.
			if (cookie.transfer && cookie.transfer.enabled) {
				var transferBadge = document.createElement('span');
				transferBadge.className = 'faz-cookie-transfer-badge';
				transferBadge.textContent = __('cookies.transferBadge', '3rd country');
				transferBadge.title = __('cookies.transferBadgeTitle', 'Transfers personal data to a third country (Schrems II)');
				tdName.appendChild(transferBadge);
			}
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
			editBtn.textContent = __('cookies.edit', 'Edit');
			editBtn.addEventListener('click', function () {
				var cookieId = getCookieId(cookie);
				if (!cookieId) {
					openCookieModal(cookie);
					return;
				}

				editBtn.disabled = true;
				FAZ.get('cookies/' + cookieId, { context: 'edit' }).then(function (fullCookie) {
					openCookieModal(fullCookie || cookie);
				}).catch(function () {
					FAZ.notify(__('cookies.cookieLoadFailed', 'Failed to load cookie details.'), 'error');
				}).then(function () {
					editBtn.disabled = false;
				});
			});
			tdActions.appendChild(editBtn);

			var delBtn = document.createElement('button');
			delBtn.className = 'faz-btn faz-btn-outline faz-btn-sm';
			delBtn.textContent = __('cookies.delete', 'Delete');
			delBtn.style.color = 'var(--faz-danger)';
			delBtn.addEventListener('click', function () { deleteCookie(cookie); });
			tdActions.appendChild(delBtn);

			if (isStale) {
				var staleBtn = document.createElement('button');
				staleBtn.className = 'faz-btn faz-btn-sm';
				staleBtn.textContent = __('cookies.deleteStale', 'Delete stale');
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

		var canEditScripts = !!(window.fazConfig && window.fazConfig.canEditScripts);

		var fields = [
			{ label: __('cookies.nameLabel', 'Cookie Name'), path: 'name', type: 'text' },
			{ label: __('cookies.domainLabel', 'Domain'), path: 'domain', type: 'text' },
			{ label: __('cookies.durationLabel', 'Duration'), path: 'duration', type: 'text', placeholder: __('cookies.durationPlaceholder', 'e.g. 1 year') },
			{ label: __('cookies.descriptionLabel', 'Description'), path: 'description', type: 'textarea' },
		];

		// Only expose opt-in/opt-out script fields to users with the
		// `unfiltered_html` capability. Without this guard the admin UI would
		// always POST these fields (even empty), tripping the REST sanitize
		// callback's 403 for multisite site-admins who lack the capability.
		if (canEditScripts) {
			fields.push({ label: __('cookies.optInScriptLabel', 'Opt-in Script (runs when category is accepted)'), path: 'opt_in_script', type: 'textarea', placeholder: __('cookies.optInScriptPlaceholder', '// JS executed on consent accept\n// e.g. gtag("event", "consent_granted");') });
			fields.push({ label: __('cookies.optOutScriptLabel', 'Opt-out Script (runs when category is rejected/revoked)'), path: 'opt_out_script', type: 'textarea', placeholder: __('cookies.optOutScriptPlaceholder', '// JS executed on consent reject or revoke') });
		}

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
				if (f.path === 'opt_in_script' || f.path === 'opt_out_script') {
					input.maxLength = 10000;
				}
			} else {
				input = document.createElement('input');
				input.type = f.type;
				input.className = 'faz-input';
			}
			input.dataset.field = f.path;
			if (f.placeholder) input.placeholder = f.placeholder;
			if (isEdit && cookie[f.path]) input.value = textVal(cookie[f.path]);
			group.appendChild(input);
			if (f.path === 'opt_in_script' || f.path === 'opt_out_script') {
				var scriptNotice = document.createElement('p');
				// #767676 is the minimum WCAG-AA-passing gray on white (4.5:1
				// contrast); #888 used previously was 3.54:1 and failed AA.
				// font-size pulled up from 11px to 12px to give the helper
				// text some breathing room without breaking the compact form
				// layout.
				scriptNotice.style.cssText = 'font-size:12px;color:#767676;margin:4px 0 0;';
				scriptNotice.textContent = __('cookies.scriptNotice', 'Note: code entered here is included in the page source and visible to all visitors.');
				group.appendChild(scriptNotice);
			}
			form.appendChild(group);
		});

		// International data transfers (Schrems II) fieldset. Purely a
		// transparency disclosure — it does not gate or reweight any consent
		// choice; default OFF so existing cookies are unchanged until ticked.
		var existingTransfer = (isEdit && cookie && cookie.transfer && typeof cookie.transfer === 'object' && !Array.isArray(cookie.transfer)) ? cookie.transfer : {};

		var transferGroup = document.createElement('div');
		transferGroup.className = 'faz-form-group faz-transfer-fieldset';

		var transferTitle = document.createElement('label');
		transferTitle.textContent = __('cookies.transferTitle', 'International data transfers (Schrems II)');
		transferGroup.appendChild(transferTitle);

		var transferEnabledLabel = document.createElement('label');
		transferEnabledLabel.style.cssText = 'display:flex;align-items:flex-start;gap:8px;font-weight:400;margin-bottom:8px;';
		var transferEnabled = document.createElement('input');
		transferEnabled.type = 'checkbox';
		transferEnabled.className = 'faz-transfer-enabled';
		transferEnabled.checked = !!existingTransfer.enabled;
		transferEnabledLabel.appendChild(transferEnabled);
		transferEnabledLabel.appendChild(document.createTextNode(__('cookies.transferEnabledLabel', 'This cookie may transfer personal data to a country without an EU adequacy decision')));
		transferGroup.appendChild(transferEnabledLabel);

		var countryLabel = document.createElement('label');
		countryLabel.style.cssText = 'font-weight:400;font-size:12px;';
		countryLabel.textContent = __('cookies.transferCountryLabel', 'Recipient country / countries');
		transferGroup.appendChild(countryLabel);
		var countryInput = document.createElement('input');
		countryInput.type = 'text';
		countryInput.className = 'faz-input faz-transfer-country';
		countryInput.placeholder = __('cookies.transferCountryPlaceholder', 'e.g. United States');
		countryInput.value = textVal(existingTransfer.countries) || '';
		transferGroup.appendChild(countryInput);

		var safeguardLabel = document.createElement('label');
		safeguardLabel.style.cssText = 'font-weight:400;font-size:12px;margin-top:8px;';
		safeguardLabel.textContent = __('cookies.transferSafeguardLabel', 'Safeguard (optional)');
		transferGroup.appendChild(safeguardLabel);
		var safeguardInput = document.createElement('textarea');
		safeguardInput.className = 'faz-textarea faz-transfer-safeguard';
		safeguardInput.rows = 2;
		safeguardInput.placeholder = __('cookies.transferSafeguardPlaceholder', 'e.g. Standard Contractual Clauses, EU-US Data Privacy Framework, or explicit consent (Art. 49(1)(a))');
		safeguardInput.value = textVal(existingTransfer.safeguard) || '';
		transferGroup.appendChild(safeguardInput);

		var transferHelp = document.createElement('p');
		transferHelp.style.cssText = 'font-size:12px;color:#767676;margin:4px 0 0;';
		transferHelp.textContent = __('cookies.transferHelp', 'Naming a third-country transfer helps you obtain informed consent. This plugin does not provide legal advice — describe the safeguard your provider relies on.');
		transferGroup.appendChild(transferHelp);

		form.appendChild(transferGroup);

		// F014 fix: the country/safeguard inputs stayed editable (and their
		// values still saved into data.transfer.countries/.safeguard) even
		// when "enabled" was left unticked, so an admin could fill them in,
		// forget to tick the checkbox, save "successfully" and the
		// disclosure would silently never render (render_transfer_disclosure()
		// returns '' unless transfer.enabled is true). Grey the inputs out
		// and disable them while the checkbox is off — same
		// bind-to-checkbox/sync-on-change pattern used for the close-button
		// sub-toggle in banner.js (bindCloseSubToggle).
		(function bindTransferInputs() {
			var sync = function () {
				var enabled = !!transferEnabled.checked;
				countryInput.disabled = !enabled;
				safeguardInput.disabled = !enabled;
				countryLabel.style.opacity = enabled ? '1' : '0.5';
				countryInput.style.opacity = enabled ? '1' : '0.5';
				safeguardLabel.style.opacity = enabled ? '1' : '0.5';
				safeguardInput.style.opacity = enabled ? '1' : '0.5';
			};
			transferEnabled.addEventListener('change', sync);
			sync();
		})();

		// Category dropdown
		var catGroup = document.createElement('div');
		catGroup.className = 'faz-form-group';
		var catLabel = document.createElement('label');
		catLabel.textContent = __('cookies.category', 'Category');
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
		cancelBtn.textContent = __('cookies.cancel', 'Cancel');
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

			// Assemble the third-country transfer disclosure. Country/safeguard
			// are wrapped into multilingual objects under the default language,
			// preserving any other-language values on edit (same pattern as
			// description/duration above).
			var countriesObj = (isEdit && cookie.transfer && cookie.transfer.countries && typeof cookie.transfer.countries === 'object' && !Array.isArray(cookie.transfer.countries))
				? Object.assign({}, cookie.transfer.countries)
				: {};
			countriesObj[defLang] = countryInput.value;
			var safeguardObj = (isEdit && cookie.transfer && cookie.transfer.safeguard && typeof cookie.transfer.safeguard === 'object' && !Array.isArray(cookie.transfer.safeguard))
				? Object.assign({}, cookie.transfer.safeguard)
				: {};
			safeguardObj[defLang] = safeguardInput.value;
			data.transfer = {
				enabled: transferEnabled.checked,
				countries: countriesObj,
				safeguard: safeguardObj
			};

			FAZ.btnLoading(saveBtn, true);
			var promise = isEdit
				? FAZ.put('cookies/' + (cookie.id || cookie.cookie_id), data)
				: FAZ.post('cookies', data);

			promise.then(function () {
				m.close();
				FAZ.notify(isEdit ? __('cookies.cookieUpdated', 'Cookie updated.') : __('cookies.cookieAdded', 'Cookie added.'));
				loadCookies();
				loadCategories();
			}).catch(function () {
				FAZ.btnLoading(saveBtn, false);
				FAZ.notify(__('cookies.cookieSaveFailed', 'Failed to save cookie.'), 'error');
			});
		});
	}

	function deleteCookie(cookie) {
		FAZ.confirm(__('cookies.cookieDeleteConfirm', 'Delete cookie "%s"?').replace('%s', cookie.name || '')).then(function (ok) {
			if (!ok) return;
			FAZ.del('cookies/' + getCookieId(cookie)).then(function () {
				FAZ.notify(__('cookies.cookieDeleted', 'Cookie deleted.'));
				loadCookies();
				loadCategories();
			}).catch(function () {
				FAZ.notify(__('cookies.cookieDeleteFailed', 'Failed to delete cookie.'), 'error');
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
			FAZ.notify(__('cookies.staleDeleted', 'Stale cookie deleted.'));
			loadCookies();
			loadCategories();
		}).catch(function () {
			FAZ.notify(__('cookies.staleDeleteFailed', 'Failed to delete stale cookie.'), 'error');
		});
	}

	function deleteAllStaleCookies() {
		if (!staleCookieCount) return;
		FAZ.confirm(__('cookies.staleAllConfirm', 'Remove these cookie-policy entries? Cookies that load only after an interaction, during a flow, or as httpOnly cookies may still be present even if the scan did not observe them.')).then(function (ok) {
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
					FAZ.notify(__('cookies.staleNone', 'No stale cookies to delete.'));
					return;
				}
				// `reason: 'stale'` opts this call into the server-side threshold
				// check. Only this caller sends it: the general multi-select bulk
				// delete stays unscoped, so an administrator can still delete a
				// hand-added or never-scanned row by hand.
				FAZ.post('cookies/bulk-delete', { ids: ids, reason: 'stale' }).then(function (res) {
					var deletedCount = (res && typeof res.deleted === 'number') ? res.deleted : ids.length;
					var refusedCount = (res && typeof res.refused === 'number') ? res.refused : 0;
					staleCookieNames = {};
					staleCookieCount = 0;
					var message = deletedCount + ' ' + __('cookies.staleDeleted', 'stale cookie(s) deleted.');
					if (refusedCount > 0) {
						message += ' ' + __('cookies.staleRefusedNotEarned', '%d entry(ies) were kept: they have not yet been missing from enough complete scans.')
							.replace('%d', function () { return String(refusedCount); });
					}
					FAZ.notify(message);
					loadCookies();
					loadCategories();
					updateRestoreBar();
				}).catch(function (err) {
					var snapshotFailed = !!(err && err.code === 'faz_recycle_bin_write_failed');
					FAZ.notify(
						snapshotFailed
							? __('cookies.bulkDeleteSnapshotFailed', 'Nothing was deleted: the undo snapshot could not be saved, so the cookies were left in place.')
							: __('cookies.staleDeleteAllFailed', 'Failed to delete stale cookies.'),
						'error'
					);
				});
			}).catch(function () {
				FAZ.notify(__('cookies.staleLoadFailed', 'Failed to load cookies for stale cleanup.'), 'error');
			});
		});
	}

	// ── Cookie scan (browser-based) ────────────────────────
	// The engine itself lives in admin/assets/js/modules/scan-engine.js and is
	// shared with the setup wizard, so both surfaces run the identical scan.
	// What stays here is this page's own business: the progress UI, and the
	// "stale cookie" bookkeeping that compares the catalogue before and after.

	// ── Already-open capture session (survives a reload) ───
	// A crawl only lives as long as the tab that drives it, but its capture
	// session on the server does not: reload the page mid-scan (or lose the
	// tab) and the session stays locked for up to fifteen minutes, previously
	// discoverable only as a bare `faz_browser_scan_in_progress` 409 with no
	// way out short of SSH. On load the page now asks the server what it knows
	// (scans/session) and, when a session is open, shows it here with an
	// explicit way to end it.
	//
	// What is shown is only what the server genuinely knows — when the session
	// started, how many Set-Cookie observations arrived, when anything last
	// reached it. The client-side page counter died with the old tab, so no
	// percentage bar is faked for it. A session something is still driving
	// (recent activity) pulses and is described as capturing; one nothing has
	// touched is shown amber and stalled, because the difference is exactly
	// what the administrator needs to decide whether ending it is safe.
	// Ending is NEVER automatic — a live session may be another tab genuinely
	// crawling, and only the human can tell that from an abandoned one.
	// `epoch` fences the async gap: a scans/session response that was already
	// in flight when the panel was explicitly removed (End clicked, a new scan
	// started) must not resurrect the panel with its stale snapshot — every
	// removal bumps the epoch and responses from an older one are dropped.
	var activeSessionPanel = { wrap: null, timer: null, els: null, scanId: '', epoch: 0 };

	// Recent-activity horizon, in seconds. During a crawl the capture path
	// touches the session on every page load, so seconds-scale silence means
	// nothing is driving it — except on fully page-cached sites, where only
	// the five-minute heartbeat gets through; hence a generous margin and
	// wording that reports facts ("nothing since …") rather than verdicts.
	var SESSION_STALL_AFTER_S = 90;

	function removeActiveSessionPanel() {
		activeSessionPanel.epoch++;
		if (activeSessionPanel.timer) {
			clearInterval(activeSessionPanel.timer);
			activeSessionPanel.timer = null;
		}
		if (activeSessionPanel.wrap && activeSessionPanel.wrap.parentNode) {
			activeSessionPanel.wrap.parentNode.removeChild(activeSessionPanel.wrap);
		}
		activeSessionPanel.wrap = null;
		activeSessionPanel.els = null;
		activeSessionPanel.scanId = '';
	}

	function refreshActiveScanSession() {
		var epoch = activeSessionPanel.epoch;
		FAZ.get('scans/session').then(function (info) {
			if (epoch !== activeSessionPanel.epoch) { return; }
			renderActiveScanSession(info);
		}, function () {
			// The page must keep working when the endpoint is unreachable —
			// the panel is an affordance, not a dependency.
		});
	}

	function formatClockTime(ts) {
		if (!ts) { return ''; }
		// `[]` means "the browser's locale", which is not necessarily the
		// admin's: a WP admin set to it_IT on an en-US browser would get a
		// 12-hour clock inside otherwise-Italian text. fazConfig.locale is the
		// WP user_locale ('it_IT'), converted to a BCP-47 tag the way
		// geo-routing.js and dashboard.js already do it.
		var loc = (window.fazConfig && window.fazConfig.locale) || document.documentElement.lang;
		loc = loc ? String(loc).replace(/_/g, '-') : undefined;
		try {
			return new Date(ts * 1000).toLocaleTimeString(loc, { hour: '2-digit', minute: '2-digit' });
		} catch (e) {
			// Only a malformed tag reaches here (toLocaleTimeString throws
			// RangeError on one), so re-passing `loc` would throw again — the
			// locale genuinely has to be dropped. The OPTIONS do not: without
			// them the fallback also loses the zero-padded 24h shape and can
			// return "9:05:03 AM" where every neighbouring string says "09:05".
			return new Date(ts * 1000).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
		}
	}

	function buildActiveSessionPanel() {
		var dropdown = document.getElementById('faz-scan-dropdown');
		if (!dropdown) { return null; }

		var wrap = document.createElement('div');
		wrap.className = 'faz-scan-progress-wrap faz-scan-session-wrap';
		wrap.id = 'faz-scan-session-panel';

		var progress = document.createElement('div');
		progress.className = 'faz-scan-progress';
		var bar = document.createElement('div');
		bar.className = 'faz-scan-bar';
		// Full width on purpose: the server does not know how many pages the
		// dead tab meant to visit, so any partial width would be an invented
		// percentage. The bar is a state light here — pulsing primary while
		// something is driving the session, flat amber once nothing is.
		bar.style.width = '100%';
		var statusEl = document.createElement('span');
		statusEl.className = 'faz-scan-status';
		progress.appendChild(bar);
		progress.appendChild(statusEl);
		wrap.appendChild(progress);

		var box = document.createElement('div');
		box.className = 'faz-scan-held';
		box.setAttribute('role', 'status');
		box.setAttribute('aria-live', 'polite');
		var notice = document.createElement('p');
		notice.className = 'faz-scan-held-text';
		var detail = document.createElement('p');
		detail.className = 'faz-scan-held-text';
		var actions = document.createElement('div');
		actions.className = 'faz-scan-held-actions';
		var endBtn = document.createElement('button');
		endBtn.type = 'button';
		endBtn.id = 'faz-scan-session-end';
		endBtn.className = 'faz-btn faz-btn-sm faz-btn-danger';
		endBtn.textContent = __('cookies.endActiveScan', 'End this scan and discard its capture');
		actions.appendChild(endBtn);
		box.appendChild(notice);
		box.appendChild(detail);
		box.appendChild(actions);
		wrap.appendChild(box);

		endBtn.addEventListener('click', function () {
			endBtn.disabled = true;
			// The scan id shown by scans/session names this administrator's own
			// session; the abort route only ever releases the caller's own.
			FAZ.post('scans/abort', { scan_id: activeSessionPanel.scanId }).then(function () {
				// aborted:false means the session lapsed on its own between the
				// last poll and the click — either way it no longer exists.
				removeActiveSessionPanel();
				FAZ.notify(__('cookies.activeScanEnded', 'Scan session ended — its capture was discarded. You can start a new scan.'), 'success');
			}, function (err) {
				endBtn.disabled = false;
				FAZ.notify((err && err.message) || __('cookies.scanFailed', 'Scan failed.'), 'error');
			});
		});

		// Same mount point as a run's own progress UI, so the session appears
		// exactly where a scan this tab started would.
		var card = dropdown.closest ? dropdown.closest('.faz-card') : null;
		var cardHeader = card ? card.querySelector('.faz-card-header') : null;
		if (card && cardHeader && cardHeader.parentNode) {
			cardHeader.parentNode.insertBefore(wrap, cardHeader.nextSibling);
		} else if (dropdown.parentNode) {
			dropdown.parentNode.insertBefore(wrap, dropdown.nextSibling);
		} else {
			return null;
		}

		activeSessionPanel.wrap = wrap;
		activeSessionPanel.els = { bar: bar, statusEl: statusEl, notice: notice, detail: detail, endBtn: endBtn };
		return wrap;
	}

	function renderActiveScanSession(info) {
		// Held sessions do not block a new scan (starting one reclaims them),
		// and this tab's own run already has richer live UI mounted — in both
		// cases showing this panel would only mislead.
		var running = document.querySelector('.faz-scan-progress-wrap:not(.faz-scan-session-wrap)');
		if (!info || info.active !== true || info.state !== 'live' || running) {
			removeActiveSessionPanel();
			return;
		}
		if (!activeSessionPanel.wrap && !buildActiveSessionPanel()) {
			return;
		}

		activeSessionPanel.scanId = info.scan_id || '';
		var els = activeSessionPanel.els;
		var idleFor = (info.server_time || 0) - (info.last_activity || 0);
		var isLive = idleFor >= 0 && idleFor <= SESSION_STALL_AFTER_S;

		activeSessionPanel.wrap.classList.toggle('faz-scan-progress-held', !isLive);
		els.statusEl.textContent = isLive ? __('cookies.scanStarted', 'Scanning...') : '';
		els.notice.textContent = __('cookies.activeScanNotice', 'A cookie scan session for your account is already open on the server (started at %s). A new scan cannot start until it ends.')
			.replace('%s', function () { return formatClockTime(info.started_at); });
		els.detail.textContent = isLive
			? __('cookies.activeScanLive', 'It is capturing right now — %1$d observation(s) so far, the last at %2$s. If one of your other tabs is running a scan, let it finish. Ending the session discards everything it has captured.')
				.replace('%1$d', function () { return String(info.observations || 0); })
				.replace('%2$s', function () { return formatClockTime(info.last_activity); })
			: __('cookies.activeScanStalled', 'Nothing has reached the server since %s. If none of your tabs is running a scan, the tab that was driving this one is gone. The session will expire on its own in a few minutes, or you can end it now — ending it discards everything it captured.')
				.replace('%s', function () { return formatClockTime(info.last_activity); });

		if (!activeSessionPanel.timer) {
			// Keep the shown state honest: another tab's crawl advances the
			// observation count (real progress), an abandoned one visibly goes
			// quiet, and a session that lapses or finishes takes the panel away.
			activeSessionPanel.timer = window.setInterval(refreshActiveScanSession, 10000);
		}
	}

	function startScan(maxPages) {
		var btn = document.getElementById('faz-scan-btn');
		var dropdown = document.getElementById('faz-scan-dropdown');
		FAZ.btnLoading(btn, true);
		btn.textContent = __('cookies.scanStarted', 'Scanning...');

		// The session panel's poll timer must stop before its wrapper goes: the
		// sweep below removes the DOM but would leave the interval re-creating
		// the panel underneath this run's own progress UI.
		removeActiveSessionPanel();

		// A previous run may have left its wrapper mounted on purpose — that is
		// how a held import keeps its Retry offer reachable. Starting a new scan
		// makes that offer void twice over: the server reclaims the held evidence
		// for the new session, and a second wrapper would leave two progress bars
		// on screen. Clear it before building this run's UI.
		var lingering = document.querySelectorAll('.faz-scan-progress-wrap');
		for (var l = 0; l < lingering.length; l++) {
			if (lingering[l].parentNode) { lingering[l].parentNode.removeChild(lingering[l]); }
		}

		// Build progress UI.
		var progressWrap = document.createElement('div');
		progressWrap.className = 'faz-scan-progress-wrap';
		var progress = document.createElement('div');
		progress.className = 'faz-scan-progress';
		var bar = document.createElement('div');
		bar.className = 'faz-scan-bar';
		var statusEl = document.createElement('span');
		statusEl.className = 'faz-scan-status';
		statusEl.textContent = __('cookies.discoveringPages', 'Discovering pages...');
		var pagesEl = document.createElement('div');
		pagesEl.className = 'faz-scan-pages';
		pagesEl.textContent = '0/0 pages';
		// A full crawl is a long foreground operation in this tab, and until now
		// nothing could interrupt it: the only way out was closing the tab, which
		// stranded the administrator behind an "another scan is in progress" 409.
		// The button stops the dispatcher; pages already loading still settle and
		// the partial result is still imported, flagged as incomplete coverage.
		var stopBtn = document.createElement('button');
		stopBtn.type = 'button';
		stopBtn.className = 'faz-btn faz-btn-sm faz-scan-stop';
		stopBtn.textContent = __('cookies.stopScan', 'Stop scan');
		progress.appendChild(bar);
		progress.appendChild(statusEl);
		progressWrap.appendChild(progress);
		progressWrap.appendChild(pagesEl);
		progressWrap.appendChild(stopBtn);
		var card = dropdown.closest ? dropdown.closest('.faz-card') : null;
		var cardHeader = card ? card.querySelector('.faz-card-header') : null;
		if (card && cardHeader && cardHeader.parentNode) {
			cardHeader.parentNode.insertBefore(progressWrap, cardHeader.nextSibling);
		} else {
			dropdown.parentNode.insertBefore(progressWrap, dropdown.nextSibling);
		}

		var hooks = {
			status: function (text) { statusEl.textContent = text; },
			progress: function (pct) { bar.style.width = pct + '%'; },
			pages: function (text) { pagesEl.textContent = text; },
		};

		// Snapshot first: the stale-cookie diff is the difference between what
		// the catalogue knew before the run and what this run actually saw.
		snapshotDiscoveredCookies().then(function (previousDiscoveredSet) {
			// Reached from the first import AND from a manual retry of a held
			// one, so the stale-cookie bookkeeping is identical either way.
			function handleScanSuccess(res) {
				var currentDetectedSet = buildCookieNameSet(res.cookies, false);
				var inferred = res.importResult && res.importResult.cookie_names;
				if (Array.isArray(inferred) && inferred.length) {
					inferred.forEach(function (name) {
						var prefix = name ? String(name).trim().toLowerCase() + '|' : '';
						if (!prefix) { return; }
						// Server-inferred names carry no domain; match any
						// previously-known key by its name prefix.
						Object.keys(previousDiscoveredSet).forEach(function (key) {
							if (key.indexOf(prefix) === 0) { currentDetectedSet[key] = true; }
						});
					});
				}

				// Unconditional, including the empty case: the bar has to clear
				// itself when a later scan withholds nothing, or a stale list
				// would sit there describing a run that is over.
				updateJarOnlyBar(res);

				var coverageIsComplete = scanCoverageIsComplete(res, maxPages);
				if (!coverageIsComplete) {
					// A depth-capped, incremental, early-stopped, cancelled or
					// degraded scan cannot prove a cookie is gone. Never
					// expose a destructive stale-cookie action from incomplete evidence.
					resetStaleCookies();
				} else {
					setStaleCookies(previousDiscoveredSet, currentDetectedSet, getEarnedDeletableSet(res));
				}

				// Every fragment of this summary goes through __(): a half-translated
				// sentence is worse than an untranslated one, and the two clauses
				// below were already localized. Replacements use callbacks so a
				// value containing "$&" cannot be read as a replacement pattern.
				//
				// `duplicate` means the server answered this submission with the
				// response an EARLIER one already produced — it did not save
				// anything a second time. Reporting it as a fresh import would
				// tell the administrator a scan ran that did not, so the counts
				// below are described as what is already on record.
				var duplicate = !!(res.importResult && res.importResult.duplicate);
				var msg = duplicate
					? __('cookies.scanAlreadySaved', 'Already saved — %1$d cookies on %2$d pages were imported by an earlier attempt. Nothing was saved twice.')
						.replace('%1$d', function () { return String(res.total); })
						.replace('%2$d', function () { return String(res.pagesScanned); })
					: __('cookies.scanComplete', 'Scan complete — %1$d cookies found on %2$d pages')
						.replace('%1$d', function () { return String(res.total); })
						.replace('%2$d', function () { return String(res.pagesScanned); });
				if (res.stoppedReason) {
					msg += ' ' + __('cookies.scanStopped', '(stopped by you before every page was visited)');
				} else if (res.earlyStopReason) {
					msg += ' ' + __('cookies.scanEarlyStop', '(early stop: %s)')
						.replace('%s', function () { return String(res.earlyStopReason); });
				}
				if (!coverageIsComplete) {
					msg += ' | ' + __('cookies.scanCoverageIncomplete', 'Scan coverage was incomplete, so no cookie was marked as stale.');
				} else if (staleCookieCount > 0) {
					msg += ' | ' + __('cookies.staleHighlighted', '%d stale cookie(s) highlighted')
						.replace('%d', function () { return String(staleCookieCount); });
				}
				if (res.importResult && res.importResult.enrichment_pending) {
					msg += ' | ' + __('cookies.enrichmentPending', 'The browser scan was saved. Server-header enrichment is still running in the background, so a few more cookies may appear shortly.');
				}
				msg += FAZ.scanEngine.diagnosticsHint(res.diagnostics, res.total);
				if (res.diagnostics && res.diagnostics.totalIssues > 0) {
					console.warn('[FAZ Scanner] Diagnostics:', res.diagnostics);
				}
				finishScan(btn, progressWrap, msg);
				loadCookies(function () { loadCategories(); });
			}

			function terminalFailure(err) {
				finishScan(btn, progressWrap, (err && err.message) || __('cookies.scanFailed', 'Scan failed.'), true);
			}

			// The import stage is the only failure the administrator can still
			// recover from without paying for the crawl again: the pages have
			// already been walked and the server kept the evidence, so all that
			// is left to redo is the save. `sessionHeld` is the server's own
			// word for that (data.faz_session_held) — never an assumption, so a
			// retry is never offered against evidence that is already gone.
			function handleScanFailure(err) {
				console.error('[FAZ Scanner] Scan failed:', err);
				if (err && err.sessionHeld === true) {
					offerImportRetry(err);
					return;
				}
				terminalFailure(err);
				// The refusal that used to be a dead end. The toast above names
				// it; this puts the session it collided with on screen, with
				// its explicit stop affordance, right where the collision
				// happened.
				if (err && err.code === 'faz_browser_scan_in_progress') {
					refreshActiveScanSession();
				}
			}

			/**
			 * Keep the failed run on screen with a way out of it.
			 *
			 * Deliberately not a toast: FAZ.notify() has neither an action nor a
			 * sticky mode — it fades after six seconds — so a recovery offered
			 * there would be unreachable by the time anyone read it. The progress
			 * wrapper this run already owns stays mounted and becomes the panel,
			 * which also keeps the affordance next to the Scan button that
			 * produced it.
			 */
			function offerImportRetry(err) {
				FAZ.btnLoading(btn, false);
				btn.textContent = __('cookies.scanSite', 'Scan Site') + ' ▾';
				if (stopBtn.parentNode) { stopBtn.parentNode.removeChild(stopBtn); }
				bar.style.width = '100%';
				progressWrap.classList.add('faz-scan-progress-held');
				statusEl.textContent = __('cookies.importNotSaved', 'Not saved');
				pagesEl.textContent = '';

				// The engine hands back a resubmit of the payload it already
				// built whenever the evidence is held, which imports without
				// touching the site again. Re-entering the held capture session
				// by its scan id is the fallback for the case where it did not:
				// it works, but the pages are walked a second time, and the two
				// are not described to the administrator as if they were alike.
				var savesOnly = !!(err && typeof err.retryImport === 'function');

				var panel = document.createElement('div');
				panel.className = 'faz-scan-held';
				panel.setAttribute('role', 'status');
				panel.setAttribute('aria-live', 'polite');

				var explain = document.createElement('p');
				explain.className = 'faz-scan-held-text';
				explain.textContent = savesOnly
					? __('cookies.importHeldRetrySave', 'The scan finished but the results could not be saved. Nothing is lost — they are held on the server for a few minutes. Retrying saves them; it does not scan the site again.')
					: __('cookies.importHeldRerun', 'The scan finished but the results could not be saved. The capture session is held on the server for a few minutes, so retrying reuses it instead of failing — but this browser has to walk the pages again.');
				panel.appendChild(explain);

				if (err && err.message) {
					var detail = document.createElement('p');
					detail.className = 'faz-scan-held-detail';
					detail.textContent = err.message;
					panel.appendChild(detail);
				}

				var actions = document.createElement('div');
				actions.className = 'faz-scan-held-actions';
				var retryBtn = document.createElement('button');
				retryBtn.type = 'button';
				retryBtn.className = 'faz-btn faz-btn-sm faz-btn-primary';
				retryBtn.textContent = savesOnly
					? __('cookies.retryImport', 'Retry import')
					: __('cookies.retryImportRescan', 'Retry import (re-scans the site)');
				var dismissBtn = document.createElement('button');
				dismissBtn.type = 'button';
				dismissBtn.className = 'faz-btn faz-btn-sm';
				dismissBtn.textContent = __('cookies.discardHeldScan', 'Discard');
				actions.appendChild(retryBtn);
				actions.appendChild(dismissBtn);
				panel.appendChild(actions);
				progressWrap.appendChild(panel);

				function closePanel() {
					if (panel.parentNode) { panel.parentNode.removeChild(panel); }
					progressWrap.classList.remove('faz-scan-progress-held');
				}

				retryBtn.addEventListener('click', function () {
					retryBtn.disabled = true;
					dismissBtn.disabled = true;
					FAZ.btnLoading(btn, true);
					btn.textContent = __('cookies.scanStarted', 'Scanning...');
					statusEl.textContent = __('cookies.savingResults', 'Saving results...');
					var attempt = savesOnly
						? err.retryImport()
						// Same scan id, so the held session is re-entered rather
						// than 409'd against.
						: FAZ.scanEngine.run({ maxPages: maxPages, scanId: err.scanId }, hooks);
					Promise.resolve(attempt).then(function (res) {
						closePanel();
						handleScanSuccess(res);
					}, function (retryErr) {
						closePanel();
						// Still held means still retryable. Once it is not, the
						// evidence really is gone and this becomes terminal.
						handleScanFailure(retryErr);
					}).catch(function (fatal) {
						console.error('[FAZ Scanner] Import retry handling failed:', fatal);
						if (progressWrap.parentNode) { terminalFailure(fatal); }
					});
				});

				dismissBtn.addEventListener('click', function () {
					closePanel();
					terminalFailure(err);
				});
			}

			var run = FAZ.scanEngine.run({ maxPages: maxPages }, hooks);
			stopBtn.addEventListener('click', function () {
				if (typeof run.cancel !== 'function') { return; }
				stopBtn.disabled = true;
				stopBtn.textContent = __('cookies.stoppingScan', 'Stopping…');
				run.cancel();
			});
			return run.then(handleScanSuccess, handleScanFailure);
		}).catch(function (err) {
			console.error('[FAZ Scanner] Scan failed:', err);
			finishScan(btn, progressWrap, (err && err.message) || __('cookies.scanFailed', 'Scan failed.'), true);
		});
	}

	function finishScan(btn, progress, message, isError) {
		FAZ.btnLoading(btn, false);
		btn.textContent = __('cookies.scanSite', 'Scan Site') + ' ▾';
		if (progress.parentNode) { progress.parentNode.removeChild(progress); }
		FAZ.notify(message, isError ? 'error' : 'success');
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
				FAZ.notify(scopeAll ? __('cookies.noCookiesToProcess', 'No cookies to process.') : __('cookies.noUncategorized', 'No uncategorized cookies to process.'));
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
					FAZ.notify(__('cookies.noneAutoCategorized', 'No cookies could be auto-categorized.'));
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
			FAZ.notify(__('cookies.autoCatFailed', 'Auto-categorize failed.'), 'error');
		});
	}

	// ── Cookie Definitions ──────────────────────────────────
	function loadDefinitionsStatus() {
		var el = document.getElementById('faz-defs-status');
		if (!el) return;
		FAZ.get('cookies/definitions').then(function (meta) {
			if (!meta || !meta.has_definitions) {
				el.textContent = __('cookies.noDefinitions', 'No definitions downloaded yet. Click "Update Definitions" to download.');
				return;
			}
			var count = meta.count || 0;
			var updated = meta.updated_at || '';
			if (meta.source === 'bundled') {
				el.textContent = count + ' built-in cookie definitions loaded' + (updated ? ' - bundled snapshot date: ' + updated : '') + '. Click "Update Definitions" to refresh from GitHub.';
				return;
			}
			el.textContent = count + ' cookie definitions loaded' + (updated ? ' - last updated: ' + updated : '');
		}).catch(function () {
			el.textContent = __('cookies.definitionsLoadFailed', 'Could not load definitions status.');
		});
	}

	function updateDefinitions() {
		var btn = document.getElementById('faz-update-defs-btn');
		var el = document.getElementById('faz-defs-status');
		FAZ.btnLoading(btn, true);
		if (el) el.textContent = __('cookies.downloadingDefinitions', 'Downloading definitions from GitHub...');

		FAZ.post('cookies/definitions/update').then(function (result) {
			FAZ.btnLoading(btn, false);
			if (result && result.success) {
				FAZ.notify(result.message || __('cookies.definitionsUpdated', 'Definitions updated.'));
				loadDefinitionsStatus();
			} else {
				FAZ.notify(result.message || __('cookies.definitionsFailed', 'Update failed.'), 'error');
				if (el) el.textContent = __('cookies.definitionsFailed', 'Update failed.') + ': ' + (result.message || 'unknown error');
			}
		}).catch(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify(__('cookies.definitionsFailed', 'Update failed.'), 'error');
			if (el) el.textContent = __('cookies.definitionsNetworkFailed', 'Update failed. Check your network connection.');
		});
	}

	/* ── Custom Blocking Rules ────────────────────────── */

	// Must match the allowlist in class-settings.php::sanitize_settings()
	// case 'custom_rules' (admin/modules/settings/includes/class-settings.php:386).
	// `necessary` is required by 8 built-in blocker templates (Cloudflare
	// Turnstile, Gravatar, reCAPTCHA, hCaptcha, Wordfence, WPForms, Ninja
	// Forms reCAPTCHA, WooCommerce Attribution) — these scripts must load
	// unconditionally regardless of consent state and the auto-scanner must
	// leave them alone. Without `necessary` here the dropdown silently
	// refused to expose the option even though the backend accepted it,
	// forcing admins into the lossy workaround of re-deleting GTM/Turnstile
	// rows after every re-scan. `uncategorized` is the fallback bucket; it
	// is accepted by the backend but rarely a useful choice for a rule.
	var ruleCategories = ['necessary', 'analytics', 'marketing', 'functional', 'performance'];

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
		input.placeholder = __('cookies.rulePlaceholder', 'e.g. custom-tracker.com/script.js');
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
		emptyOpt.textContent = __('cookies.select', '— Select —');
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
		removeBtn.textContent = __('cookies.remove', 'Remove');
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
			FAZ.notify(collected.invalid + ' ' + __('cookies.rulesIncomplete', 'rule(s) incomplete — fill in both pattern and category.'), 'error');
			return;
		}
		FAZ.btnLoading(btn, true);
		FAZ.get('settings').then(function (current) {
			current.script_blocking = current.script_blocking || {};
			current.script_blocking.custom_rules = collected.rules;
			return FAZ.post('settings', current);
		}).then(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify(__('cookies.rulesSaved', 'Custom rules saved.'));
		}).catch(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify(__('cookies.rulesSaveFailed', 'Failed to save custom rules.'), 'error');
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
				emptyMsg.textContent = __('cookies.noTemplates', 'No templates available.');
				container.appendChild(emptyMsg);
				return;
			}

			templates.forEach(function (tpl) {
				var card = document.createElement('div');
				card.className = 'faz-template-card';
				var cardAction = document.createElement('button');
				cardAction.type = 'button';
				cardAction.className = 'faz-template-card-action';
				card.appendChild(cardAction);

				var name = document.createElement('div');
				name.className = 'faz-template-card-name';
				name.textContent = tpl.name;
				cardAction.appendChild(name);

				var desc = document.createElement('div');
				desc.className = 'faz-template-card-desc';
				desc.textContent = tpl.description;
				cardAction.appendChild(desc);

				var badge = document.createElement('span');
				badge.className = 'faz-template-card-badge';
				badge.textContent = tpl.category;
				cardAction.appendChild(badge);

				// A rule that cannot take effect must say so where it lives.
				// Without this the admin adds it, sees the feed load anyway, and
				// has no way to learn that the third-party surface it gates no
				// longer exists — the plugin looks broken while behaving
				// correctly. The card stays clickable: the condition belongs to
				// the other plugin's setting and can change at any moment.
				if (tpl.not_applicable && tpl.not_applicable.label) {
					card.classList.add('faz-template-card-inert');
					card.title = tpl.not_applicable.note || '';

					var inert = document.createElement('span');
					inert.className = 'faz-template-card-inert-badge';
					inert.textContent = tpl.not_applicable.label;
					cardAction.appendChild(inert);

					var note = document.createElement('div');
					note.className = 'faz-template-card-inert-note';
					note.textContent = tpl.not_applicable.note || '';
					cardAction.appendChild(note);

					if (tpl.not_applicable.url) {
						var inertUrl = '';
						try {
							var parsed = new URL(String(tpl.not_applicable.url), window.location.href);
							if ((parsed.protocol === 'http:' || parsed.protocol === 'https:')
								&& parsed.origin === window.location.origin) {
								inertUrl = parsed.href;
							}
						} catch (e) {
							inertUrl = '';
						}
						if (inertUrl) {
							// A real link is a sibling of the card button: valid HTML,
							// same-origin only, with native new-tab/copy-link behaviour.
							var link = document.createElement('a');
							link.className = 'faz-template-card-inert-link';
							link.href = inertUrl;
							link.textContent = tpl.not_applicable.link_label
								|| __('cookies.sbOpenSettings', 'Open Instagram Feed settings');
							card.appendChild(link);
						}
					}
				}

				cardAction.addEventListener('click', function () {
					var patterns = Array.isArray(tpl.patterns) ? tpl.patterns : [];
					if (!patterns.length && !(Array.isArray(tpl.cookies) && tpl.cookies.length)) {
						FAZ.notify(__('cookies.templateEmpty', 'No patterns or cookies in template.'), 'error');
						return;
					}
					var added = 0;
					patterns.forEach(function (pattern) {
						addRuleRow(pattern, tpl.category);
						added++;
					});
					if (added) {
						saveCustomRules();
						FAZ.notify(__('cookies.rulesAdded', 'Added %1$d rules from %2$s (saved)').replace('%1$d', added).replace('%2$s', tpl.name), 'success');
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
						FAZ.notify('Category "' + tpl.category + '" ' + __('cookies.templateCatNotFound', 'not found — cookies not added.'), 'error');
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
							FAZ.notify(__('cookies.allCookiesExist', 'All cookies from %s already exist').replace('%s', tpl.name), 'info');
							return;
						}

						return Promise.all(creates).then(function () {
							FAZ.notify(creates.length + ' cookie(s) added from ' + tpl.name, 'success');
							loadCookies();
							loadCategories();
						});
					}).catch(function () {
						FAZ.notify(__('cookies.templateCookiesFailed', 'Failed to create cookies from template.'), 'error');
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
				errMsg.textContent = __('cookies.templateLoadFailed', 'Failed to load templates.');
				container.appendChild(errMsg);
			}
		});
	}

})();

/* ─────────────────────────────────────────────────────────────────
 * Shortcode copy buttons + scanner debug-log actions.
 *
 * Migrated out of admin/views/cookies.php (where it lived as an
 * inline <script>) so the file complies with the WordPress.org
 * "use wp_enqueue commands" guideline.
 *
 * Localized strings come from `fazConfig.i18n.cookies.*` — see the
 * `wp_localize_script()` registration in admin/class-admin.php.
 * ───────────────────────────────────────────────────────────────── */
(function () {
	'use strict';

	function _t( key, fallback ) {
		var i18n = ( window.fazConfig && window.fazConfig.i18n && window.fazConfig.i18n.cookies ) || {};
		return i18n[ key ] || fallback;
	}

	function copyToClipboard( sourceId, successMsg ) {
		var src = document.getElementById( sourceId );
		if ( ! src ) {
			return;
		}
		var text = src.textContent;
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( function () {
				if ( window.FAZ && window.FAZ.notify ) { window.FAZ.notify( successMsg ); }
			} );
			return;
		}
		// Fallback for older browsers or insecure contexts.
		var range = document.createRange();
		range.selectNodeContents( src );
		var sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange( range );
		try {
			document.execCommand( 'copy' );
			if ( window.FAZ && window.FAZ.notify ) { window.FAZ.notify( successMsg ); }
		} catch ( e ) {}
	}

	var copyShortcodeBtn = document.getElementById( 'faz-copy-shortcode' );
	if ( copyShortcodeBtn ) {
		copyShortcodeBtn.addEventListener( 'click', function () {
			copyToClipboard( 'faz-shortcode-text', _t( 'shortcodeCopied', 'Shortcode copied!' ) );
		} );
	}

	var copyPolicyBtn = document.getElementById( 'faz-copy-policy-shortcode' );
	if ( copyPolicyBtn ) {
		copyPolicyBtn.addEventListener( 'click', function () {
			copyToClipboard( 'faz-policy-shortcode', _t( 'shortcodeCopied', 'Shortcode copied!' ) );
		} );
	}

	// Scanner Debug Log — show buttons + attach listeners only if debug mode is enabled.
	if ( window.FAZ && typeof window.FAZ.get === 'function' ) {
		window.FAZ.get( 'settings' ).then( function ( settings ) {
			if ( ! ( settings && settings.scanner && settings.scanner.debug_mode ) ) {
				return;
			}

			var actionsEl = document.getElementById( 'faz-debug-log-actions' );
			if ( actionsEl ) {
				actionsEl.style.display = '';
			}

			var dlBtn = document.getElementById( 'faz-download-debug-log' );
			if ( dlBtn ) {
				dlBtn.addEventListener( 'click', function () {
					window.FAZ.get( 'scans/debug-log' ).then( function ( res ) {
						if ( ! res || ! res.log ) {
							if ( window.FAZ.notify ) {
								window.FAZ.notify( _t( 'noScanLogs', 'No scan logs available.' ), 'warning' );
							}
							return;
						}
						var blob = new Blob( [ res.log ], { type: 'text/plain' } );
						var url  = URL.createObjectURL( blob );
						var a    = document.createElement( 'a' );
						a.href     = url;
						a.download = 'faz-scanner-debug-' + new Date().toISOString().slice( 0, 10 ) + '.log';
						document.body.appendChild( a );
						a.click();
						document.body.removeChild( a );
						URL.revokeObjectURL( url );
					} ).catch( function () {
						if ( window.FAZ.notify ) {
							window.FAZ.notify( _t( 'debugLogDownloadFailed', 'Failed to download debug log.' ), 'error' );
						}
					} );
				} );
			}

			var clearBtn = document.getElementById( 'faz-clear-debug-log' );
			if ( clearBtn ) {
				clearBtn.addEventListener( 'click', function () {
					var confirmMsg = _t( 'clearDebugLogsConfirm', 'Clear all scanner debug logs?' );
					if ( ! window.confirm( confirmMsg ) ) {
						return;
					}
					window.FAZ.del( 'scans/debug-log' ).then( function () {
						if ( window.FAZ.notify ) {
							window.FAZ.notify( _t( 'debugLogsCleared', 'Debug logs cleared.' ) );
						}
					} ).catch( function () {
						if ( window.FAZ.notify ) {
							window.FAZ.notify( _t( 'debugLogsClearFailed', 'Failed to clear debug logs.' ), 'error' );
						}
					} );
				} );
			}
		} ).catch( function () {} );
	}
}() );
