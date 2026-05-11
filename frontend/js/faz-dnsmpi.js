/**
 * CCPA "Do Not Sell My Personal Information" opt-out form handler.
 *
 * Uses event delegation on the document so it works even when the shortcode
 * HTML is injected into the DOM client-side by page builders (Bricks, etc.).
 * Localized config is provided via fazDnsmpiConfig (wp_localize_script).
 */
(function () {
	'use strict';

	function handleSubmit(e) {
		var form = e.target;
		if (!form || !form.classList.contains('faz-dnsmpi-form')) return;
		e.preventDefault();

		var wrap = form.parentElement;
		if (!wrap) return;
		var notice = wrap.querySelector('.faz-dnsmpi-notice');
		var btn = form.querySelector('button');
		if (btn) btn.disabled = true;

		var data = new FormData(form);
		fetch(fazDnsmpiConfig.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				form.style.display = 'none';
				if (notice) {
					notice.style.display = 'block';
					if (res.success) {
						notice.className = 'faz-dnsmpi-notice success';
						notice.textContent =
							res.data && res.data.message ? res.data.message : fazDnsmpiConfig.successMsg;
					} else {
						notice.className = 'faz-dnsmpi-notice error';
						notice.textContent = res.data || fazDnsmpiConfig.errMsg;
						form.style.display = 'block';
						if (btn) btn.disabled = false;
					}
				}
			})
			.catch(function () {
				if (btn) btn.disabled = false;
				if (notice) {
					notice.className = 'faz-dnsmpi-notice error';
					notice.textContent = fazDnsmpiConfig.netMsg;
					notice.style.display = 'block';
				}
			});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			document.addEventListener('submit', handleSubmit);
		});
	} else {
		document.addEventListener('submit', handleSubmit);
	}
})();
