/**
 * FAZ Cookie Manager - GCM Page JS
 */
(function () {
	'use strict';

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
			FAZ.notify('Failed to load GCM settings', 'error');
		});
	}

	function saveGcm() {
		var btn = document.getElementById('faz-gcm-save');
		FAZ.btnLoading(btn, true);

		var data = FAZ.serializeForm(form);

		FAZ.post('gcm', data).then(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify('GCM settings saved successfully');
		}).catch(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify('Failed to save GCM settings', 'error');
		});
	}

})();
