/**
 * FAZ Cookie Manager - Languages Page JS
 * Searchable language selector with tag-based UI.
 */
(function () {
	'use strict';

	var allLangs = window.fazAllLanguages || {};
	var selected = [];
	var defaultLang = 'en';

	FAZ.ready(function () {
		loadLanguages();
		initSearch();
		document.getElementById('faz-lang-save').addEventListener('click', saveLanguages);
	});

	function loadLanguages() {
		FAZ.get('settings').then(function (data) {
			var langs = data.languages || {};
			selected = langs.selected || langs.selected_languages || ['en'];
			defaultLang = langs['default'] || langs.default_language || 'en';

			// Ensure default is in selected
			if (selected.indexOf(defaultLang) === -1) {
				selected.unshift(defaultLang);
			}

			renderTags();
			renderDefaultSelect();
		}).catch(function () {
			FAZ.notify('Failed to load languages', 'error');
		});
	}

	function renderTags() {
		var container = document.getElementById('faz-lang-tags');
		container.textContent = '';

		if (!selected.length) {
			var empty = document.createElement('span');
			empty.className = 'faz-text-muted';
			empty.textContent = 'No languages selected. Add one below.';
			container.appendChild(empty);
			return;
		}

		selected.forEach(function (code) {
			var tag = document.createElement('span');
			tag.className = 'faz-lang-tag';

			var name = allLangs[code] || code.toUpperCase();
			var text = document.createElement('span');
			text.textContent = name + ' (' + code + ')';
			tag.appendChild(text);

			var removeBtn = document.createElement('button');
			removeBtn.type = 'button';
			removeBtn.className = 'faz-lang-tag-remove';
			removeBtn.textContent = '\u00D7'; // multiplication sign ×
			removeBtn.title = 'Remove ' + name;
			removeBtn.addEventListener('click', function () {
				removeLanguage(code);
			});
			tag.appendChild(removeBtn);

			container.appendChild(tag);
		});
	}

	function renderDefaultSelect() {
		var sel = document.getElementById('faz-default-lang');
		sel.textContent = '';

		selected.forEach(function (code) {
			var opt = document.createElement('option');
			opt.value = code;
			opt.textContent = (allLangs[code] || code) + ' (' + code + ')';
			if (code === defaultLang) opt.selected = true;
			sel.appendChild(opt);
		});
	}

	function addLanguage(code) {
		if (selected.indexOf(code) > -1) return;
		selected.push(code);
		renderTags();
		renderDefaultSelect();
		document.getElementById('faz-lang-search').value = '';
		hideDropdown();
	}

	function removeLanguage(code) {
		if (selected.length <= 1) {
			FAZ.notify('At least one language must be selected', 'error');
			return;
		}
		selected = selected.filter(function (c) { return c !== code; });

		if (defaultLang === code) {
			defaultLang = selected[0] || 'en';
		}

		renderTags();
		renderDefaultSelect();
	}

	// ── Search / Dropdown ──

	function initSearch() {
		var input = document.getElementById('faz-lang-search');

		input.addEventListener('input', function () {
			var q = input.value.trim().toLowerCase();
			if (q.length < 1) {
				hideDropdown();
				return;
			}
			showResults(q);
		});

		input.addEventListener('focus', function () {
			if (input.value.trim().length >= 1) {
				showResults(input.value.trim().toLowerCase());
			}
		});

		document.addEventListener('click', function (e) {
			if (!e.target.closest('.faz-lang-search-wrap')) {
				hideDropdown();
			}
		});
	}

	function showResults(query) {
		var dropdown = document.getElementById('faz-lang-dropdown');
		dropdown.textContent = '';
		var count = 0;

		var codes = Object.keys(allLangs);
		for (var i = 0; i < codes.length && count < 12; i++) {
			var code = codes[i];
			var name = allLangs[code];

			if (
				name.toLowerCase().indexOf(query) > -1 ||
				code.toLowerCase().indexOf(query) > -1
			) {
				var already = selected.indexOf(code) > -1;
				var item = document.createElement('div');
				item.className = 'faz-lang-dropdown-item' + (already ? ' disabled' : '');

				var label = document.createElement('span');
				label.textContent = name + ' (' + code + ')';
				item.appendChild(label);

				if (already) {
					var badge = document.createElement('span');
					badge.className = 'faz-badge faz-badge-muted';
					badge.textContent = 'Added';
					item.appendChild(badge);
				} else {
					(function (c) {
						item.addEventListener('click', function () {
							addLanguage(c);
						});
					})(code);
				}

				dropdown.appendChild(item);
				count++;
			}
		}

		if (count === 0) {
			var noResult = document.createElement('div');
			noResult.className = 'faz-lang-dropdown-item disabled';
			noResult.textContent = 'No languages found';
			dropdown.appendChild(noResult);
		}

		dropdown.style.display = 'block';
	}

	function hideDropdown() {
		document.getElementById('faz-lang-dropdown').style.display = 'none';
	}

	// ── Save ──

	function saveLanguages() {
		var btn = document.getElementById('faz-lang-save');
		FAZ.btnLoading(btn, true);

		defaultLang = document.getElementById('faz-default-lang').value || selected[0] || 'en';

		if (selected.indexOf(defaultLang) === -1) {
			selected.unshift(defaultLang);
		}

		FAZ.get('settings').then(function (current) {
			current.languages = {
				selected: selected,
				'default': defaultLang,
			};
			return FAZ.post('settings', current);
		}).then(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify('Languages saved successfully');
		}).catch(function () {
			FAZ.btnLoading(btn, false);
			FAZ.notify('Failed to save languages', 'error');
		});
	}

})();
