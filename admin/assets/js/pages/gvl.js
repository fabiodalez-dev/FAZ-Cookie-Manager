/**
 * FAZ Cookie Manager - GVL (Vendor List) Page JS
 */
(function () {
	'use strict';

	// i18n helper — looks up fazConfig.i18n.<key> with dot-notation, falls back to provided string.
	// `key` is a hard-coded string from this file (e.g. 'gvl.savedCount'),
	// never reachable from user input — the computed-key walk is safe.
	function __(key, fallback) {
		var parts = key.split('.');
		var obj = (window.fazConfig && window.fazConfig.i18n) || {};
		for (var i = 0; i < parts.length; i++) {
			if (!obj || typeof obj !== 'object') { return fallback; }
			obj = obj[parts[i]]; // nosemgrep
		}
		return typeof obj === 'string' ? obj : fallback;
	}

	var currentPage = 1;
	var perPage = 50;
	var searchTerm = '';
	var purposeFilter = 0;
	var selectedVendors = {};  // { vendorId: true }
	// Whether the saved selection has loaded. Until it has, selectedVendors is
	// empty and a Save would wipe the server-side selection — so Auto-detect
	// stays disabled and saveSelection() refuses to run.
	var hydrated = false;
	var searchTimer = null;
	// Race guard for autoDetectFromCookies — incremented on every
	// invocation, captured locally inside the function so a slow
	// previous request that resolves AFTER a newer click cannot push
	// stale data into selectedVendors / status / notifications.
	var autoDetectRequestId = 0;
	// Closure-level timer handle for the auto-detect status auto-clear.
	// Cleared at the start of every setAutoDetectStatus() call so a stale
	// timer scheduled by a previous 'ok'/'success' message can never blank a
	// newer scanning/error message painted afterwards. Mirrors
	// cookie-policy.js setAutoDetectStatus().
	var autoDetectStatusTimer = null;
	function setAutoDetectStatus(msg, kind) {
		var el = document.getElementById('faz-gvl-auto-detect-status');
		if (!el) { return; }
		if (autoDetectStatusTimer) { clearTimeout(autoDetectStatusTimer); autoDetectStatusTimer = null; }
		el.textContent = msg || '';
		el.style.color = kind === 'error' ? 'var(--faz-danger, #c03658)' : (kind === 'warning' ? 'var(--faz-warning, #b86900)' : (kind === 'ok' || kind === 'success' ? 'var(--faz-success, #17785b)' : 'var(--faz-text-secondary, #555)'));
		// Auto-clear ONLY the 'ok'/'success' message after 3s. 'error' (F007),
		// 'warning' (noGvl), 'info' (noMatch — neutral grey, no --faz-info token)
		// and scanning ('') states stay persistent — no timer.
		if (msg && (kind === 'ok' || kind === 'success')) {
			autoDetectStatusTimer = setTimeout(function () { el.textContent = ''; autoDetectStatusTimer = null; }, 3000);
		}
	}

	FAZ.ready(function () {
		if (!document.getElementById('faz-gvl')) return;

		loadMeta();
		loadSelectedVendors();

		document.getElementById('faz-gvl-download').addEventListener('click', downloadGvl);
		document.getElementById('faz-gvl-save').addEventListener('click', saveSelection);
		document.getElementById('faz-gvl-select-all').addEventListener('change', toggleSelectAll);
		var autoBtn = document.getElementById('faz-gvl-auto-detect');
		if (autoBtn) {
			// Disable Auto-detect until the gvl/selected hydration completes.
			// Otherwise a click during loadSelectedVendors() lets
			// autoDetectFromCookies() tick selectedVendors, then the in-flight
			// .then() resets selectedVendors={} and silently wipes the
			// auto-detected selection after the admin already saw a success
			// toast. loadSelectedVendors() re-enables it in BOTH .then()/.catch().
			// Mirrors the cookie-policy.js fix (PR #127 CodeRabbit review).
			autoBtn.disabled = true;
			// Give AT users context for the disabled button while the saved
			// selection hydrates. Cleared once hydration completes / the button
			// is re-enabled in loadSelectedVendors().
			setAutoDetectStatus(__('gvl.autoDetectHydrating', 'Loading saved selection…'), 'info');
			autoBtn.addEventListener('click', autoDetectFromCookies);
		}

		var searchInput = document.getElementById('faz-gvl-search');
		searchInput.addEventListener('input', function () {
			clearTimeout(searchTimer);
			searchTimer = setTimeout(function () {
				searchTerm = searchInput.value.trim();
				currentPage = 1;
				loadVendors();
			}, 300);
		});

		var purposeSelect = document.getElementById('faz-gvl-purpose-filter');
		purposeSelect.addEventListener('change', function () {
			purposeFilter = parseInt(purposeSelect.value, 10) || 0;
			currentPage = 1;
			loadVendors();
		});
	});

	function loadMeta() {
		FAZ.get('gvl').then(function (data) {
			var el = document.getElementById('faz-gvl-meta');
			if (!el) return;
			el.textContent = '';

			if (data.version && data.version > 0) {
				var b1 = document.createElement('strong');
				b1.textContent = __('gvl.version', 'GVL Version: ');
				el.appendChild(b1);
				el.appendChild(document.createTextNode(data.version + '  |  '));
				var b2 = document.createElement('strong');
				b2.textContent = __('gvl.vendors', 'Vendors: ');
				el.appendChild(b2);
				el.appendChild(document.createTextNode(data.vendor_count + '  |  '));
				var b3 = document.createElement('strong');
				b3.textContent = __('gvl.lastUpdated', 'Last Updated: ');
				el.appendChild(b3);
				el.appendChild(document.createTextNode(data.last_updated || 'N/A'));

				// Populate purpose filter.
				if (data.purposes && data.purposes.length) {
					var select = document.getElementById('faz-gvl-purpose-filter');
					if (!select) return;
					while (select.options.length > 1) { select.remove(1); }
					data.purposes.forEach(function (p) {
						var opt = document.createElement('option');
						opt.value = p.id;
						opt.textContent = p.id + '. ' + p.name;
						select.appendChild(opt);
					});
				}
			} else {
				el.textContent = __('gvl.noData', 'No GVL data downloaded yet. Click "Update GVL Now" to download.');
			}
		}).catch(function () {
			var el = document.getElementById('faz-gvl-meta');
			if (el) el.textContent = __('gvl.loadFailed', 'Failed to load GVL status.');
		});
	}

	function loadSelectedVendors() {
		FAZ.get('gvl/selected').then(function (data) {
			selectedVendors = {};
			if (data.vendor_ids && Array.isArray(data.vendor_ids)) {
				data.vendor_ids.forEach(function (id) {
					selectedVendors[id] = true;
				});
			}
			updateSelectedCount();
			hydrated = true;
			// Hydration done — Auto-detect is safe now (selectedVendors holds
			// the saved selection, so a subsequent auto-detect tick can't be
			// overwritten by a late hydration). Re-enable before loadVendors().
			var ab = document.getElementById('faz-gvl-auto-detect');
			if (ab) { ab.disabled = false; }
			// Hydration done — clear the "Loading saved selection…" status.
			setAutoDetectStatus('', '');
			loadVendors();
		}).catch(function () {
			// Fetch error: the saved selection never loaded. Do NOT re-enable
			// Auto-detect and leave `hydrated` false — saveSelection() now holds
			// only an empty set, and committing it would wipe the server-side
			// selection. Surface the failure and still show the vendor list.
			setAutoDetectStatus(__('gvl.selectedLoadFailed', 'Could not load your saved selection — reload before changing it.'), 'error');
			loadVendors();
		});
	}

	function loadVendors() {
		var params = 'page=' + currentPage + '&per_page=' + perPage;
		if (searchTerm) params += '&search=' + encodeURIComponent(searchTerm);
		if (purposeFilter > 0) params += '&purpose=' + purposeFilter;

		FAZ.get('gvl/vendors?' + params).then(function (data) {
			renderVendors(data.vendors || []);
			renderPagination(data.total || 0, data.pages || 0, data.page || 1);
		}).catch(function () {
			var el = document.getElementById('faz-gvl-vendor-list');
			if (el) el.textContent = __('gvl.vendorsLoadFailed', 'Failed to load vendors. Make sure GVL is downloaded.');
		});
	}

	function renderVendors(vendors) {
		var container = document.getElementById('faz-gvl-vendor-list');
		container.textContent = '';

		if (!vendors.length) {
			container.textContent = __('gvl.noVendors', 'No vendors found.');
			return;
		}

		var table = document.createElement('table');
		table.className = 'faz-table';
		table.style.width = '100%';

		var thead = document.createElement('thead');
		var headerRow = document.createElement('tr');
		['', 'ID', 'Vendor Name', 'Purposes', 'LI Purposes', 'Features'].forEach(function (h) {
			var th = document.createElement('th');
			th.textContent = h;
			th.style.textAlign = h === '' ? 'center' : 'left';
			if (h === '') th.style.width = '40px';
			if (h === 'ID') th.style.width = '60px';
			headerRow.appendChild(th);
		});
		thead.appendChild(headerRow);
		table.appendChild(thead);

		var tbody = document.createElement('tbody');
		vendors.forEach(function (v) {
			var tr = document.createElement('tr');
			tr.style.cursor = 'pointer';

			// Checkbox.
			var tdCheck = document.createElement('td');
			tdCheck.style.textAlign = 'center';
			var cb = document.createElement('input');
			cb.type = 'checkbox';
			cb.checked = !!selectedVendors[v.id];
			cb.dataset.vendorId = v.id;
			cb.addEventListener('change', function (e) {
				e.stopPropagation();
				if (cb.checked) {
					selectedVendors[v.id] = true;
				} else {
					delete selectedVendors[v.id];
				}
				updateSelectedCount();
			});
			tdCheck.appendChild(cb);
			tr.appendChild(tdCheck);

			// ID.
			var tdId = document.createElement('td');
			tdId.textContent = v.id;
			tr.appendChild(tdId);

			// Name.
			var tdName = document.createElement('td');
			tdName.textContent = v.name;
			tdName.style.fontWeight = '500';
			tr.appendChild(tdName);

			// Purposes.
			var tdPurp = document.createElement('td');
			tdPurp.textContent = (v.purposes || []).join(', ') || '-';
			tr.appendChild(tdPurp);

			// LI Purposes.
			var tdLI = document.createElement('td');
			tdLI.textContent = (v.legIntPurposes || []).join(', ') || '-';
			tr.appendChild(tdLI);

			// Features.
			var tdFeat = document.createElement('td');
			tdFeat.textContent = (v.features || []).join(', ') || '-';
			tr.appendChild(tdFeat);

			// Click row to toggle details (except checkbox click).
			tr.addEventListener('click', function (e) {
				if (e.target.tagName === 'INPUT') return;
				showVendorDetails(v.id);
			});

			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		container.appendChild(table);

		// Sync select-all checkbox state for current page.
		var selectAll = document.getElementById('faz-gvl-select-all');
		if (selectAll && vendors.length) {
			var allSelected = vendors.every(function(v) { return !!selectedVendors[v.id]; });
			selectAll.checked = allSelected;
		}
	}

	function renderPagination(total, pages, page) {
		var container = document.getElementById('faz-gvl-pagination');
		container.textContent = '';

		if (pages <= 1) return;

		function addBtn(label, targetPage, disabled) {
			var btn = document.createElement('button');
			btn.className = 'faz-btn faz-btn-sm ' + (targetPage === page ? 'faz-btn-primary' : 'faz-btn-secondary');
			btn.textContent = label;
			btn.disabled = disabled;
			btn.addEventListener('click', function () {
				currentPage = targetPage;
				loadVendors();
			});
			container.appendChild(btn);
		}

		addBtn('Prev', page - 1, page <= 1);

		var start = Math.max(1, page - 2);
		var end = Math.min(pages, page + 2);
		for (var i = start; i <= end; i++) {
			addBtn(String(i), i, false);
		}

		addBtn('Next', page + 1, page >= pages);

		var info = document.createElement('span');
		info.style.color = 'var(--faz-text-secondary)';
		info.textContent = __('gvl.pagination', 'Page %1$d of %2$d (%3$d vendors)').replace('%1$d', page).replace('%2$d', pages).replace('%3$d', total);
		container.appendChild(info);
	}

	function showVendorDetails(vendorId) {
		FAZ.get('gvl/vendors/' + vendorId).then(function (v) {
			var lines = [];
			lines.push('Vendor: ' + v.name + ' (ID: ' + v.id + ')');
			if (v.policyUrl) lines.push('Privacy Policy: ' + v.policyUrl);
			lines.push('Purposes: ' + (v.purposes || []).join(', '));
			lines.push('LI Purposes: ' + (v.legIntPurposes || []).join(', '));
			lines.push('Features: ' + (v.features || []).join(', '));
			lines.push('Special Features: ' + (v.specialFeatures || []).join(', '));
			lines.push('Special Purposes: ' + (v.specialPurposes || []).join(', '));
			if (v.cookieMaxAgeSeconds != null) {
				var days = Math.round(v.cookieMaxAgeSeconds / 86400);
				lines.push('Cookie Retention: ' + days + ' days');
			}
			if (v.usesCookies != null) lines.push('Uses Cookies: ' + (v.usesCookies ? 'Yes' : 'No'));
			if (v.usesNonCookieAccess != null) lines.push('Non-Cookie Access: ' + (v.usesNonCookieAccess ? 'Yes' : 'No'));

			alert(lines.join('\n'));
		}).catch(function () {
			FAZ.notify(__('gvl.vendorDetailFailed', 'Failed to load vendor details.'), 'error');
		});
	}

	function toggleSelectAll() {
		var checked = document.getElementById('faz-gvl-select-all').checked;
		var checkboxes = document.querySelectorAll('#faz-gvl-vendor-list input[type="checkbox"]');
		checkboxes.forEach(function (cb) {
			cb.checked = checked;
			var id = parseInt(cb.dataset.vendorId, 10);
			if (checked) {
				selectedVendors[id] = true;
			} else {
				delete selectedVendors[id];
			}
		});
		updateSelectedCount();
	}

	function updateSelectedCount() {
		var count = Object.keys(selectedVendors).length;
		var el = document.getElementById('faz-gvl-selected-count');
		if (el) el.textContent = (count !== 1 ? __('gvl.selectedVendors', 'Selected: %d vendors') : __('gvl.selectedVendor', 'Selected: %d vendor')).replace('%d', count);
	}

	function saveSelection() {
		// Refuse to save before the saved selection has loaded — selectedVendors
		// would be empty and committing it would wipe the server-side selection.
		if (!hydrated) {
			FAZ.notify(__('gvl.notHydrated', 'Your saved selection has not loaded yet — reload the page before saving.'), 'error');
			return;
		}
		var btn = document.getElementById('faz-gvl-save');
		var ids = Object.keys(selectedVendors).map(Number).sort(function (a, b) { return a - b; });

		FAZ.btnLoading(btn, true);
		FAZ.post('gvl/selected', { vendor_ids: ids }).then(function (data) {
			FAZ.btnLoading(btn, false);
			if (data.success) {
				FAZ.notify(__('gvl.savedCount', 'Saved %d vendor(s).').replace('%d', data.count), 'success');
			} else {
				FAZ.notify(__('gvl.selectionFailed', 'Failed to save selection.'), 'error');
			}
		}).catch(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify(__('gvl.selectionFailed', 'Failed to save selection.'), 'error');
		});
	}

	function autoDetectFromCookies() {
		var btn    = document.getElementById('faz-gvl-auto-detect');
		// Hydration guard: while loadSelectedVendors() is still in flight the
		// button is disabled. Bail out early so a click can't tick
		// selectedVendors before the gvl/selected .then() resets it (which
		// would silently wipe the auto-detected selection). Mirrors
		// cookie-policy.js autoDetectServices().
		if (!btn || btn.disabled) { return; }
		// Race guard: capture the current request id BEFORE awaiting
		// the network call. If a newer click bumps autoDetectRequestId
		// while this promise is in flight, the stale .then / .catch
		// handlers bail out early and leave the UI state to the newer
		// invocation. Matches the previewRequestId pattern used in
		// other admin pages.
		var requestId = ++autoDetectRequestId;
		// Read-only scan, not a save — pass a scan-specific spinner label so
		// the button doesn't misleadingly read "Saving..." during detection.
		FAZ.btnLoading(btn, true, __('gvl.autoDetectScanning', 'Scanning cookie inventory…'));
		// Scanning state: announce it in the aria-live status region (no
		// timer — stays until the next status write replaces it) so screen
		// readers hear the scan start, matching the cookie-policy page.
		setAutoDetectStatus(__('gvl.autoDetectScanning', 'Scanning cookie inventory…'), 'info');

		FAZ.get('gvl/suggest').then(function (data) {
			if (requestId !== autoDetectRequestId) { return; }
			FAZ.btnLoading(btn, false);
			if (!data || data.gvl_available !== true) {
				// GVL has never been downloaded — nudge the admin towards
				// the Update button at the top of the page rather than
				// silently doing nothing. Write the SAME message to the
				// persistent status span so a faded toast still leaves a
				// trace — parity with cookie-policy.js (F007).
				var noGvlMsg = __('gvl.autoDetectNoGvl', 'Update the Global Vendor List first, then try Auto-detect again.');
				setAutoDetectStatus(noGvlMsg, 'warning');
				FAZ.notify(noGvlMsg, 'warning');
				return;
			}
			// Three-state distinction (parity with cookie-policy.js): the
			// scanner never ran (no discovered rows) is actionably different
			// from "scanner ran but nothing matched". Without scan_available
			// both collapse to the no-match hint, so the admin who simply
			// forgot to run the scanner gets the wrong nudge.
			if (data.scan_available !== true) {
				var noScanMsg = __('gvl.autoDetectNoScan', 'No scanner data yet. Run the cookie scanner first.');
				setAutoDetectStatus(noScanMsg, 'warning');
				FAZ.notify(noScanMsg, 'warning');
				return;
			}

			var suggested = (data.vendor_ids || []).map(Number).filter(function (n) { return n > 0; });
			var added     = (data.newly_suggested || []).map(Number);
			var already   = (data.already_selected || []).map(Number);

			if (suggested.length === 0) {
				// Scanner ran (scan_available === true) and the GVL is present
				// but no scanned domain matched the curated map. Soft info
				// string in the persistent span instead of going blank — keeps
				// a trace after the toast fades (F007).
				var noMatchMsg = __('gvl.autoDetectNoMatch', 'No matching ad-tech vendors were found in the scanned cookies.');
				setAutoDetectStatus(noMatchMsg, 'info');
				FAZ.notify(noMatchMsg, 'info');
				return;
			}

			// Merge ONLY the newly-suggested vendors into the in-memory
			// selection — never the full matched set. `already_selected` is
			// computed server-side against the SAVED option, so re-ticking it
			// would silently resurrect a vendor the admin unticked in-session
			// (before saving), reversing their unsaved change. Ticking only
			// `added` leaves in-session unticks intact. Mirrors the safe
			// cookie-policy.js autoDetectServices() pattern (F007).
			// Save is deferred — the admin still clicks "Save Selection" to
			// persist, so the auto-detection stays auditable.
			added.forEach(function (id) { selectedVendors[id] = true; });

			// Refresh the visible "Selected: N vendors" counter immediately
			// after mutating selectedVendors. loadVendors()/renderVendors()
			// only redraw the table — they don't touch #faz-gvl-selected-count,
			// so without this call the counter stays stale until the admin
			// manually toggles a checkbox or clicks Select-All.
			updateSelectedCount();

			// Re-render the currently visible vendor table so the new
			// checkboxes appear ticked. The set we just merged into may
			// include vendors NOT on the current page (filtered by
			// search/purpose) — those still get persisted on Save.
			loadVendors();

			// Inline feedback string. Tone tries to make the deferred-save
			// nature obvious so the admin doesn't think the change is
			// already live.
			// The "already selected" count must reflect the CURRENT in-session
			// selection, not the server-computed `already_selected` (which is
			// measured against the SAVED option). An admin who unticked a saved
			// vendor in-session and then re-runs auto-detect should NOT see that
			// vendor counted as "already selected": we deliberately do not
			// re-tick it (see the `added`-only merge above), so reporting it as
			// already-selected would contradict what the admin sees on screen.
			// Keep only the matched-and-still-ticked vendors for the message.
			var alreadyInSession = already.filter(function (id) { return selectedVendors[id] === true; });
			var msg;
			if (added.length === 0) {
				// Nothing new to pre-tick. Report only the matched vendors STILL
				// selected in-session — an admin who unticked some this session must
				// not see them counted (same screen-vs-count contradiction the
				// sibling branches already avoid via alreadyInSession). Fall back to
				// a "left unticked" note when none remain.
				msg = (alreadyInSession.length > 0)
					? __('gvl.autoDetectAllAlready', 'All %d auto-detected vendor(s) were already in your selection.').replace('%d', alreadyInSession.length)
					: __('gvl.autoDetectNoneAdded', 'Auto-detected vendors left unticked, as you set them.');
			} else if (alreadyInSession.length === 0) {
				// Single placeholder — no reordering possible, plain %d is fine.
				msg = __('gvl.autoDetectAdded', 'Pre-ticked %d vendor(s) from cookie scan. Click Save Selection to apply.')
					.replace('%d', added.length);
			} else {
				// Dual-mode formatter. Both the registered string (class-admin.php)
				// and the English fallback below use positional %1$d/%2$d so
				// translators can reorder the two counts; the positional replaces
				// handle that case. The trailing plain-%d replaces are a defensive
				// no-op kept only so a translation that happens to use plain %d
				// still renders. Mirrors cookie-policy.js svcAutoDetectDone.
				var template = __('gvl.autoDetectMixed', 'Pre-ticked %1$d new vendor(s), %2$d were already selected. Click Save Selection to apply.');
				msg = template
					.replace(/%1\$d/g, String(added.length))
					.replace(/%2\$d/g, String(alreadyInSession.length))
					.replace('%d', String(added.length))
					.replace('%d', String(alreadyInSession.length));
			}
			// Success: route through the helper so the message auto-clears
			// after 3s (the only kind that gets a timer).
			setAutoDetectStatus(msg, 'ok');
			FAZ.notify(msg, 'success');
		}).catch(function () {
			if (requestId !== autoDetectRequestId) { return; }
			FAZ.btnLoading(btn, false);
			// Persist the failure in the status span too — the toast
			// auto-dismisses but the admin still needs a trace (F007).
			// 'error' kind => no auto-clear timer, stays visible.
			var failedMsg = __('gvl.autoDetectFailed', 'Auto-detect failed. Check the cookie scanner and try again.');
			setAutoDetectStatus(failedMsg, 'error');
			FAZ.notify(failedMsg, 'error');
		});
	}

	function downloadGvl() {
		var btn = document.getElementById('faz-gvl-download');
		FAZ.btnLoading(btn, true);
		FAZ.post('gvl/update').then(function (data) {
			FAZ.btnLoading(btn, false);
			if (data.success) {
				var updatedMsg = __('gvl.updatedWithMeta', 'GVL updated: v{version} ({count} vendors)')
					.replace('{version}', String(data.version))
					.replace('{count}', String(data.vendor_count));
				FAZ.notify(updatedMsg);
				loadMeta();
				loadVendors();
			} else {
				FAZ.notify(data.message || __('gvl.updateFailed', 'Failed to update GVL.'), 'error');
			}
		}).catch(function (err) {
			FAZ.btnLoading(btn, false);
			FAZ.notify((err && err.message) || __('gvl.updateFailed', 'Failed to update GVL.'), 'error');
		});
	}

})();
