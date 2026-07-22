/* PACCC Member Directory — single member page: lazy Google map embed. */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	ready(function () {
		var box = document.querySelector('.paccc-member-single .paccc-map-embed');
		if (!box || box.dataset.loaded) {
			return;
		}
		var address = box.getAttribute('data-address');
		if (!address) {
			return;
		}
		var iframe = document.createElement('iframe');
		iframe.src = 'https://www.google.com/maps?q=' + encodeURIComponent(address) + '&output=embed';
		iframe.loading = 'lazy';
		iframe.title = 'Map of ' + address;
		iframe.referrerPolicy = 'no-referrer-when-downgrade';
		box.appendChild(iframe);
		box.dataset.loaded = '1';
	});
})();
