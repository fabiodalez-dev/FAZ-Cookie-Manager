/**
 * FAZ Cookie Manager - GCM Page JS
 */
(function () {
	'use strict';

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

	var form;

	FAZ.ready(function () {
		form = document.getElementById('faz-gcm');
		if (!form) return;
		loadGcm();
		document.getElementById('faz-gcm-save').addEventListener('click', saveGcm);
	});

	function loadGcm() {
		FAZ.get('gcm').then(function (data) {
			FAZ.populateForm(form, data);
		}).catch(function () {
			FAZ.notify(__('gcm.loadFailed', 'Failed to load GCM settings.'), 'error');
		});
	}

	function saveGcm() {
		var btn = document.getElementById('faz-gcm-save');
		FAZ.btnLoading(btn, true);

		var data = FAZ.serializeForm(form);

		FAZ.post('gcm', data).then(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify(__('gcm.saved', 'GCM settings saved successfully.'));
		}).catch(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify(__('gcm.saveFailed', 'Failed to save GCM settings.'), 'error');
		});
	}

})();
