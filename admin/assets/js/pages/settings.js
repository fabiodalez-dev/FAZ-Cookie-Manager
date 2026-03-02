/**
 * FAZ Cookie Manager — Settings Page JS
 */
(function () {
	'use strict';

	var form;

	FAZ.ready(function () {
		form = document.getElementById('faz-settings');
		if (!form) return;
		loadSettings();
		loadGeoDbStatus();
		document.getElementById('faz-settings-save').addEventListener('click', saveSettings);
		var geoBtn = document.getElementById('faz-geodb-update');
		if (geoBtn) geoBtn.addEventListener('click', updateGeoDb);
	});

	function loadSettings() {
		FAZ.get('settings').then(function (data) {
			// Excluded pages comes as array, convert to newline-separated text
			if (data.banner_control && Array.isArray(data.banner_control.excluded_pages)) {
				data.banner_control.excluded_pages = data.banner_control.excluded_pages.join('\n');
			}
			FAZ.populateForm(form, data);
		}).catch(function () {
			FAZ.notify('Failed to load settings', 'error');
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
				var sizeKB = Math.round(data.database.size / 1024);
				var b = document.createElement('strong');
				b.textContent = 'Database: ';
				el.appendChild(b);
				el.appendChild(document.createTextNode(
					data.database.file + ' (' + sizeKB + ' KB) — Last updated: ' + data.database.modified
				));
			} else {
				el.textContent = 'No GeoIP database installed. Enter your license key and click "Update Database".';
			}
			el.style.display = 'block';
		}).catch(function () {});
	}

	function updateGeoDb() {
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
		}).catch(function (err) {
			FAZ.btnLoading(btn, false);
			var msg = (err && err.message) ? err.message : 'Failed to update database';
			FAZ.notify(msg, 'error');
		});
	}

})();
