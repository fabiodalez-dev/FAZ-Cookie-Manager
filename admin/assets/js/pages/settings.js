/**
 * FAZ Cookie Manager - Settings Page JS
 */
(function () {
	'use strict';

	var form;

	FAZ.ready(function () {
		form = document.getElementById('faz-settings');
		if (!form) return;
		loadSettings();
		loadGeoDbStatus();
		loadGvlStatus();
		document.getElementById('faz-settings-save').addEventListener('click', saveSettings);
		var geoBtn = document.getElementById('faz-geodb-update');
		if (geoBtn) geoBtn.addEventListener('click', updateGeoDb);
		var gvlBtn = document.getElementById('faz-gvl-update');
		if (gvlBtn) gvlBtn.addEventListener('click', updateGvl);
	});

	function loadSettings() {
		FAZ.get('settings').then(function (data) {
			// Excluded pages comes as array, convert to newline-separated text
			if (data.banner_control && Array.isArray(data.banner_control.excluded_pages)) {
				data.banner_control.excluded_pages = data.banner_control.excluded_pages.join('\n');
			}
			FAZ.populateForm(form, data);
			applyShowIf();
		}).catch(function () {
			FAZ.notify('Failed to load settings', 'error');
		});
	}

	/** Show/hide elements based on data-show-if="path.to.checkbox" */
	function applyShowIf() {
		form.querySelectorAll('[data-show-if]').forEach(function (el) {
			var path = el.getAttribute('data-show-if');
			var src = form.querySelector('input[type="checkbox"][data-path="' + path + '"]');
			if (!src) return;
			function toggle() { el.style.display = src.checked ? '' : 'none'; }
			toggle();
			src.addEventListener('change', toggle);
		});
	}

	function saveSettings() {
		var btn = document.getElementById('faz-settings-save');
		FAZ.btnLoading(btn, true);

		// Load full settings first, then merge form changes on top
		FAZ.get('settings').then(function (current) {
			var formData = FAZ.serializeForm(form);

			// Convert excluded pages back to array
			if (formData.banner_control && typeof formData.banner_control.excluded_pages === 'string') {
				formData.banner_control.excluded_pages = formData.banner_control.excluded_pages
					.split('\n')
					.map(function (s) { return s.trim(); })
					.filter(Boolean);
			}

			// Deep merge form data into current settings
			Object.keys(formData).forEach(function (key) {
				if (typeof formData[key] === 'object' && formData[key] !== null && !Array.isArray(formData[key])) {
					current[key] = Object.assign({}, current[key] || {}, formData[key]);
				} else {
					current[key] = formData[key];
				}
			});

			return FAZ.post('settings', current);
		}).then(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify('Settings saved successfully');
		}).catch(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify('Failed to save settings', 'error');
		});
	}

	function loadGeoDbStatus() {
		FAZ.get('settings/geolite2/status').then(function (data) {
			var el = document.getElementById('faz-geodb-status');
			if (!el) return;
			el.textContent = '';
			if (data.installed && data.database) {
				var rawSize = parseInt(data.database.size, 10);
			var sizeKB = isFinite(rawSize) ? Math.round(rawSize / 1024) : 0;
				var b = document.createElement('strong');
				b.textContent = 'Database: ';
				el.appendChild(b);
				el.appendChild(document.createTextNode(
					data.database.file + ' (' + sizeKB + ' KB) - Last updated: ' + data.database.modified
				));
			} else {
				el.textContent = 'No GeoIP database installed. Enter your license key and click "Update Database".';
			}
			el.style.display = 'block';
		}).catch(function (err) {
			console.warn('Failed to load GeoIP status', err);
		});
	}

	function loadGvlStatus() {
		FAZ.get('gvl').then(function (data) {
			var el = document.getElementById('faz-gvl-status');
			if (!el) return;
			el.textContent = '';
			if (data.version && data.version > 0) {
				var b1 = document.createElement('strong');
				b1.textContent = 'GVL Version: ';
				el.appendChild(b1);
				el.appendChild(document.createTextNode(data.version + ' | '));
				var b2 = document.createElement('strong');
				b2.textContent = 'Vendors: ';
				el.appendChild(b2);
				el.appendChild(document.createTextNode((data.vendor_count || 0) + ' | '));
				var b3 = document.createElement('strong');
				b3.textContent = 'Last Updated: ';
				el.appendChild(b3);
				el.appendChild(document.createTextNode(data.last_updated || 'N/A'));
			} else {
				el.textContent = 'No GVL data downloaded yet. Click "Update GVL Now" to download.';
			}
		}).catch(function () {
			var el = document.getElementById('faz-gvl-status');
			if (el) el.textContent = 'No GVL data available.';
		});
	}

	function updateGvl(event) {
		if (event) event.preventDefault();
		var btn = document.getElementById('faz-gvl-update');
		FAZ.btnLoading(btn, true);
		FAZ.post('gvl/update').then(function (data) {
			FAZ.btnLoading(btn, false);
			if (data.success) {
				FAZ.notify('GVL updated: v' + data.version + ' (' + data.vendor_count + ' vendors)');
				loadGvlStatus();
			} else {
				FAZ.notify(data.message || 'Failed to update GVL', 'error');
			}
		}).catch(function (err) {
			FAZ.btnLoading(btn, false);
			FAZ.notify((err && err.message) || 'Failed to update GVL', 'error');
		});
	}

	function updateGeoDb(event) {
		if (event) event.preventDefault();
		var btn = document.getElementById('faz-geodb-update');
		var keyInput = form.querySelector('[data-path="geolocation.maxmind_license_key"]');
		var licenseKey = keyInput ? keyInput.value.trim() : '';

		if (!licenseKey) {
			FAZ.notify('Please enter a MaxMind license key first', 'error');
			return;
		}

		FAZ.btnLoading(btn, true);
		FAZ.post('settings/geolite2/update', { license_key: licenseKey }).then(function (data) {
			FAZ.btnLoading(btn, false);
			if (data.success) {
				FAZ.notify('GeoIP database updated successfully');
				loadGeoDbStatus();
			}
			else {
				FAZ.notify(data.message || 'Failed to update database', 'error');
			}
		}).catch(function (err) {
			FAZ.btnLoading(btn, false);
			var msg = (err && err.message) ? err.message : 'Failed to update database';
			FAZ.notify(msg, 'error');
		});
	}

})();
