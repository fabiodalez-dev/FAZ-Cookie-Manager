/**
 * FAZ Cookie Manager - Dashboard Page JS
 * Stats, line chart (pageviews), donut chart (consent distribution).
 * Filter bar: presets (1D, 7D, 30D, 1Y, All) + custom date range.
 */
(function () {
	'use strict';

	var currentFilter = { days: 7, from: null, to: null };

	FAZ.ready(function () {
		reloadDashboard();
		initFilterBar();
	});

	/* ── Filter bar ── */

	function initFilterBar() {
		// Preset buttons
		var presetBtns = document.querySelectorAll('.faz-chart-filter-btn');
		presetBtns.forEach(function (btn) {
			btn.addEventListener('click', function () {
				var days = parseInt(btn.getAttribute('data-days'), 10);
				currentFilter = { days: days, from: null, to: null };

				// Toggle active
				presetBtns.forEach(function (b) { b.classList.remove('active'); });
				btn.classList.add('active');

				// Clear custom inputs
				var fromEl = document.getElementById('faz-filter-from');
				var toEl = document.getElementById('faz-filter-to');
				if (fromEl) fromEl.value = '';
				if (toEl) toEl.value = '';

				updateRangeLabel();
				reloadDashboard();
			});
		});

		// Custom Apply button
		var applyBtn = document.getElementById('faz-filter-apply');
		if (applyBtn) {
			applyBtn.addEventListener('click', function () {
				var fromEl = document.getElementById('faz-filter-from');
				var toEl = document.getElementById('faz-filter-to');
				var from = fromEl ? fromEl.value : '';
				var to = toEl ? toEl.value : '';

				if (!from || !to) {
					FAZ.notify('Please select both start and end dates.', 'error');
					return;
				}
				if (from > to) {
					FAZ.notify('Start date must be before end date.', 'error');
					return;
				}

				currentFilter = { days: 0, from: from, to: to };

				// Remove preset active
				presetBtns.forEach(function (b) { b.classList.remove('active'); });

				updateRangeLabel();
				reloadDashboard();
			});
		}
	}

	function updateRangeLabel() {
		var text;

		if (currentFilter.from && currentFilter.to) {
			text = formatDateRange(currentFilter.from, currentFilter.to);
		} else {
			var map = {
				1: 'Last 24 Hours',
				7: 'Last 7 Days',
				30: 'Last 30 Days',
				365: 'Last Year',
				0: 'All Time'
			};
			text = map[currentFilter.days] || ('Last ' + currentFilter.days + ' Days');
		}

		var ids = ['faz-chart-range-label', 'faz-consent-range-label'];
		ids.forEach(function (id) {
			var el = document.getElementById(id);
			if (el) el.textContent = text;
		});
	}

	function formatDateRange(from, to) {
		var opts = { month: 'short', day: 'numeric' };
		var optsYear = { month: 'short', day: 'numeric', year: 'numeric' };
		var d1 = new Date(from + 'T00:00:00');
		var d2 = new Date(to + 'T00:00:00');

		if (d1.getFullYear() === d2.getFullYear()) {
			return d1.toLocaleDateString('en-US', opts) + ' – ' + d2.toLocaleDateString('en-US', optsYear);
		}
		return d1.toLocaleDateString('en-US', optsYear) + ' – ' + d2.toLocaleDateString('en-US', optsYear);
	}

	function buildParams() {
		if (currentFilter.from && currentFilter.to) {
			return { from: currentFilter.from, to: currentFilter.to };
		}
		return { days: currentFilter.days };
	}

	function reloadDashboard() {
		var params = buildParams();
		loadStats(params);
		loadChart(params);
	}

	/* ── Stats + Donut ── */

	function loadStats(params) {
		FAZ.get('pageviews/banner-stats', params).then(function (data) {
			var banner   = data.banner_view || 0;
			var accepted = data.banner_accept || 0;
			var rejected = data.banner_reject || 0;
			var total    = accepted + rejected;

			document.getElementById('faz-stat-pageviews').textContent = (banner + total).toLocaleString();
			document.getElementById('faz-stat-banner').textContent = banner.toLocaleString();
			document.getElementById('faz-stat-accept').textContent = total > 0 ? Math.round((accepted / total) * 100) + '%' : '--';
			document.getElementById('faz-stat-reject').textContent = total > 0 ? Math.round((rejected / total) * 100) + '%' : '--';

			resetCanvas('faz-chart-consent');
			hideEmpty('faz-consent-empty');
			drawConsentDonut(accepted, rejected);
		}).catch(function () {
			showEmpty('faz-consent-empty');
		});
	}

	/* ── Pageviews Line Chart ── */

	function loadChart(params) {
		FAZ.get('pageviews/chart', params).then(function (data) {
			var items = Array.isArray(data) ? data : (data.data || data.items || []);

			resetCanvas('faz-chart-pageviews');
			hideEmpty('faz-chart-empty');

			if (!items.length) {
				showEmpty('faz-chart-empty');
				return;
			}

			var labels = [];
			var values = [];
			items.forEach(function (item) {
				labels.push(item.date || '');
				values.push(item.views || item.count || item.pageviews || 0);
			});

			var hasData = values.some(function (v) { return v > 0; });
			if (!hasData) {
				showEmpty('faz-chart-empty');
				return;
			}

			drawLineChart('faz-chart-pageviews', labels, values);
		}).catch(function () {
			showEmpty('faz-chart-empty');
		});
	}

	/* ── Helpers ── */

	function showEmpty(id) {
		var el = document.getElementById(id);
		if (el) el.classList.remove('faz-hidden');
	}

	function hideEmpty(id) {
		var el = document.getElementById(id);
		if (el) el.classList.add('faz-hidden');
	}

	function resetCanvas(id) {
		var canvas = document.getElementById(id);
		if (!canvas || !canvas.getContext) return;
		var ctx = canvas.getContext('2d');
		ctx.setTransform(1, 0, 0, 1, 0, 0);
		ctx.clearRect(0, 0, canvas.width, canvas.height);
	}

	/**
	 * Consent donut chart - pure Canvas 2D.
	 */
	function drawConsentDonut(accepted, rejected) {
		var canvas = document.getElementById('faz-chart-consent');
		if (!canvas || !canvas.getContext) return;

		var total = accepted + rejected;
		if (total === 0) {
			showEmpty('faz-consent-empty');
			return;
		}

		var ctx = canvas.getContext('2d');
		var dpr = window.devicePixelRatio || 1;
		var rect = canvas.getBoundingClientRect();
		canvas.width = rect.width * dpr;
		canvas.height = rect.height * dpr;
		ctx.scale(dpr, dpr);

		var w = rect.width;
		var h = rect.height;
		var cx = w / 2;
		var cy = h / 2 - 10;
		var radius = Math.min(cx, cy) - 20;
		var innerRadius = radius * 0.6;

		var pctAccept = accepted / total;
		var pctReject = rejected / total;

		var segments = [
			{ value: pctAccept, color: '#16a34a', label: 'Accepted' },
			{ value: pctReject, color: '#dc2626', label: 'Rejected' },
		];

		var start = -Math.PI / 2;
		segments.forEach(function (seg) {
			var end = start + seg.value * Math.PI * 2;

			ctx.beginPath();
			ctx.arc(cx, cy, radius, start, end);
			ctx.arc(cx, cy, innerRadius, end, start, true);
			ctx.closePath();
			ctx.fillStyle = seg.color;
			ctx.fill();

			start = end;
		});

		// Center text
		ctx.fillStyle = '#1e293b';
		ctx.font = 'bold 20px -apple-system, sans-serif';
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';
		ctx.fillText(total.toLocaleString(), cx, cy - 6);
		ctx.fillStyle = '#64748b';
		ctx.font = '11px -apple-system, sans-serif';
		ctx.fillText('total responses', cx, cy + 12);

		// Legend
		var legendY = cy + radius + 20;
		var legendX = cx - 60;

		segments.forEach(function (seg, i) {
			var x = legendX + i * 120;
			// Dot
			ctx.beginPath();
			ctx.arc(x, legendY, 5, 0, Math.PI * 2);
			ctx.fillStyle = seg.color;
			ctx.fill();
			// Label
			ctx.fillStyle = '#1e293b';
			ctx.font = '12px -apple-system, sans-serif';
			ctx.textAlign = 'left';
			ctx.fillText(seg.label + ' (' + Math.round(seg.value * 100) + '%)', x + 10, legendY + 4);
		});
	}

	/**
	 * Line chart with gradient fill - pure Canvas 2D.
	 */
	function drawLineChart(canvasId, labels, values) {
		var canvas = document.getElementById(canvasId);
		if (!canvas || !canvas.getContext) return;

		var ctx = canvas.getContext('2d');
		var dpr = window.devicePixelRatio || 1;
		var rect = canvas.getBoundingClientRect();
		canvas.width = rect.width * dpr;
		canvas.height = rect.height * dpr;
		ctx.scale(dpr, dpr);

		var w = rect.width;
		var h = rect.height;
		var padLeft = 50;
		var padRight = 20;
		var padTop = 20;
		var padBottom = 30;
		var chartW = w - padLeft - padRight;
		var chartH = h - padTop - padBottom;

		var max = Math.max.apply(null, values) || 1;
		var step = Math.pow(10, Math.floor(Math.log10(max)));
		max = Math.ceil(max / step) * step || 1;

		var points = values.map(function (v, i) {
			return {
				x: padLeft + (i / Math.max(values.length - 1, 1)) * chartW,
				y: padTop + chartH - (v / max) * chartH,
			};
		});

		// Grid lines
		ctx.strokeStyle = '#e2e8f0';
		ctx.lineWidth = 1;
		for (var g = 0; g <= 4; g++) {
			var gy = padTop + (g / 4) * chartH;
			ctx.beginPath();
			ctx.moveTo(padLeft, gy);
			ctx.lineTo(w - padRight, gy);
			ctx.stroke();

			ctx.fillStyle = '#94a3b8';
			ctx.font = '11px -apple-system, sans-serif';
			ctx.textAlign = 'right';
			ctx.fillText(Math.round(max - (g / 4) * max), padLeft - 8, gy + 4);
		}

		// X-axis labels
		ctx.fillStyle = '#94a3b8';
		ctx.font = '11px -apple-system, sans-serif';
		ctx.textAlign = 'center';
		labels.forEach(function (label, i) {
			var x = padLeft + (i / Math.max(labels.length - 1, 1)) * chartW;
			var short = label.length > 5 ? label.slice(0, 5) : label;
			ctx.fillText(short, x, h - 6);
		});

		// Gradient area fill
		if (points.length > 1) {
			var grad = ctx.createLinearGradient(0, padTop, 0, padTop + chartH);
			grad.addColorStop(0, 'rgba(37, 99, 235, 0.15)');
			grad.addColorStop(1, 'rgba(37, 99, 235, 0.01)');

			ctx.beginPath();
			ctx.moveTo(points[0].x, points[0].y);
			for (var i = 1; i < points.length; i++) {
				ctx.lineTo(points[i].x, points[i].y);
			}
			ctx.lineTo(points[points.length - 1].x, padTop + chartH);
			ctx.lineTo(points[0].x, padTop + chartH);
			ctx.closePath();
			ctx.fillStyle = grad;
			ctx.fill();
		}

		// Line
		ctx.beginPath();
		ctx.strokeStyle = '#2563eb';
		ctx.lineWidth = 2.5;
		ctx.lineJoin = 'round';
		ctx.lineCap = 'round';
		points.forEach(function (p, i) {
			if (i === 0) ctx.moveTo(p.x, p.y);
			else ctx.lineTo(p.x, p.y);
		});
		ctx.stroke();

		// Dots with white border
		points.forEach(function (p) {
			ctx.beginPath();
			ctx.arc(p.x, p.y, 4, 0, Math.PI * 2);
			ctx.fillStyle = '#2563eb';
			ctx.fill();
			ctx.strokeStyle = '#fff';
			ctx.lineWidth = 2;
			ctx.stroke();
		});
	}

})();
