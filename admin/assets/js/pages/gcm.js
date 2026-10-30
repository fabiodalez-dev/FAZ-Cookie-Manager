/**
 * FAZ Cookie Manager - GCM Page JS
 */
(function () {
	'use strict';

	// i18n helper — looks up fazConfig.i18n.<key> with dot-notation, falls back to provided string.
	// Not named `__`: this is a key lookup into the PHP-provided fazConfig.i18n
	// map, not gettext. Under the gettext name, translate.wordpress.org harvests
	// the dotted keys below as if they were translatable English text.
	function fazI18n(key, fallback) {
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
			checkGoogleTag(data && data.google_tag_ids, []);
		}).catch(function () {
			FAZ.notify(fazI18n('gcm.loadFailed', 'Failed to load GCM settings.'), 'error');
		});
	}

	function saveGcm() {
		var btn = document.getElementById('faz-gcm-save');
		FAZ.btnLoading(btn, true);

		var data = FAZ.serializeForm(form);
		var typed = splitIds(data.google_tag_ids);

		FAZ.post('gcm', data).then(function (saved) {
			FAZ.btnLoading(btn, false);
			FAZ.notify(fazI18n('gcm.saved', 'GCM settings saved successfully.'));
			// Show what was kept: the server drops anything that is not a valid ID.
			var kept = saved && typeof saved.google_tag_ids === 'string' ? saved.google_tag_ids : '';
			var input = document.getElementById('faz-gcm-google-tag-ids');
			if (input) input.value = kept.split(',').filter(Boolean).join(', ');
			var keptIds = splitIds(kept);
			checkGoogleTag(kept, typed.filter(function (id) { return keptIds.indexOf(id) === -1; }));
		}).catch(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify(fazI18n('gcm.saveFailed', 'Failed to save GCM settings.'), 'error');
		});
	}

	function splitIds(value) {
		return String(value || '').toUpperCase().split(/[\s,;]+/).filter(Boolean);
	}

	/**
	 * Built-in Google tag (#366): say which typed IDs were not kept, and
	 * whether the home page already loads one of the saved IDs from somewhere
	 * else (a theme or plugin snippet), which would load the tag twice. The
	 * plugin's own copies carry data-faz-google-tag and are not counted.
	 */
	function checkGoogleTag(savedValue, dropped) {
		var box = document.getElementById('faz-gcm-google-tag-notice');
		if (!box) return;
		var messages = [];
		if (dropped.length) {
			messages.push(fazI18n('gcm.tagDropped', 'Not saved, because they are not valid Google tag IDs: %s').replace('%s', dropped.join(', ')));
		}
		var show = function () {
			box.textContent = messages.join(' ');
			box.hidden = messages.length === 0;
		};
		var ids = splitIds(savedValue);
		if (!ids.length || typeof window.fetch !== 'function' || typeof window.DOMParser !== 'function') {
			show();
			return;
		}
		var home = (window.fazConfig && window.fazConfig.site && window.fazConfig.site.home) || '/';
		window.fetch(home, { credentials: 'omit', cache: 'no-store' }).then(function (r) {
			return r.ok ? r.text() : '';
		}).then(function (html) {
			var doc = new DOMParser().parseFromString(html || '', 'text/html');
			var found = [];
			Array.prototype.forEach.call(doc.querySelectorAll('script, noscript, iframe'), function (el) {
				if (el.hasAttribute('data-faz-google-tag')) return;
				var text = ((el.getAttribute('src') || '') + ' ' + (el.textContent || '')).toUpperCase();
				ids.forEach(function (id) {
					if (found.indexOf(id) === -1 && text.indexOf(id) !== -1) found.push(id);
				});
			});
			if (found.length) {
				messages.push(fazI18n('gcm.tagDuplicate', 'Your home page already loads %s outside this plugin. Remove the other copy, or the tag will be loaded twice.').replace('%s', found.join(', ')));
			}
			show();
		}).catch(show);
	}

})();
