/**
 * FAZ Cookie Manager - GVL (Vendor List) Page JS
 */
(function () {
	'use strict';

	var currentPage = 1;
	var perPage = 50;
	var searchTerm = '';
	var purposeFilter = 0;
	var selectedVendors = {};  // { vendorId: true }
	var searchTimer = null;

	FAZ.ready(function () {
		if (!document.getElementById('faz-gvl')) return;

		loadMeta();
		loadSelectedVendors();

		document.getElementById('faz-gvl-download').addEventListener('click', downloadGvl);
		document.getElementById('faz-gvl-save').addEventListener('click', saveSelection);
		document.getElementById('faz-gvl-select-all').addEventListener('change', toggleSelectAll);

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
				b1.textContent = 'GVL Version: ';
				el.appendChild(b1);
				el.appendChild(document.createTextNode(data.version + '  |  '));
				var b2 = document.createElement('strong');
				b2.textContent = 'Vendors: ';
				el.appendChild(b2);
				el.appendChild(document.createTextNode(data.vendor_count + '  |  '));
				var b3 = document.createElement('strong');
				b3.textContent = 'Last Updated: ';
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
				el.textContent = 'No GVL data downloaded yet. Click "Update GVL Now" to download.';
			}
		}).catch(function () {
			var el = document.getElementById('faz-gvl-meta');
			if (el) el.textContent = 'Failed to load GVL status.';
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
			loadVendors();
		}).catch(function () {
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
			if (el) el.textContent = 'Failed to load vendors. Make sure GVL is downloaded.';
		});
	}

	function renderVendors(vendors) {
		var container = document.getElementById('faz-gvl-vendor-list');
		container.textContent = '';

		if (!vendors.length) {
			container.textContent = 'No vendors found.';
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
		info.textContent = 'Page ' + page + ' of ' + pages + ' (' + total + ' vendors)';
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
			FAZ.notify('Failed to load vendor details', 'error');
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
		if (el) el.textContent = 'Selected: ' + count + ' vendor' + (count !== 1 ? 's' : '');
	}

	function saveSelection() {
		var btn = document.getElementById('faz-gvl-save');
		var ids = Object.keys(selectedVendors).map(Number).sort(function (a, b) { return a - b; });

		FAZ.btnLoading(btn, true);
		FAZ.post('gvl/selected', { vendor_ids: ids }).then(function (data) {
			FAZ.btnLoading(btn, false);
			if (data.success) {
				FAZ.notify('Saved ' + data.count + ' vendor(s)');
			} else {
				FAZ.notify('Failed to save selection', 'error');
			}
		}).catch(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify('Failed to save selection', 'error');
		});
	}

	function downloadGvl() {
		var btn = document.getElementById('faz-gvl-download');
		FAZ.btnLoading(btn, true);
		FAZ.post('gvl/update').then(function (data) {
			FAZ.btnLoading(btn, false);
			if (data.success) {
				FAZ.notify('GVL updated: v' + data.version + ' (' + data.vendor_count + ' vendors)');
				loadMeta();
				loadVendors();
			} else {
				FAZ.notify(data.message || 'Failed to update GVL', 'error');
			}
		}).catch(function (err) {
			FAZ.btnLoading(btn, false);
			FAZ.notify((err && err.message) || 'Failed to update GVL', 'error');
		});
	}

})();
