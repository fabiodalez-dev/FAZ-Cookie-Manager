/**
 * FAZ Cookie Manager - Consent Logs Page JS
 * Paginated table with filters, statistics, and CSV export.
 */
(function () {
	'use strict';

	var page = 1;
	var perPage = 15;
	var totalPages = 1;
	var totalItems = 0;

	FAZ.ready(function () {
		loadStats();
		loadLogs();

		document.getElementById('faz-log-filter').addEventListener('click', function () {
			page = 1;
			loadLogs();
		});

		// Enter key in search triggers filter
		document.getElementById('faz-log-search').addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				page = 1;
				loadLogs();
			}
		});

		document.getElementById('faz-log-status').addEventListener('change', function () {
			page = 1;
			loadLogs();
		});

		document.getElementById('faz-log-export').addEventListener('click', exportCsv);
	});

	function loadStats() {
		FAZ.get('consent_logs/statistics').then(function (data) {
			var stats = {};
			var total = 0;
			if (Array.isArray(data)) {
				data.forEach(function (item) {
					stats[item.type] = parseInt(item.count, 10) || 0;
					total += stats[item.type];
				});
			}
			document.getElementById('faz-stat-total').textContent = total.toLocaleString();
			document.getElementById('faz-stat-accepted').textContent = (stats.accepted || 0).toLocaleString();
			document.getElementById('faz-stat-rejected').textContent = (stats.rejected || 0).toLocaleString();
			document.getElementById('faz-stat-partial').textContent = (stats.partial || 0).toLocaleString();
		}).catch(function () {
			// Leave as --
		});
	}

	function getFilterParams() {
		var params = { paged: page, per_page: perPage };
		var search = document.getElementById('faz-log-search').value.trim();
		var status = document.getElementById('faz-log-status').value;
		if (search) params.search = search;
		if (status) params.status = status;
		return params;
	}

	function loadLogs() {
		var params = getFilterParams();

		FAZ.getWithHeaders('consent_logs', params).then(function (result) {
			var items = result.data;
			totalItems = result.total;
			totalPages = result.pages;

			renderTable(items);
			renderPagination();
		}).catch(function () {
			var tbody = document.getElementById('faz-logs-body');
			tbody.textContent = '';
			var tr = document.createElement('tr');
			var td = document.createElement('td');
			td.colSpan = 6;
			td.className = 'faz-text-center faz-text-muted';
			td.style.padding = '40px';
			td.textContent = 'Failed to load consent logs.';
			tr.appendChild(td);
			tbody.appendChild(tr);
		});
	}

	function renderTable(items) {
		var tbody = document.getElementById('faz-logs-body');
		tbody.textContent = '';

		if (!items || !items.length) {
			var tr = document.createElement('tr');
			var td = document.createElement('td');
			td.colSpan = 6;
			td.className = 'faz-text-center faz-text-muted';
			td.style.padding = '40px';
			td.textContent = 'No consent logs found.';
			tr.appendChild(td);
			tbody.appendChild(tr);
			document.getElementById('faz-log-footer').style.display = 'none';
			return;
		}

		items.forEach(function (item) {
			var tr = document.createElement('tr');

			// Date
			var tdDate = document.createElement('td');
			tdDate.style.fontSize = '13px';
			if (item.created_at) {
				var d = new Date(item.created_at.replace(' ', 'T'));
				tdDate.textContent = d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
			} else {
				tdDate.textContent = '--';
			}
			tr.appendChild(tdDate);

			// Consent ID
			var tdConsent = document.createElement('td');
			var consentCode = document.createElement('code');
			consentCode.className = 'faz-code';
			consentCode.textContent = (item.consent_id || '--').substring(0, 20);
			consentCode.title = item.consent_id || '';
			tdConsent.appendChild(consentCode);
			tr.appendChild(tdConsent);

			// Status badge
			var tdStatus = document.createElement('td');
			var badge = document.createElement('span');
			var status = item.status || 'unknown';
			var badgeClass = status === 'accepted' ? 'success' : status === 'rejected' ? 'danger' : 'warning';
			badge.className = 'faz-badge faz-badge-' + badgeClass;
			badge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
			tdStatus.appendChild(badge);
			tr.appendChild(tdStatus);

			// Categories
			var tdCats = document.createElement('td');
			tdCats.style.fontSize = '12px';
			var cats = item.categories;
			if (cats && typeof cats === 'string') {
				try { cats = JSON.parse(cats); } catch (_unused) { /* keep as string */ }
			}
			if (cats && typeof cats === 'object' && !Array.isArray(cats)) {
				var catKeys = Object.keys(cats);
				catKeys.forEach(function (k, i) {
					var catBadge = document.createElement('span');
					var accepted = cats[k] === 'yes' || cats[k] === true || cats[k] === 'true';
					catBadge.className = 'faz-cat-pill ' + (accepted ? 'faz-cat-yes' : 'faz-cat-no');
					catBadge.textContent = k;
					tdCats.appendChild(catBadge);
					if (i < catKeys.length - 1) {
						tdCats.appendChild(document.createTextNode(' '));
					}
				});
			} else {
				tdCats.textContent = '--';
			}
			tr.appendChild(tdCats);

			// IP Hash
			var tdIp = document.createElement('td');
			var ipCode = document.createElement('code');
			ipCode.className = 'faz-code';
			ipCode.textContent = (item.ip_hash || '--').substring(0, 12) + '\u2026';
			ipCode.title = item.ip_hash || '';
			tdIp.appendChild(ipCode);
			tr.appendChild(tdIp);

			// Page URL
			var tdUrl = document.createElement('td');
			tdUrl.style.fontSize = '12px';
			tdUrl.style.maxWidth = '200px';
			tdUrl.style.overflow = 'hidden';
			tdUrl.style.textOverflow = 'ellipsis';
			tdUrl.style.whiteSpace = 'nowrap';
			tdUrl.textContent = item.url || '--';
			tdUrl.title = item.url || '';
			tr.appendChild(tdUrl);

			tbody.appendChild(tr);
		});

		document.getElementById('faz-log-footer').style.display = '';
	}

	function renderPagination() {
		var container = document.getElementById('faz-log-pagination');
		var info = document.getElementById('faz-log-info');
		container.textContent = '';

		// Info text
		var start = (page - 1) * perPage + 1;
		var end = Math.min(page * perPage, totalItems);
		info.textContent = 'Showing ' + start + '-' + end + ' of ' + totalItems;

		if (totalPages <= 1) return;

		// Prev button
		var prev = document.createElement('button');
		prev.className = 'faz-btn faz-btn-sm faz-btn-outline';
		prev.textContent = '\u2190 Prev';
		prev.disabled = page <= 1;
		prev.addEventListener('click', function () { page--; loadLogs(); });
		container.appendChild(prev);

		// Page numbers
		var pages = getPaginationRange(page, totalPages);
		pages.forEach(function (p) {
			if (p === '...') {
				var dots = document.createElement('span');
				dots.className = 'faz-page-dots';
				dots.textContent = '\u2026';
				container.appendChild(dots);
			} else {
				var btn = document.createElement('button');
				btn.className = 'faz-btn faz-btn-sm' + (p === page ? ' faz-btn-primary' : ' faz-btn-outline');
				btn.textContent = p;
				(function (num) {
					btn.addEventListener('click', function () { page = num; loadLogs(); });
				})(p);
				container.appendChild(btn);
			}
		});

		// Next button
		var next = document.createElement('button');
		next.className = 'faz-btn faz-btn-sm faz-btn-outline';
		next.textContent = 'Next \u2192';
		next.disabled = page >= totalPages;
		next.addEventListener('click', function () { page++; loadLogs(); });
		container.appendChild(next);
	}

	function getPaginationRange(current, total) {
		if (total <= 7) {
			var arr = [];
			for (var i = 1; i <= total; i++) arr.push(i);
			return arr;
		}
		var pages = [1];
		if (current > 3) pages.push('...');
		for (var j = Math.max(2, current - 1); j <= Math.min(total - 1, current + 1); j++) {
			pages.push(j);
		}
		if (current < total - 2) pages.push('...');
		pages.push(total);
		return pages;
	}

	function exportCsv() {
		var params = getFilterParams();
		delete params.paged;
		delete params.per_page;

		var qs = [];
		Object.keys(params).forEach(function (k) {
			if (params[k]) qs.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
		});

		// Use wp.apiFetch with parse:false to get raw response for download
		wp.apiFetch({ path: 'faz/v1/consent_logs/export' + (qs.length ? '?' + qs.join('&') : ''), parse: false })
			.then(function (response) {
				return response.text();
			})
			.then(function (csv) {
				var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
				var link = document.createElement('a');
				link.href = URL.createObjectURL(blob);
				link.download = 'consent-logs-' + new Date().toISOString().slice(0, 10) + '.csv';
				document.body.appendChild(link);
				link.click();
				document.body.removeChild(link);
				URL.revokeObjectURL(link.href);
				FAZ.notify('CSV exported successfully');
			})
			.catch(function () {
				FAZ.notify('Failed to export CSV', 'error');
			});
	}

})();
