/**
 * FAZ Cookie Manager - Dashboard Page JS
 * Stats, line chart (pageviews), donut chart (consent distribution).
 * Filter bar: presets (1D, 7D, 30D, 1Y, All) + custom date range.
 */
(function () {
	'use strict';

	var currentFilter = { days: 7, from: null, to: null };
	var themeCache = null;

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

		var ids = ['faz-chart-range-label', 'faz-consent-range-label', 'faz-consent-stats-range-label'];
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
			return d1.toLocaleDateString(undefined, opts) + ' – ' + d2.toLocaleDateString(undefined, optsYear);
		}
		return d1.toLocaleDateString(undefined, optsYear) + ' – ' + d2.toLocaleDateString(undefined, optsYear);
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
		loadConsentStats(params);
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

	function readCssVar(name, fallback) {
		var value = window.getComputedStyle(document.documentElement).getPropertyValue(name);
		value = value ? value.trim() : '';
		return value || fallback;
	}

	function getTheme() {
		if (themeCache) return themeCache;
		themeCache = {
			primary: readCssVar('--faz-primary', '#e5007e'),
			success: readCssVar('--faz-success', '#17785b'),
			danger: readCssVar('--faz-danger', '#c03658'),
			text: readCssVar('--faz-text', '#1f1921'),
			muted: readCssVar('--faz-text-muted', '#756a79'),
			border: readCssVar('--faz-border', '#e7dde7'),
			surface: readCssVar('--faz-bg-secondary', '#fbf7fa'),
			font: readCssVar('--faz-font', '-apple-system, BlinkMacSystemFont, sans-serif')
		};
		return themeCache;
	}

	function hexToRgba(hex, alpha) {
		if (!hex || hex.charAt(0) !== '#') {
			return hex;
		}

		var normalized = hex.length === 4
			? '#' + hex.charAt(1) + hex.charAt(1) + hex.charAt(2) + hex.charAt(2) + hex.charAt(3) + hex.charAt(3)
			: hex;
		var intVal = parseInt(normalized.slice(1), 16);
		var r = (intVal >> 16) & 255;
		var g = (intVal >> 8) & 255;
		var b = intVal & 255;
		return 'rgba(' + [r, g, b, alpha].join(',') + ')';
	}

	function formatAxisLabel(label, index, total) {
		if (!label) return '';
		var parsed = new Date(label + 'T00:00:00');
		var text = label;
		var every = Math.max(1, Math.ceil(total / 5));

		if (total > 6 && index !== 0 && index !== total - 1 && (index % every) !== 0) {
			return '';
		}

		if (!isNaN(parsed.getTime())) {
			var opts = total > 45 ? { month: 'short' } : { month: 'short', day: 'numeric' };
			text = parsed.toLocaleDateString(undefined, opts);
		} else if (label.length > 10) {
			text = label.slice(0, 10);
		}

		return text;
	}

	function traceSeries(ctx, points, baselineY) {
		if (!points.length) return;

		ctx.beginPath();
		ctx.moveTo(points[0].x, points[0].y);

		if (points.length === 1) {
			ctx.lineTo(points[0].x, points[0].y);
		} else {
			for (var i = 1; i < points.length - 1; i++) {
				var midX = (points[i].x + points[i + 1].x) / 2;
				var midY = (points[i].y + points[i + 1].y) / 2;
				ctx.quadraticCurveTo(points[i].x, points[i].y, midX, midY);
			}

			ctx.quadraticCurveTo(
				points[points.length - 1].x,
				points[points.length - 1].y,
				points[points.length - 1].x,
				points[points.length - 1].y
			);
		}

		if (typeof baselineY === 'number') {
			ctx.lineTo(points[points.length - 1].x, baselineY);
			ctx.lineTo(points[0].x, baselineY);
			ctx.closePath();
		}
	}

	/**
	 * Consent donut chart - pure Canvas 2D.
	 */
	function drawConsentDonut(accepted, rejected) {
		var canvas = document.getElementById('faz-chart-consent');
		if (!canvas || !canvas.getContext) return;

		var theme = getTheme();
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
		var radius = Math.min(cx, cy) - 18;
		var innerRadius = radius * 0.68;

		var pctAccept = accepted / total;
		var pctReject = rejected / total;

		ctx.beginPath();
		ctx.arc(cx, cy, radius, 0, Math.PI * 2);
		ctx.arc(cx, cy, innerRadius, Math.PI * 2, 0, true);
		ctx.closePath();
		ctx.fillStyle = theme.surface;
		ctx.fill();

		var i18n = (typeof fazConfig !== 'undefined' && fazConfig.i18n) || {};
		var segments = [
			{ value: pctAccept, color: theme.primary, label: i18n.accepted || 'Accepted' },
			{ value: pctReject, color: theme.danger, label: i18n.rejected || 'Rejected' },
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
		ctx.fillStyle = theme.text;
		ctx.font = '700 21px ' + theme.font;
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';
		ctx.fillText(total.toLocaleString(), cx, cy - 6);
		ctx.fillStyle = theme.muted;
		ctx.font = '12px ' + theme.font;
		ctx.fillText('total responses', cx, cy + 14);

		// Legend
		var legendVertical = w < 320;
		var legendY = cy + radius + 22;
		var legendX = legendVertical ? cx - 54 : cx - 66;

		segments.forEach(function (seg, i) {
			var x = legendVertical ? legendX : legendX + i * 132;
			var y = legendVertical ? legendY + i * 18 : legendY;
			// Dot
			ctx.beginPath();
			ctx.arc(x, y, 5, 0, Math.PI * 2);
			ctx.fillStyle = seg.color;
			ctx.fill();
			// Label
			ctx.fillStyle = theme.text;
			ctx.font = '12px ' + theme.font;
			ctx.textAlign = 'left';
			ctx.fillText(seg.label + ' (' + Math.round(seg.value * 100) + '%)', x + 12, y + 4);
		});
	}

	/* ── Consent Statistics ── */

	function loadConsentStats(params) {
		params = params || { days: currentFilter.days || 30 };
		FAZ.get('consent_logs/stats', params).then(function (stats) {
			if (!stats || !stats.totals) return;

			var total    = parseInt(stats.totals.total, 10) || 0;
			var accepted = parseInt(stats.totals.accepted, 10) || 0;
			var rejected = parseInt(stats.totals.rejected, 10) || 0;
			var partial  = parseInt(stats.totals.partial, 10) || 0;

			var acceptEl  = document.getElementById('faz-cstat-accept-rate');
			var rejectEl  = document.getElementById('faz-cstat-reject-rate');
			var partialEl = document.getElementById('faz-cstat-partial-rate');
			var totalEl   = document.getElementById('faz-cstat-total');

			if (acceptEl)  acceptEl.textContent  = total > 0 ? Math.round(accepted / total * 100) + '%' : '--';
			if (rejectEl)  rejectEl.textContent  = total > 0 ? Math.round(rejected / total * 100) + '%' : '--';
			if (partialEl) partialEl.textContent  = total > 0 ? Math.round(partial / total * 100) + '%' : '--';
			if (totalEl)   totalEl.textContent    = total.toLocaleString();

			// Category bars — built with safe DOM methods.
			var catContainer = document.getElementById('faz-category-bars');
			if (catContainer && stats.categories) {
				while (catContainer.firstChild) {
					catContainer.removeChild(catContainer.firstChild);
				}
				var cats = stats.categories;
				var hasCats = false;
				for (var cat in cats) {
					if (!cats.hasOwnProperty(cat) || cat === 'necessary') continue;
					hasCats = true;
					var yes = cats[cat].yes || 0;
					var no  = cats[cat].no || 0;
					var catTotal = yes + no;
					var pct = catTotal > 0 ? Math.round(yes / catTotal * 100) : 0;
					var label = cat.charAt(0).toUpperCase() + cat.slice(1);

					var wrap = document.createElement('div');
					wrap.className = 'faz-category-bar-wrap';

					var barLabel = document.createElement('div');
					barLabel.className = 'faz-category-bar-label';
					var nameSpan = document.createElement('span');
					nameSpan.textContent = label;
					var pctSpan = document.createElement('span');
					pctSpan.textContent = pct + '%';
					barLabel.appendChild(nameSpan);
					barLabel.appendChild(pctSpan);

					var barOuter = document.createElement('div');
					barOuter.className = 'faz-category-bar';
					var barFill = document.createElement('div');
					barFill.className = 'faz-category-bar-fill';
					barFill.style.width = pct + '%';
					barOuter.appendChild(barFill);

					wrap.appendChild(barLabel);
					wrap.appendChild(barOuter);
					catContainer.appendChild(wrap);
				}
				if (!hasCats) {
					var emptyP = document.createElement('p');
					emptyP.style.color = 'var(--faz-text-muted)';
					emptyP.textContent = 'No category data yet.';
					catContainer.appendChild(emptyP);
				}
			}
		}).catch(function () {
			// Silently fail — stats card shows default dashes.
		});
	}

	/**
	 * Line chart with gradient fill - pure Canvas 2D.
	 */
	function drawLineChart(canvasId, labels, values) {
		var canvas = document.getElementById(canvasId);
		if (!canvas || !canvas.getContext) return;

		var theme = getTheme();
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
		ctx.strokeStyle = theme.border;
		ctx.lineWidth = 1;
		for (var g = 0; g <= 4; g++) {
			var gy = padTop + (g / 4) * chartH;
			ctx.beginPath();
			ctx.moveTo(padLeft, gy);
			ctx.lineTo(w - padRight, gy);
			ctx.stroke();

			ctx.fillStyle = theme.muted;
			ctx.font = '11px ' + theme.font;
			ctx.textAlign = 'right';
			ctx.fillText(Math.round(max - (g / 4) * max), padLeft - 8, gy + 4);
		}

		// X-axis labels
		ctx.fillStyle = theme.muted;
		ctx.font = '11px ' + theme.font;
		ctx.textAlign = 'center';
		labels.forEach(function (label, i) {
			var x = padLeft + (i / Math.max(labels.length - 1, 1)) * chartW;
			var short = formatAxisLabel(label, i, labels.length);
			if (short) {
				ctx.fillText(short, x, h - 6);
			}
		});

		// Area fill
		if (points.length > 1) {
			traceSeries(ctx, points, padTop + chartH);
			ctx.fillStyle = hexToRgba(theme.primary, 0.12);
			ctx.fill();
		}

		// Line
		traceSeries(ctx, points);
		ctx.strokeStyle = theme.primary;
		ctx.lineWidth = 3;
		ctx.lineJoin = 'round';
		ctx.lineCap = 'round';
		ctx.shadowColor = hexToRgba(theme.primary, 0.08);
		ctx.shadowBlur = 10;
		ctx.shadowOffsetY = 6;
		ctx.stroke();
		ctx.shadowColor = 'transparent';

		// Dots with white border
		points.forEach(function (p) {
			ctx.beginPath();
			ctx.arc(p.x, p.y, 4, 0, Math.PI * 2);
			ctx.fillStyle = theme.primary;
			ctx.fill();
			ctx.strokeStyle = '#fff';
			ctx.lineWidth = 2;
			ctx.stroke();
		});
	}

})();
